<?php

namespace App\Services\Interlab;

/**
 * Ulanish kodi: 24 belgi (Crockford base32), masalan  7KQ2-M9XD-4TPA-HW3E-RN8C-JF6V
 *
 *   ichida: kod yaratgan laboratoriya kalit izining 64 biti + 56 bitlik tasodifiy sir
 *
 *   - Kalit izi: kodni kiritgan server to'g'ri laboratoriyaga ulanayotganini tekshiradi
 *     (shu manzilda boshqa server bo'lsa — ulanmaydi).
 *   - Sir: tarmoqqa hech qachon ochiq yuborilmaydi. Ulanuvchi uni bilishini HMAC bilan
 *     isbotlaydi; isbot ulanuvchining kalitlariga bog'langan, boshqasi qayta ishlata olmaydi.
 */
class PairingCode
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford: I, L, O, U yo'q
    private const FP_BYTES = 8;
    private const SECRET_BYTES = 7;

    /** @return array{code: string, secret: string, lookup: string} */
    public static function generate(string $ownFingerprint): array
    {
        $secret = random_bytes(self::SECRET_BYTES);
        $raw = substr(hex2bin($ownFingerprint), 0, self::FP_BYTES) . $secret;

        return [
            'code'   => self::format(self::encode($raw)),
            'secret' => bin2hex($secret),
            'lookup' => self::lookup(bin2hex($secret)),
        ];
    }

    /** @return array{fp_prefix: string, secret: string, lookup: string} */
    public static function parse(string $code): array
    {
        $clean = strtoupper(preg_replace('/[\s\-_.]/', '', $code));
        // Crockford: o'xshash harflarni to'g'rilash
        $clean = strtr($clean, ['O' => '0', 'I' => '1', 'L' => '1']);
        if (! preg_match('/^[' . self::ALPHABET . ']{24}$/', $clean)) {
            throw new InterlabException('Ulanish kodi noto\'g\'ri formatda — 24 belgi bo\'lishi kerak (masalan 7KQ2-M9XD-4TPA-HW3E-RN8C-JF6V)');
        }
        $raw = self::decode($clean);
        $secret = bin2hex(substr($raw, self::FP_BYTES, self::SECRET_BYTES));

        return [
            'fp_prefix' => bin2hex(substr($raw, 0, self::FP_BYTES)),
            'secret'    => $secret,
            'lookup'    => self::lookup($secret),
        ];
    }

    public static function lookup(string $secretHex): string
    {
        return substr(hash('sha256', 'lims-invite|' . $secretHex), 0, 32);
    }

    /** Ulanuvchining isboti: sirni bilishi + o'z kalitlari va manzil laboratoriyaga bog'langan */
    public static function proof(string $secretHex, string $fromNodeId, string $fromFingerprint, string $toNodeId): string
    {
        return hash_hmac('sha256', implode('|', ['lims-pair-v1', $fromNodeId, $fromFingerprint, $toNodeId]), $secretHex);
    }

    public static function fingerprintMatches(string $fingerprint, string $fpPrefix): bool
    {
        return hash_equals($fpPrefix, substr(strtolower($fingerprint), 0, self::FP_BYTES * 2));
    }

    private static function format(string $s): string
    {
        return implode('-', str_split($s, 4));
    }

    private static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function decode(string $s): string
    {
        $bits = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $out .= chr(bindec($byte));
        }

        return $out;
    }
}
