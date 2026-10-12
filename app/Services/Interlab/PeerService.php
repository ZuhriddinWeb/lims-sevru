<?php

namespace App\Services\Interlab;

use App\Models\InterlabInvite;
use App\Models\InterlabMessage;
use App\Models\InterlabPeer;
use App\Models\InterlabReferral;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Laboratoriyalarni ulash — BIR MARTALIK KOD bilan (telefon / solishtirish shart emas):
 *
 *   B admin: «Ulanish kodi yaratish» → 24 belgili kod (muddati bor, bir marta ishlatiladi).
 *            Kodni A ga rasmiy yo'l bilan beradi (xizmat xati, ichki pochta, qo'lma-qo'l).
 *   A admin: «Laboratoriya qo'shish» → B manzili + kod → «Ulanish».
 *            A serveri kod ichidagi kalit izi bilan B ni tekshiradi (boshqa serverga ulanmaydi),
 *            B serveri A kodni bilishini HMAC isboti bilan tekshiradi (kod ochiq yuborilmaydi).
 *   Natija: ikkala tomonda darhol «Ulangan».
 *
 * Kodsiz ulanish so'rovlari qabul qilinmaydi.
 */
class PeerService
{
    public const INVITE_HOURS = [1, 8, 24, 72];

    public function __construct(
        private Identity $identity,
        private Outbox $outbox,
        private Notifier $notifier,
    ) {
    }

    // ================================================================ kodlar (B tomoni)

    /** @return array{code: string, invite: InterlabInvite} */
    public function createInvite(User $user, int $hours, ?string $note): array
    {
        $me = $this->identity->require();
        $hours = in_array($hours, self::INVITE_HOURS, true) ? $hours : 24;

        $generated = PairingCode::generate($me->fingerprint);
        $invite = InterlabInvite::create([
            'lookup'     => $generated['lookup'],
            'secret'     => $generated['secret'],
            'note'       => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 191) : null,
            'expires_at' => now()->addHours($hours),
            'created_by' => $user->id,
        ]);

        $this->notifier->audit('interlab.invite_create',
            "Ulanish kodi yaratildi ({$hours} soat)" . ($invite->note ? ": {$invite->note}" : ''),
            ['expires_at' => $invite->expires_at->toIso8601String()], 'InterlabInvite', $invite->id);

        return ['code' => $generated['code'], 'invite' => $invite];
    }

    public function revokeInvite(InterlabInvite $invite): InterlabInvite
    {
        if ($invite->state() !== 'active') {
            throw new InterlabException('Faqat amaldagi kodni bekor qilish mumkin', 409);
        }
        $invite->forceFill(['revoked_at' => now()])->save();
        $this->notifier->audit('interlab.invite_revoke', 'Ulanish kodi bekor qilindi' . ($invite->note ? ": {$invite->note}" : ''),
            null, 'InterlabInvite', $invite->id);

        return $invite;
    }

    public function invites(): array
    {
        // Amaldagilar + oxirgi 10 ta tarix
        $active = InterlabInvite::whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->orderByDesc('id')->get();
        $recent = InterlabInvite::where(fn ($q) => $q->whereNotNull('used_at')->orWhereNotNull('revoked_at')->orWhere('expires_at', '<=', now()))
            ->orderByDesc('id')->limit(10)->get();

        return $active->concat($recent)->map(fn (InterlabInvite $i) => $i->toPublicArray())->values()->all();
    }

    // ================================================================ ulanish (A tomoni)

    public function connectWithCode(string $url, string $code, User $user): InterlabPeer
    {
        $me = $this->identity->require();
        $parsed = PairingCode::parse($code);

        $transport = app(Transport::class);
        $info = $transport->fetchIdentity($url);
        if (hash_equals($me->node_id, $info['node_id'])) {
            throw new InterlabException('Bu — shu laboratoriyaning o\'z manzili');
        }
        if (! PairingCode::fingerprintMatches($info['fingerprint'], $parsed['fp_prefix'])) {
            throw new InterlabException('Bu kod ushbu manzildagi laboratoriyaga tegishli emas. Manzilni va kodni tekshiring '
                . '(kodni ulanmoqchi bo\'lgan laboratoriyaning o\'zi yaratishi kerak).', 422);
        }

        $existing = InterlabPeer::where('node_id', $info['node_id'])->first();
        if ($existing && $existing->status === 'active' && hash_equals($existing->fingerprint, $info['fingerprint'])) {
            throw new InterlabException("Bu laboratoriya allaqachon ulangan: {$existing->name}", 409);
        }

        $body = $this->identity->publicInfo($me) + [
            'invite' => [
                'lookup' => $parsed['lookup'],
                'proof'  => PairingCode::proof($parsed['secret'], $me->node_id, $me->fingerprint, $info['node_id']),
            ],
        ];
        $envelope = Envelope::build($me, $info['node_id'], $info['enc_public'], 'pair.request', $body);

        // To'g'ridan-to'g'ri (navbatsiz) — natija darhol ekranda ko'rinsin
        $tmpPeer = new InterlabPeer(['base_url' => $info['requested_url']]);
        $result = $transport->send($tmpPeer, $envelope);
        if (! $result['ok'] || ! ($result['body']['paired'] ?? false)) {
            throw new InterlabException($result['error'] ?: 'Ulanib bo\'lmadi', $result['status'] === 0 ? 503 : 422);
        }

        $peer = DB::transaction(function () use ($info, $user, $existing, $envelope, $body) {
            $peer = $existing ?? new InterlabPeer(['node_id' => $info['node_id']]);
            $peer->fill([
                'name'        => $info['name'],
                'code'        => $info['code'] ?? null,
                'base_url'    => $info['requested_url'],
                'enc_public'  => $info['enc_public'],
                'sign_public' => $info['sign_public'],
                'fingerprint' => $info['fingerprint'],
                'status'      => 'active',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'last_ok_at'  => now(),
                'last_error'  => null,
            ])->save();

            unset($body['invite']);
            InterlabMessage::create([
                'uuid' => $envelope['id'], 'peer_id' => $peer->id, 'peer_node_id' => $peer->node_id,
                'direction' => 'out', 'type' => 'pair.request', 'body' => $body,
                'envelope' => null, 'status' => 'sent', 'attempts' => 1, 'sent_at' => now(),
            ]);

            return $peer;
        });

        $this->notifier->audit('interlab.peer_connect', "Laboratoriya kod orqali ulandi: {$peer->name} ({$peer->base_url})",
            ['fingerprint' => $peer->fingerprint], 'InterlabPeer', $peer->id);

        // Kutib qolgan xabarlar bo'lsa (masalan, qayta ulanishda) — yuboramiz
        $this->outbox->flush($peer, true);

        return $peer->fresh();
    }

    // ================================================================ boshqaruv

    /** Eski versiyadan qolgan «kutilayotgan» yozuvlarni tozalash uchun */
    public function reject(InterlabPeer $peer, User $user): InterlabPeer
    {
        if (! in_array($peer->status, ['pending_in', 'pending_out'], true)) {
            throw new InterlabException('Faqat kutilayotgan so\'rovni rad etish mumkin', 409);
        }
        $peer->forceFill(['status' => 'rejected'])->save();
        $this->notifier->audit('interlab.peer_reject', "Ulanish rad etildi: {$peer->name}", null, 'InterlabPeer', $peer->id);

        return $peer->fresh();
    }

    public function setEnabled(InterlabPeer $peer, bool $enabled): InterlabPeer
    {
        if ($enabled) {
            if ($peer->status !== 'disabled') {
                throw new InterlabException('Laboratoriya o\'chirilmagan', 409);
            }
            $peer->forceFill(['status' => 'active'])->save();
            $this->outbox->flush($peer, true);
        } else {
            if ($peer->status !== 'active') {
                throw new InterlabException('Faqat faol laboratoriyani o\'chirish mumkin', 409);
            }
            $peer->forceFill(['status' => 'disabled'])->save();
        }
        $this->notifier->audit('interlab.peer_' . ($enabled ? 'enable' : 'disable'),
            ($enabled ? 'Yoqildi: ' : 'O\'chirildi: ') . $peer->name, null, 'InterlabPeer', $peer->id);

        return $peer->fresh();
    }

    public function delete(InterlabPeer $peer): void
    {
        if ($peer->status === 'active') {
            throw new InterlabException('Avval laboratoriyani o\'chiring (disable)', 409);
        }
        if (InterlabReferral::where('peer_id', $peer->id)->exists()) {
            throw new InterlabException('Bu laboratoriya bilan yo\'llanmalar bor — o\'chirib bo\'lmaydi (faqat o\'chirib qo\'yish mumkin)', 409);
        }
        $name = $peer->name;
        DB::transaction(function () use ($peer) {
            InterlabMessage::where('peer_id', $peer->id)->delete();
            $peer->delete();
        });
        $this->notifier->audit('interlab.peer_delete', "Laboratoriya ro'yxatdan olib tashlandi: {$name}");
    }

    /** Laboratoriya bilan aloqani tekshirish (identity so'rovi) */
    public function ping(InterlabPeer $peer): InterlabPeer
    {
        try {
            $info = app(Transport::class)->fetchIdentity($peer->base_url);
            $attrs = ['last_ping_at' => now(), 'last_ok_at' => now(), 'last_error' => null, 'last_error_at' => null];
            if (! hash_equals($info['node_id'], $peer->node_id)) {
                $attrs = ['last_ping_at' => now(), 'last_error' => 'Bu manzilda boshqa laboratoriya javob beryapti', 'last_error_at' => now()];
            } elseif (! hash_equals($info['fingerprint'], $peer->fingerprint)) {
                $attrs = ['last_ping_at' => now(), 'last_error' => 'DIQQAT: laboratoriya kalitlari o\'zgargan — yangi ulanish kodi bilan qayta ulanish kerak', 'last_error_at' => now()];
            }
            $peer->forceFill($attrs)->save();
        } catch (InterlabException $e) {
            $peer->forceFill(['last_ping_at' => now(), 'last_error' => $e->getMessage(), 'last_error_at' => now()])->save();
        }

        return $peer->fresh();
    }

    /** Tarmoqdagi barcha faol laboratoriyalarga nomi/manzil o'zgarganini xabar qilish */
    public function broadcastNodeUpdate(): void
    {
        $info = $this->identity->publicInfo();
        $body = array_intersect_key($info, array_flip(['node_id', 'name', 'code', 'base_url']));
        foreach (InterlabPeer::where('status', 'active')->get() as $peer) {
            $this->outbox->queue($peer, 'node.update', $body);
        }
        $this->outbox->flush(null, true);
    }

    // ================================================================ kiruvchi

    /**
     * Ulanish so'rovi (B tomonida). Imzo va kalit egaligi Inbox'da tekshirilgan.
     *
     * @param array $body node_id, name, code, base_url, enc_public, sign_public, fingerprint, invite?
     * @return InterlabPeer faol laboratoriya
     */
    public function onPairRequest(array $body, ?string $ip): InterlabPeer
    {
        $peer = InterlabPeer::where('node_id', $body['node_id'])->lockForUpdate()->first();
        $info = [
            'name'     => mb_substr((string) $body['name'], 0, 191),
            'code'     => isset($body['code']) ? mb_substr((string) $body['code'], 0, 20) : null,
            'base_url' => $body['base_url'],
        ];
        $keys = [
            'enc_public'  => $body['enc_public'],
            'sign_public' => $body['sign_public'],
            'fingerprint' => $body['fingerprint'],
        ];

        $invite = $this->matchInvite($body);

        if (! $invite) {
            // Kodsiz: faqat avval ulangan va kalitlari o'sha laboratoriya (javob yo'qolgan bo'lsa — takroriy so'rov)
            if ($peer && $peer->status === 'active' && hash_equals($peer->fingerprint, $body['fingerprint'])) {
                $peer->fill($info)->save();

                return $peer;
            }
            throw new InterlabException('Ulanish kodi noto\'g\'ri, ishlatilgan, bekor qilingan yoki muddati o\'tgan. '
                . 'Ulanmoqchi bo\'lgan laboratoriyadan yangi kod oling.', 403);
        }

        $previous = $peer?->status;
        $peer ??= new InterlabPeer(['node_id' => $body['node_id']]);
        $peer->fill($info + $keys + [
            'status'      => 'active',
            'approved_by' => $invite->created_by,
            'approved_at' => now(),
            'last_error'  => null,
        ])->save();

        $invite->forceFill([
            'used_at'         => now(),
            'used_by_peer_id' => $peer->id,
            'used_by_name'    => $peer->name,
            'used_ip'         => $ip ? mb_substr($ip, 0, 64) : null,
        ])->save();

        $this->notifier->audit('interlab.peer_active',
            "Laboratoriya ulanish kodi orqali ulandi: {$peer->name} ({$peer->base_url})" . ($previous ? " — avvalgi holat: {$previous}" : ''),
            ['fingerprint' => $peer->fingerprint, 'ip' => $ip, 'invite_id' => $invite->id], 'InterlabPeer', $peer->id);
        $this->notifier->notify(
            $this->notifier->usersWith('interlab.manage'),
            "Laboratoriya ulandi: {$peer->name}",
            'Ulanish kodi orqali' . ($invite->note ? " ({$invite->note})" : '') . ($ip ? ", IP {$ip}" : ''),
            'info', 'interlab-network', $peer->id, "interlab-peer-active-{$peer->node_id}-{$invite->id}"
        );

        return $peer;
    }

    /** Kod isbotini tekshiradi; to'g'ri bo'lsa — kodni qulflab qaytaradi */
    private function matchInvite(array $body): ?InterlabInvite
    {
        $lookup = $body['invite']['lookup'] ?? null;
        $proof = $body['invite']['proof'] ?? null;
        if (! is_string($lookup) || ! is_string($proof) || strlen($lookup) !== 32 || strlen($proof) !== 64) {
            return null;
        }

        $invite = InterlabInvite::where('lookup', strtolower($lookup))->lockForUpdate()->first();
        if (! $invite || $invite->state() !== 'active') {
            return null;
        }

        $me = $this->identity->require();
        $expected = PairingCode::proof($invite->secret, $body['node_id'], $body['fingerprint'], $me->node_id);

        return hash_equals($expected, strtolower($proof)) ? $invite : null;
    }

    public function onPairAccept(InterlabPeer $peer, array $body): void
    {
        // Eski versiya bilan moslik: kod tizimida bu xabar yuborilmaydi
        if (isset($body['fingerprint']) && ! hash_equals($peer->fingerprint, (string) $body['fingerprint'])) {
            throw new InterlabException('Tasdiqdagi kalitlar mos emas', 403);
        }
        $this->onNodeUpdate($peer, $body);
    }

    public function onPairReject(InterlabPeer $peer, array $body): void
    {
        $peer->forceFill(['status' => 'rejected'])->save();
        $this->notifier->audit('interlab.peer_rejected', "Ulanish rad etildi: {$peer->name}", null, 'InterlabPeer', $peer->id);
    }

    public function onNodeUpdate(InterlabPeer $peer, array $body): void
    {
        $peer->forceFill(array_filter([
            'name'     => isset($body['name']) ? mb_substr((string) $body['name'], 0, 191) : null,
            'code'     => isset($body['code']) ? mb_substr((string) $body['code'], 0, 20) : null,
            'base_url' => ! empty($body['base_url']) ? Transport::normalizeUrl((string) $body['base_url']) : null,
        ], fn ($v) => $v !== null))->save();
    }
}
