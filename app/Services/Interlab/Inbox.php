<?php

namespace App\Services\Interlab;

use App\Models\InterlabMessage;
use App\Models\InterlabPeer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kiruvchi xabarlar.
 *
 *  1. Tuzilma va qabul qiluvchi (bizmi?) tekshiriladi.
 *  2. Takror bo'lsa (uuid) — «qabul qilindi» deb javob, qayta ishlanmaydi.
 *  3. Jo'natuvchi tanish va faol laboratoriya bo'lishi shart (ulanish so'rovidan tashqari).
 *  4. Imzo jo'natuvchining saqlangan kaliti bilan tekshiriladi, keyin xabar ochiladi.
 *  5. Mazmun bitta tranzaksiyada qayta ishlanadi va jurnalga yoziladi.
 *
 * Kiruvchi xabarni qayta ishlash davomida tarmoqqa chiqilmaydi (javob xabarlari navbatga
 * qo'yiladi) — ikki server bir-birini kutib qolmasligi uchun.
 */
class Inbox
{
    public function __construct(
        private Identity $identity,
        private PeerService $peers,
        private ReferralService $referrals,
    ) {
    }

    /** @return array{0: int, 1: array} HTTP holati va javob */
    public function receive(array $env, ?string $ip = null): array
    {
        try {
            return [200, $this->process($env, $ip)];
        } catch (InterlabException $e) {
            $this->logRejected($env, $e);

            return [$e->status(), ['ok' => false, 'message' => $e->getMessage(), 'retryable' => $e->retryable()]];
        } catch (Throwable $e) {
            Log::error('interlab: inbox', ['error' => $e->getMessage(), 'id' => $env['id'] ?? null]);

            return [500, ['ok' => false, 'message' => 'Qabul qiluvchi serverda ichki xato', 'retryable' => true]];
        }
    }

    private function process(array $env, ?string $ip): array
    {
        Envelope::assertShape($env);
        $me = $this->identity->get();
        if (! $me) {
            throw new InterlabException('Qabul qiluvchi laboratoriya hali sozlanmagan', 423);
        }
        if (! hash_equals($me->node_id, $env['to'])) {
            throw new InterlabException('Xabar boshqa laboratoriyaga mo\'ljallangan', 400);
        }

        // Takroriy yuborish (javob yo'qolgan bo'lishi mumkin) — xavfsiz, qayta ishlanmaydi
        $existing = InterlabMessage::where('uuid', $env['id'])->first();
        if ($existing && $existing->direction === 'in' && $existing->status === 'processed') {
            return ['ok' => true, 'id' => $env['id'], 'duplicate' => true, 'paired' => $existing->type === 'pair.request'];
        }
        if ($existing && $existing->direction === 'out') {
            throw new InterlabException('Xabar identifikatori band', 409);
        }

        $ts = Carbon::parse($env['ts']);
        $maxAge = max(1, (int) config('interlab.max_message_age_days', 30));
        if ($ts->lt(now()->subDays($maxAge)) || $ts->gt(now()->addDay())) {
            throw new InterlabException('Xabar vaqti ruxsat etilgan oraliqdan tashqarida (server soatlarini tekshiring)', 422);
        }

        if ($env['type'] === 'pair.request') {
            return $this->processPairRequest($env, $me, $ip, $existing);
        }

        $peer = InterlabPeer::where('node_id', $env['from'])->first();
        if (! $peer) {
            throw new InterlabException('Jo\'natuvchi laboratoriya bizda ro\'yxatda yo\'q', 403);
        }
        $this->assertPeerMayDeliver($peer, $env['type']);

        if (! Crypto::verify(Envelope::signingString($env), $env['sig'], $peer->sign_public)) {
            throw new InterlabException('Imzo tasdiqlanmadi — xabar rad etildi', 401);
        }
        $body = Envelope::openBody($env, $me);

        DB::transaction(function () use ($env, $peer, $body, $existing) {
            $message = $this->record($env, $peer, $body, $existing);
            match ($env['type']) {
                'pair.accept'       => $this->peers->onPairAccept($peer, $body),
                'pair.reject'       => $this->peers->onPairReject($peer, $body),
                'node.update'       => $this->peers->onNodeUpdate($peer, $body),
                'referral.create'   => $this->referrals->onCreate($peer, $body, $message),
                'referral.accept'   => $this->referrals->onAccept($peer, $body),
                'referral.reject'   => $this->referrals->onReject($peer, $body),
                'referral.result'   => $this->referrals->onResult($peer, $body),
                'referral.cancel'   => $this->referrals->onCancel($peer, $body),
                'referral.reviewed' => $this->referrals->onReviewed($peer, $body),
            };
            $peer->forceFill(['last_seen_at' => now()])->save();
        });

        return ['ok' => true, 'id' => $env['id']];
    }

    /**
     * Ulanish so'rovi: jo'natuvchi hali bizga tanish emas. Uning kalitlari xabar ichida keladi;
     * imzo shu kalit bilan tekshiriladi (kalitga egaligini isbotlaydi). Ishonch esa — so'rovda
     * shu laboratoriya yaratgan bir martalik ulanish kodining isboti bo'lsa paydo bo'ladi.
     */
    private function processPairRequest(array $env, $me, ?string $ip, ?InterlabMessage $existing): array
    {
        $body = Envelope::openBody($env, $me);
        foreach (['node_id', 'name', 'base_url', 'enc_public', 'sign_public'] as $field) {
            if (empty($body[$field]) || ! is_string($body[$field])) {
                throw new InterlabException("Ulanish so'rovida «{$field}» yo'q", 422);
            }
        }
        if (! hash_equals($body['node_id'], $env['from'])) {
            throw new InterlabException('Ulanish so\'rovi jo\'natuvchisi mos emas', 400);
        }
        $body['sign_public'] = Crypto::normalizePublicKey($body['sign_public']);
        $body['enc_public'] = Crypto::normalizePublicKey($body['enc_public']);
        if (! Crypto::verify(Envelope::signingString($env), $env['sig'], $body['sign_public'])) {
            throw new InterlabException('Ulanish so\'rovining imzosi tasdiqlanmadi', 401);
        }
        $body['base_url'] = Transport::normalizeUrl($body['base_url']);
        $body['fingerprint'] = Crypto::fingerprint($body['sign_public'], $body['enc_public']);

        DB::transaction(function () use ($env, $body, $ip, $existing) {
            $peer = $this->peers->onPairRequest($body, $ip);
            $this->record($env, $peer, $this->safeBody($body), $existing);
            $peer->forceFill(['last_seen_at' => now()])->save();
        });

        return ['ok' => true, 'id' => $env['id'], 'paired' => true];
    }

    private function assertPeerMayDeliver(InterlabPeer $peer, string $type): void
    {
        $pairReply = in_array($type, ['pair.accept', 'pair.reject'], true);

        if ($peer->status === 'active') {
            return;
        }
        if ($peer->status === 'pending_out' && $pairReply) {
            return;
        }
        if ($peer->status === 'disabled') {
            throw new InterlabException('Laboratoriya vaqtincha o\'chirilgan — keyinroq yuboriladi', 423);
        }
        if (in_array($peer->status, ['pending_out', 'pending_in'], true)) {
            throw new InterlabException('Ulanish hali ikki tomondan tasdiqlanmagan — keyinroq yuboriladi', 423);
        }

        throw new InterlabException('Laboratoriya ulanishi rad etilgan', 403);
    }

    private function record(array $env, InterlabPeer $peer, array $body, ?InterlabMessage $existing): InterlabMessage
    {
        $data = [
            'uuid'          => $env['id'],
            'peer_id'       => $peer->id,
            'peer_node_id'  => $peer->node_id,
            'direction'     => 'in',
            'type'          => $env['type'],
            'referral_uuid' => isset($body['uuid']) && is_string($body['uuid']) ? $body['uuid'] : null,
            'body'          => $body,
            'envelope'      => json_encode($env, JSON_UNESCAPED_SLASHES),
            'status'        => 'processed',
            'processed_at'  => now(),
            'last_error'    => null,
        ];

        if ($existing) {
            $existing->forceFill($data)->save();
            return $existing;
        }

        return InterlabMessage::create($data);
    }

    /** Rad etilgan xabar jurnalga (tranzaksiyadan tashqarida) — sababni ko'rish uchun */
    private function logRejected(array $env, InterlabException $e): void
    {
        try {
            if (empty($env['id']) || ! is_string($env['id']) || strlen($env['id']) !== 36) {
                return;
            }
            if (InterlabMessage::where('uuid', $env['id'])->exists()) {
                return;
            }
            $peer = isset($env['from']) && is_string($env['from'])
                ? InterlabPeer::where('node_id', $env['from'])->first() : null;
            if (! $peer) {
                return; // notanish manbadan kelgan xabarlarni saqlamaymiz
            }
            InterlabMessage::create([
                'uuid'         => $env['id'],
                'peer_id'      => $peer->id,
                'peer_node_id' => $peer->node_id,
                'direction'    => 'in',
                'type'         => in_array($env['type'] ?? '', Envelope::TYPES, true) ? $env['type'] : 'unknown',
                'status'       => 'rejected',
                'processed_at' => now(),
                'last_error'   => mb_substr($e->getMessage(), 0, 500),
            ]);
        } catch (Throwable) {
            // jurnal ixtiyoriy
        }
    }

    private function safeBody(array $body): array
    {
        return array_intersect_key($body, array_flip(['node_id', 'name', 'code', 'base_url', 'fingerprint']));
    }
}
