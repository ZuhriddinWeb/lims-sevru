<?php

namespace App\Services\Interlab;

use App\Models\InterlabPeer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Serverlar o'rtasidagi HTTP aloqa (ichki tarmoq).
 *   GET  {base}/api/interlab/v1/identity — ochiq ma'lumot (nom, ochiq kalitlar)
 *   POST {base}/api/interlab/v1/inbox    — konvert yuborish
 */
class Transport
{
    public const PATH_IDENTITY = '/api/interlab/v1/identity';
    public const PATH_INBOX = '/api/interlab/v1/inbox';

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        $url = rtrim($url, '/');
        // Foydalanuvchi to'liq yo'lni kiritgan bo'lsa — kesib tashlaymiz
        $url = preg_replace('#/api/interlab/v1(/.*)?$#i', '', $url);

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InterlabException('Manzil noto\'g\'ri: ' . $url);
        }

        return $url;
    }

    /**
     * @return array{ok: bool, status: int, error: ?string, retryable: bool, body: ?array}
     */
    public function send(InterlabPeer $peer, array $envelope): array
    {
        try {
            $response = $this->client()->post($peer->base_url . self::PATH_INBOX, $envelope);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => 0, 'error' => self::networkError($e->getMessage()), 'retryable' => true, 'body' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'error' => self::short($e->getMessage()), 'retryable' => true, 'body' => null];
        }

        $status = $response->status();
        $json = $response->json();
        if ($response->successful() && is_array($json) && ($json['ok'] ?? false) === true) {
            return ['ok' => true, 'status' => $status, 'error' => null, 'retryable' => false, 'body' => $json];
        }

        $error = is_array($json) && isset($json['message']) ? (string) $json['message'] : "HTTP {$status}";
        if ($response->successful()) {
            // 200, lekin bizning javob emas (masalan, boshqa sahifa) — manzil noto'g'ri bo'lishi mumkin
            $error = 'Kutilmagan javob — manzil to\'g\'riligini tekshiring';
        }

        // 404 — modul o'rnatilmagan yoki manzil xato (tuzatilishi mumkin) → qayta urinamiz
        $retryable = $status >= 500 || in_array($status, [404, 408, 423, 429], true) || $response->successful();
        if (is_array($json) && array_key_exists('retryable', $json)) {
            $retryable = (bool) $json['retryable'];
        }

        return ['ok' => false, 'status' => $status, 'error' => self::short($error), 'retryable' => $retryable, 'body' => is_array($json) ? $json : null];
    }

    /** Boshqa laboratoriyaning ochiq ma'lumotini oladi va tekshiradi */
    public function fetchIdentity(string $baseUrl): array
    {
        $baseUrl = self::normalizeUrl($baseUrl);
        try {
            $response = $this->client()->get($baseUrl . self::PATH_IDENTITY);
        } catch (Throwable $e) {
            throw new InterlabException(self::networkError($e->getMessage()), 503);
        }

        $info = $response->json();
        if (! $response->successful() || ! is_array($info) || ($info['app'] ?? null) !== 'lims-interlab') {
            $hint = $response->status() === 404 ? ' (modul o\'rnatilmagan yoki tarmoq sozlanmagan bo\'lishi mumkin)' : '';
            throw new InterlabException("Bu manzilda LIMS laboratoriya topilmadi: HTTP {$response->status()}{$hint}", 404);
        }
        if ((int) ($info['protocol'] ?? 0) !== Crypto::PROTOCOL) {
            throw new InterlabException('Protokol versiyasi mos emas — ikkala serverda modul versiyasini tenglashtiring', 409);
        }
        foreach (['node_id', 'name', 'enc_public', 'sign_public', 'fingerprint'] as $field) {
            if (empty($info[$field]) || ! is_string($info[$field])) {
                throw new InterlabException("Javobda «{$field}» yo'q", 422);
            }
        }

        $info['enc_public'] = Crypto::normalizePublicKey($info['enc_public']);
        $info['sign_public'] = Crypto::normalizePublicKey($info['sign_public']);
        $computed = Crypto::fingerprint($info['sign_public'], $info['enc_public']);
        if (! hash_equals($computed, (string) $info['fingerprint'])) {
            throw new InterlabException('Barmoq izi kalitlarga mos emas — ulanish xavfli', 422);
        }
        $info['requested_url'] = $baseUrl;

        return $info;
    }

    private function client()
    {
        return Http::acceptJson()
            ->asJson()
            ->connectTimeout(max(1, (int) config('interlab.connect_timeout', 4)))
            ->timeout(max(2, (int) config('interlab.timeout', 15)))
            ->withOptions(['verify' => (bool) config('interlab.verify_tls', false)])
            ->withHeaders(['User-Agent' => 'LIMS-Interlab/' . Crypto::PROTOCOL]);
    }

    /** cURL xatolarini oddiy tilga o'giradi (texnik kod qavsda qoladi — IT uchun) */
    public static function networkError(string $raw): string
    {
        $code = preg_match('/cURL error (\d+)/', $raw, $m) ? (int) $m[1] : null;
        $text = match ($code) {
            6       => 'Server nomi topilmadi — manzilni tekshiring yoki IP manzil kiriting',
            7       => 'Server javob bermadi — u o\'chiq, manzil/port noto\'g\'ri yoki firewall to\'sib qo\'ygan',
            28      => 'Server javob berish vaqti tugadi — tarmoq sekin yoki server band',
            35, 60  => 'HTTPS sertifikat bilan muammo — manzilni http:// bilan sinab ko\'ring',
            52, 56  => 'Server ulanishni uzib qo\'ydi',
            default => 'Ulanib bo\'lmadi',
        };

        return $code ? "{$text} (cURL {$code})" : $text . ': ' . self::short($raw);
    }

    private static function short(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message);

        return mb_substr($message, 0, 300);
    }
}
