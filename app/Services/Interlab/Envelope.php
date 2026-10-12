<?php

namespace App\Services\Interlab;

use App\Models\InterlabIdentity;
use Illuminate\Support\Str;

/**
 * Xabar konverti (serverlar o'rtasida faqat shu ko'rinishda yuradi):
 *
 *   { v, id, type, from, to, ts,            ← ochiq sarlavha (shifrlangan matnga bog'langan)
 *     eph, iv, tag, data,                   ← shifrlangan mazmun (faqat qabul qiluvchi ochadi)
 *     sig }                                 ← jo'natuvchining imzosi (hamma maydonlar ustidan)
 */
class Envelope
{
    public const TYPES = [
        'pair.request', 'pair.accept', 'pair.reject', 'node.update',
        'referral.create', 'referral.accept', 'referral.reject',
        'referral.result', 'referral.cancel', 'referral.reviewed',
    ];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function build(
        InterlabIdentity $me,
        string $toNodeId,
        string $toEncPublic,
        string $type,
        array $body,
        ?string $id = null,
    ): array {
        $header = [
            'v'    => Crypto::PROTOCOL,
            'id'   => $id ?? (string) Str::uuid(),
            'type' => $type,
            'from' => $me->node_id,
            'to'   => $toNodeId,
            'ts'   => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        $plain = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sealed = Crypto::seal($plain, $toEncPublic, self::aad($header), $header['id']);

        $envelope = $header + $sealed;
        $envelope['sig'] = Crypto::sign(self::signingString($envelope), $me->sign_private);

        return $envelope;
    }

    /** Tuzilmani tekshiradi (kalitlarsiz). Xato bo'lsa — 400. */
    public static function assertShape(array $env): void
    {
        foreach (['id', 'type', 'from', 'to', 'ts', 'eph', 'iv', 'tag', 'data', 'sig'] as $field) {
            if (! isset($env[$field]) || ! is_string($env[$field]) || $env[$field] === '') {
                throw new InterlabException("Konvertda «{$field}» yo'q", 400);
            }
        }
        if ((int) ($env['v'] ?? 0) !== Crypto::PROTOCOL) {
            throw new InterlabException('Protokol versiyasi mos emas', 400);
        }
        if (! in_array($env['type'], self::TYPES, true)) {
            throw new InterlabException('Noma\'lum xabar turi', 400);
        }
        foreach (['id', 'from', 'to'] as $field) {
            if (! preg_match(self::UUID, $env[$field])) {
                throw new InterlabException("Konvertdagi «{$field}» noto'g'ri", 400);
            }
        }
        if (strtotime($env['ts']) === false) {
            throw new InterlabException('Konvert vaqti noto\'g\'ri', 400);
        }
    }

    public static function aad(array $env): string
    {
        return implode('|', [
            (int) $env['v'], $env['id'], $env['type'], $env['from'], $env['to'], $env['ts'],
        ]);
    }

    public static function signingString(array $env): string
    {
        return implode("\n", [
            self::aad($env), $env['eph'], $env['iv'], $env['tag'], $env['data'],
        ]);
    }

    /** Imzo tekshirilgandan keyin ochadi */
    public static function openBody(array $env, InterlabIdentity $me): array
    {
        $plain = Crypto::open($env, $me->enc_private, self::aad($env), $env['id']);
        $body = json_decode($plain, true);
        if (! is_array($body)) {
            throw new InterlabException('Xabar mazmuni noto\'g\'ri', 400);
        }

        return $body;
    }
}
