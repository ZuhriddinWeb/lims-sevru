<?php

namespace App\Services\Interlab;

use App\Models\InterlabIdentity;
use Illuminate\Support\Str;

/**
 * Shu laboratoriya (tugun) — nomi, manzili va kalitlari.
 */
class Identity
{
    private ?InterlabIdentity $cached = null;

    public function get(): ?InterlabIdentity
    {
        return $this->cached ??= InterlabIdentity::query()->orderBy('id')->first();
    }

    public function require(): InterlabIdentity
    {
        $identity = $this->get();
        if (! $identity) {
            throw new InterlabException('Bu laboratoriya hali tarmoqqa sozlanmagan', 409);
        }

        return $identity;
    }

    public function forget(): void
    {
        $this->cached = null;
    }

    public function create(string $name, string $code, ?string $baseUrl): InterlabIdentity
    {
        if ($this->get()) {
            throw new InterlabException('Bu laboratoriya allaqachon sozlangan', 409);
        }

        $enc = Crypto::generateKeyPair();
        $sign = Crypto::generateKeyPair();

        $identity = InterlabIdentity::create([
            'node_id'      => (string) Str::uuid(),
            'name'         => trim($name),
            'code'         => self::normalizeCode($code),
            'base_url'     => $baseUrl ? Transport::normalizeUrl($baseUrl) : null,
            'enc_public'   => $enc['public'],
            'enc_private'  => $enc['private'],
            'sign_public'  => $sign['public'],
            'sign_private' => $sign['private'],
            'fingerprint'  => Crypto::fingerprint($sign['public'], $enc['public']),
        ]);

        $this->cached = $identity;

        return $identity;
    }

    /** @return bool o'zgarish tarmoqdagi laboratoriyalarga xabar qilinishi kerakmi */
    public function update(string $name, string $code, ?string $baseUrl): bool
    {
        $identity = $this->require();
        $identity->fill([
            'name'     => trim($name),
            'code'     => self::normalizeCode($code),
            'base_url' => $baseUrl ? Transport::normalizeUrl($baseUrl) : null,
        ]);
        $changed = $identity->isDirty(['name', 'code', 'base_url']);
        $identity->save();

        return $changed;
    }

    /** Boshqa laboratoriyalarga ochiq ko'rsatiladigan ma'lumot (faqat ochiq kalitlar) */
    public function publicInfo(?InterlabIdentity $identity = null): array
    {
        $identity ??= $this->require();

        return [
            'app'         => 'lims-interlab',
            'protocol'    => Crypto::PROTOCOL,
            'node_id'     => $identity->node_id,
            'name'        => $identity->name,
            'code'        => $identity->code,
            'base_url'    => $identity->base_url,
            'enc_public'  => $identity->enc_public,
            'sign_public' => $identity->sign_public,
            'fingerprint' => $identity->fingerprint,
        ];
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $code));

        return substr($code !== '' ? $code : 'LAB', 0, 20);
    }
}
