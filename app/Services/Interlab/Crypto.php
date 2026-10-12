<?php

namespace App\Services\Interlab;

/**
 * Kriptografiya — faqat OpenSSL kengaytmasi (Laravel uchun baribir majburiy).
 *
 *   Shifrlash : ECIES — vaqtinchalik EC P-256 kalit + ECDH + HKDF-SHA256 → AES-256-GCM.
 *               Xabarni faqat qabul qiluvchining yopiq kaliti ocha oladi.
 *   Imzo      : ECDSA P-256 / SHA-256. Jo'natuvchini isbotlaydi, o'zgartirishni sezadi.
 *
 * Har laboratoriyada ikki juft kalit: shifrlash (enc) va imzo (sign).
 */
class Crypto
{
    public const PROTOCOL = 1;
    public const CURVE = 'prime256v1';
    private const HKDF_INFO = 'lims-interlab-v1';

    /** Windows'dagi PHP openssl.cnf topolmasa kalit yaratolmaydi — o'zimiznikini beramiz */
    public static function opensslConfig(): array
    {
        return ['config' => __DIR__ . DIRECTORY_SEPARATOR . 'openssl.cnf'];
    }

    /** @return array{private: string, public: string} PEM */
    public static function generateKeyPair(): array
    {
        $key = openssl_pkey_new(self::opensslConfig() + [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => self::CURVE,
            'private_key_bits' => 2048, // EC uchun ishlatilmaydi, lekin ba'zi PHP versiyalari tekshiradi
        ]);
        if ($key === false) {
            throw new InterlabException('Kalit yaratib bo\'lmadi: ' . self::opensslErrors());
        }
        if (! openssl_pkey_export($key, $private, null, self::opensslConfig())) {
            throw new InterlabException('Kalitni saqlab bo\'lmadi: ' . self::opensslErrors());
        }
        $details = openssl_pkey_get_details($key);

        return ['private' => $private, 'public' => $details['key']];
    }

    /** Ochiq kalit to'g'ri EC P-256 ekanini tekshiradi; normallashtirilgan PEM qaytaradi */
    public static function normalizePublicKey(string $pem): string
    {
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new InterlabException('Ochiq kalit noto\'g\'ri');
        }
        $d = openssl_pkey_get_details($key);
        if (($d['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($d['ec']['curve_name'] ?? null) !== self::CURVE) {
            throw new InterlabException('Ochiq kalit turi qo\'llab-quvvatlanmaydi');
        }

        return $d['key'];
    }

    /**
     * Barmoq izi: imzo va shifrlash ochiq kalitlarining SHA-256 si (64 hex).
     * Adminlar uni telefon orqali solishtiradi.
     */
    public static function fingerprint(string $signPublic, string $encPublic): string
    {
        return hash('sha256', self::der($signPublic) . '|' . self::der($encPublic));
    }

    /** Ko'rsatish uchun: AB12-CD34-EF56-7890-1A2B-3C4D (birinchi 96 bit) */
    public static function formatFingerprint(string $hex): string
    {
        return strtoupper(implode('-', str_split(substr($hex, 0, 24), 4)));
    }

    /**
     * Shifrlaydi. $aad — ochiq sarlavha (shifrlangan matnga bog'lanadi).
     *
     * @return array{eph: string, iv: string, tag: string, data: string} base64
     */
    public static function seal(string $plaintext, string $recipientEncPublic, string $aad, string $messageId): array
    {
        $recipient = openssl_pkey_get_public($recipientEncPublic);
        if ($recipient === false) {
            throw new InterlabException('Qabul qiluvchi kaliti noto\'g\'ri');
        }

        $eph = openssl_pkey_new(self::opensslConfig() + [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => self::CURVE,
            'private_key_bits' => 2048,
        ]);
        if ($eph === false) {
            throw new InterlabException('Vaqtinchalik kalit yaratib bo\'lmadi: ' . self::opensslErrors());
        }
        $ephPublic = openssl_pkey_get_details($eph)['key'];

        $shared = openssl_pkey_derive($recipient, $eph, 32);
        if ($shared === false) {
            throw new InterlabException('Kalit kelishuvi bajarilmadi: ' . self::opensslErrors());
        }
        $key = hash_hkdf('sha256', $shared, 32, self::HKDF_INFO . '|' . $messageId);

        $iv = random_bytes(12);
        $tag = '';
        $data = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($data === false) {
            throw new InterlabException('Shifrlab bo\'lmadi');
        }

        return [
            'eph'  => base64_encode($ephPublic),
            'iv'   => base64_encode($iv),
            'tag'  => base64_encode($tag),
            'data' => base64_encode($data),
        ];
    }

    /** Ochadi. Kalit/sarlavha mos kelmasa yoki matn o'zgartirilgan bo'lsa — xato. */
    public static function open(array $sealed, string $ownEncPrivate, string $aad, string $messageId): string
    {
        $ephPublic = base64_decode((string) ($sealed['eph'] ?? ''), true);
        $iv = base64_decode((string) ($sealed['iv'] ?? ''), true);
        $tag = base64_decode((string) ($sealed['tag'] ?? ''), true);
        $data = base64_decode((string) ($sealed['data'] ?? ''), true);
        if ($ephPublic === false || $iv === false || $tag === false || $data === false || strlen($iv) !== 12 || strlen($tag) !== 16) {
            throw new InterlabException('Konvert buzilgan', 400);
        }

        $eph = @openssl_pkey_get_public($ephPublic);
        $own = openssl_pkey_get_private($ownEncPrivate);
        if ($eph === false || $own === false) {
            throw new InterlabException('Kalitni o\'qib bo\'lmadi', 400);
        }
        $shared = @openssl_pkey_derive($eph, $own, 32);
        if ($shared === false) {
            throw new InterlabException('Kalit kelishuvi bajarilmadi', 400);
        }
        $key = hash_hkdf('sha256', $shared, 32, self::HKDF_INFO . '|' . $messageId);

        $plain = openssl_decrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($plain === false) {
            throw new InterlabException('Xabarni ochib bo\'lmadi (boshqa kalit uchun yoki o\'zgartirilgan)', 400);
        }

        return $plain;
    }

    public static function sign(string $data, string $signPrivate): string
    {
        $key = openssl_pkey_get_private($signPrivate);
        if ($key === false || ! openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new InterlabException('Imzolab bo\'lmadi: ' . self::opensslErrors());
        }

        return base64_encode($signature);
    }

    public static function verify(string $data, string $signatureB64, string $signPublic): bool
    {
        $signature = base64_decode($signatureB64, true);
        if ($signature === false || $signature === '') {
            return false;
        }

        return openssl_verify($data, $signature, $signPublic, OPENSSL_ALGO_SHA256) === 1;
    }

    /** PEM → DER (barmoq izi uchun formatdan mustaqil) */
    private static function der(string $pem): string
    {
        $b64 = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);

        return base64_decode($b64, true) ?: $pem;
    }

    private static function opensslErrors(): string
    {
        $out = [];
        while ($e = openssl_error_string()) {
            $out[] = $e;
        }

        return $out ? implode('; ', array_slice($out, 0, 3)) : 'noma\'lum';
    }
}
