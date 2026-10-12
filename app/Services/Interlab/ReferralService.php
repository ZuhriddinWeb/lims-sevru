<?php

namespace App\Services\Interlab;

use App\Models\InterlabIdentity;
use App\Models\InterlabMessage;
use App\Models\InterlabPeer;
use App\Models\InterlabReferral;
use App\Models\InterlabResult;
use App\Models\Sample;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tekshiruv yo'llanmalari.
 *
 *   Jo'natuvchi (A, «out»):  create → [B qabul/rad] → [B natija] → reviewed      | cancel
 *   Tekshiruvchi (B, «in»):  onCreate → accept/reject → natija (qoralama → yuborish, tuzatish)
 *
 * Ko'r-ko'rona tekshiruv: B ga A ning natijalari va ruxsat etilgan farq YUBORILMAYDI.
 * Natijalar faqat shu ikki laboratoriyada saqlanadi; probaning o'z natijalariga tegilmaydi.
 */
class ReferralService
{
    private const MAX_ELEMENTS = 60;

    public function __construct(
        private Identity $identity,
        private Outbox $outbox,
        private Notifier $notifier,
    ) {
    }

    // ================================================================ ko'rinish

    public function seesAll(User $user): bool
    {
        return $user->isAdmin()
            || $user->hasPermission('interlab.manage')
            || $user->hasPermission('samples.view_all');
    }

    public function scopeVisible(Builder $query, User $user): Builder
    {
        if ($this->seesAll($user)) {
            return $query;
        }

        $group = $user->laboratory_group_id ? (int) $user->laboratory_group_id : null;
        $canProcess = $user->hasPermission('interlab.process');

        return $query->where(function ($q) use ($user, $group, $canProcess) {
            $q->where(function ($o) use ($user, $group) {
                $o->where('direction', 'out')->where(function ($w) use ($user, $group) {
                    $w->where('created_by', $user->id);
                    if ($group) {
                        $w->orWhere('group_id', $group);
                    }
                });
            })->orWhere(function ($i) use ($group, $canProcess) {
                $i->where('direction', 'in')->where(function ($w) use ($group, $canProcess) {
                    $w->whereRaw('1 = 0');
                    if ($group) {
                        $w->orWhere('group_id', $group);
                    }
                    if ($canProcess) {
                        $w->orWhereNull('group_id');
                    }
                });
            });
        });
    }

    public function assertVisible(User $user, InterlabReferral $referral): void
    {
        $visible = $this->scopeVisible(InterlabReferral::query()->whereKey($referral->id), $user)->exists();
        if (! $visible) {
            throw new InterlabException('Yo\'llanma topilmadi', 404);
        }
    }

    // ================================================================ jo'natuvchi (A)

    /**
     * @param  array{peer_id:int, sample_id:int, elements:array, description?:string, note?:string,
     *               deadline?:string, tolerance_pct?:float, hide_code?:bool}  $data
     */
    public function create(User $user, array $data): InterlabReferral
    {
        $peer = InterlabPeer::find($data['peer_id']);
        if (! $peer || ! $peer->isActive()) {
            throw new InterlabException('Laboratoriya faol emas — avval tarmoq sozlamalarida ulang');
        }
        if (! $user->isAdmin() && ! $user->hasPermission('samples.view')) {
            throw new InterlabException('Probalarni ko\'rish ruxsatingiz yo\'q', 403);
        }

        $sample = Sample::with('sampleElements.element')->find($data['sample_id']);
        if (! $sample) {
            throw new InterlabException('Proba topilmadi', 404);
        }

        $available = [];
        foreach ($sample->sampleElements as $se) {
            if ($se->element) {
                $available[strtoupper($se->element->symbol)] = [$se->element, $se->result_unit];
            }
        }
        $symbols = collect($data['elements'] ?? [])->map(fn ($s) => strtoupper(trim((string) $s)))->filter()->unique()->values();
        if ($symbols->isEmpty()) {
            throw new InterlabException('Kamida bitta element tanlang');
        }
        $elements = [];
        foreach ($symbols as $symbol) {
            if (! isset($available[$symbol])) {
                throw new InterlabException("Probada «{$symbol}» elementi yo'q");
            }
            [$el, $unit] = $available[$symbol];
            $elements[] = [
                'symbol'  => $el->symbol,
                'name_uz' => $el->name_uz,
                'name_ru' => $el->name_ru,
                'name_en' => $el->name_en,
                'unit'    => $unit ?: $el->default_unit,
            ];
        }

        $hide = (bool) ($data['hide_code'] ?? false);

        $referral = DB::transaction(function () use ($user, $peer, $sample, $elements, $data, $hide) {
            /** @var InterlabIdentity $me */
            $me = InterlabIdentity::query()->lockForUpdate()->orderBy('id')->firstOrFail();

            $referral = InterlabReferral::create([
                'uuid'            => (string) Str::uuid(),
                'number'          => $this->nextNumber($me),
                'direction'       => 'out',
                'peer_id'         => $peer->id,
                'status'          => 'sending',
                'sample_id'       => $sample->id,
                'sample_code'     => $sample->code,
                'sample_number'   => $sample->sample_number,
                'hide_code'       => $hide,
                'sample_info'     => [
                    'state_of_matter' => $sample->state_of_matter,
                    'registered_at'   => optional($sample->registered_at)->toDateString(),
                ],
                'elements'        => $elements,
                'description'     => self::text($data['description'] ?? null, 2000),
                'note'            => self::text($data['note'] ?? null, 2000),
                'deadline'        => $data['deadline'] ?? null,
                'tolerance_pct'   => isset($data['tolerance_pct']) && $data['tolerance_pct'] !== '' ? (float) $data['tolerance_pct'] : null,
                'sender_name'     => $user->name,
                'sender_position' => $user->position,
                'sender_lab'      => $me->name,
                'group_id'        => $user->laboratory_group_id ?: $sample->assigned_group_id,
                'created_by'      => $user->id,
                'sent_at'         => now(),
                'last_event_at'   => now(),
            ]);

            $this->outbox->queue($peer, 'referral.create', [
                'uuid'            => $referral->uuid,
                'number'          => $referral->number,
                'hide_code'       => $hide,
                'sample_code'     => $hide ? null : $referral->sample_code,
                'sample_number'   => $hide ? null : $referral->sample_number,
                'sample_info'     => $referral->sample_info,
                'elements'        => $elements,
                'description'     => $referral->description,
                'note'            => $referral->note,
                'deadline'        => $referral->deadline?->toDateString(),
                'sender_name'     => $referral->sender_name,
                'sender_position' => $referral->sender_position,
                'sender_lab'      => $referral->sender_lab,
                'sent_at'         => now()->toIso8601String(),
            ], $referral->uuid);

            return $referral;
        });

        $this->notifier->audit('interlab.referral_create',
            "Tekshiruvga jo'natildi: {$referral->number} → {$peer->name} (proba {$sample->code})",
            ['elements' => array_column($elements, 'symbol')], 'InterlabReferral', $referral->id);

        $this->outbox->flush($peer, true);

        return $referral->fresh();
    }

    public function cancel(User $user, InterlabReferral $referral, string $reason): InterlabReferral
    {
        $this->assertDirection($referral, 'out');
        if (! in_array($referral->status, ['sending', 'sent', 'accepted'], true)) {
            throw new InterlabException('Bu holatdagi yo\'llanmani bekor qilib bo\'lmaydi', 409);
        }

        DB::transaction(function () use ($user, $referral, $reason) {
            $referral->forceFill([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'last_event_at' => now(),
            ])->save();
            $this->outbox->queue($referral->peer, 'referral.cancel', [
                'uuid' => $referral->uuid, 'reason' => $reason, 'by' => $user->name,
            ], $referral->uuid);
        });

        $this->notifier->audit('interlab.referral_cancel', "Yo'llanma bekor qilindi: {$referral->number}. Sabab: {$reason}",
            null, 'InterlabReferral', $referral->id);
        $this->outbox->flush($referral->peer, true);

        return $referral->fresh();
    }

    public function markReviewed(User $user, InterlabReferral $referral): InterlabReferral
    {
        $this->assertDirection($referral, 'out');
        if ($referral->status !== 'completed') {
            throw new InterlabException('Natija hali kelmagan', 409);
        }
        if ($referral->reviewed_at) {
            return $referral;
        }

        DB::transaction(function () use ($user, $referral) {
            $referral->forceFill(['reviewed_at' => now(), 'reviewed_by_name' => $user->name, 'last_event_at' => now()])->save();
            $this->outbox->queue($referral->peer, 'referral.reviewed', [
                'uuid' => $referral->uuid, 'revision' => $referral->result_revision, 'by' => $user->name,
            ], $referral->uuid);
        });

        $this->notifier->audit('interlab.referral_reviewed', "Natija ko'rib chiqildi: {$referral->number}",
            ['revision' => $referral->result_revision], 'InterlabReferral', $referral->id);
        $this->outbox->flush($referral->peer, true);

        return $referral->fresh();
    }

    /** Yo'llanma tekshiruvchiga yetib bordi */
    public function onDelivered(InterlabMessage $message): void
    {
        if ($message->type !== 'referral.create' || ! $message->referral_uuid) {
            return;
        }
        InterlabReferral::where('uuid', $message->referral_uuid)->where('direction', 'out')
            ->where('status', 'sending')
            ->update(['status' => 'sent', 'delivered_at' => now(), 'last_event_at' => now(), 'updated_at' => now()]);
        InterlabReferral::where('uuid', $message->referral_uuid)->where('direction', 'out')
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now(), 'updated_at' => now()]);
    }

    // ================================================================ tekshiruvchi (B)

    public function accept(User $user, InterlabReferral $referral, ?int $groupId): InterlabReferral
    {
        $this->assertDirection($referral, 'in');
        if ($referral->status !== 'sent') {
            throw new InterlabException('Yo\'llanma qabul qilishni kutmayapti', 409);
        }

        DB::transaction(function () use ($user, $referral, $groupId) {
            $referral->forceFill([
                'status'           => 'accepted',
                'accepted_at'      => now(),
                'accepted_by_name' => $user->name,
                'group_id'         => $groupId ?: ($user->laboratory_group_id ?: null),
                'last_event_at'    => now(),
            ])->save();
            $this->outbox->queue($referral->peer, 'referral.accept', [
                'uuid' => $referral->uuid, 'by' => $user->name, 'at' => now()->toIso8601String(),
            ], $referral->uuid);
        });

        $this->notifier->audit('interlab.referral_accept', "Yo'llanma qabul qilindi: {$referral->number}",
            null, 'InterlabReferral', $referral->id);
        $this->outbox->flush($referral->peer, true);

        return $referral->fresh();
    }

    public function reject(User $user, InterlabReferral $referral, string $reason): InterlabReferral
    {
        $this->assertDirection($referral, 'in');
        if (! in_array($referral->status, ['sent', 'accepted'], true)) {
            throw new InterlabException('Bu holatdagi yo\'llanmani rad etib bo\'lmaydi', 409);
        }

        DB::transaction(function () use ($user, $referral, $reason) {
            $referral->forceFill([
                'status' => 'rejected', 'rejected_at' => now(), 'reject_reason' => $reason, 'last_event_at' => now(),
            ])->save();
            $referral->results()->where('is_draft', true)->delete();
            $this->outbox->queue($referral->peer, 'referral.reject', [
                'uuid' => $referral->uuid, 'reason' => $reason, 'by' => $user->name,
            ], $referral->uuid);
        });

        $this->notifier->audit('interlab.referral_reject', "Yo'llanma rad etildi: {$referral->number}. Sabab: {$reason}",
            null, 'InterlabReferral', $referral->id);
        $this->outbox->flush($referral->peer, true);

        return $referral->fresh();
    }

    /** Natija qoralamasi (faqat shu serverda, yuborilmaydi) */
    public function saveDraft(User $user, InterlabReferral $referral, array $rows, ?string $note): InterlabReferral
    {
        $this->assertDirection($referral, 'in');
        if (! in_array($referral->status, ['accepted', 'completed'], true)) {
            throw new InterlabException('Natija kiritish uchun avval yo\'llanmani qabul qiling', 409);
        }

        $symbols = collect($referral->elements)->pluck('symbol')->map(fn ($s) => strtoupper($s))->all();
        $revision = $referral->result_revision + 1;

        DB::transaction(function () use ($user, $referral, $rows, $note, $symbols, $revision) {
            $referral->results()->where('is_draft', true)->delete();
            $seen = [];
            foreach ($rows as $row) {
                $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
                if (! in_array($symbol, $symbols, true) || isset($seen[$symbol])) {
                    continue;
                }
                $seen[$symbol] = true;
                [$text, $value] = self::parseValue($row['value_text'] ?? null);
                $method = self::text($row['method'] ?? null, 255);
                $notes = self::text($row['notes'] ?? null, 500);
                if ($text === null && $method === null && $notes === null) {
                    continue;
                }
                $referral->results()->create([
                    'revision'        => $revision,
                    'is_draft'        => true,
                    'symbol'          => $this->canonicalSymbol($referral, $symbol),
                    'value_text'      => $text,
                    'value'           => $value,
                    'unit'            => self::text($row['unit'] ?? null, 32),
                    'method'          => $method,
                    'notes'           => $notes,
                    'entered_by'      => $user->id,
                    'entered_by_name' => $user->name,
                    'entered_at'      => now(),
                ]);
            }
            $referral->forceFill(['result_note' => self::text($note, 2000)])->save();
        });

        return $referral->fresh();
    }

    public function sendResults(User $user, InterlabReferral $referral, ?string $reason): InterlabReferral
    {
        $this->assertDirection($referral, 'in');
        if (! in_array($referral->status, ['accepted', 'completed'], true)) {
            throw new InterlabException('Bu holatda natija yuborib bo\'lmaydi', 409);
        }

        $revision = $referral->result_revision + 1;
        $drafts = $referral->results()->where('is_draft', true)->where('revision', $revision)->get()->keyBy(fn ($r) => strtoupper($r->symbol));

        $missing = [];
        foreach ($referral->elements as $el) {
            $d = $drafts->get(strtoupper($el['symbol']));
            if (! $d || $d->value_text === null || $d->value_text === '') {
                $missing[] = $el['symbol'];
            }
        }
        if ($missing) {
            throw new InterlabException('Natija kiritilmagan: ' . implode(', ', $missing));
        }
        if ($revision > 1 && mb_strlen(trim((string) $reason)) < 3) {
            throw new InterlabException('Natijani tuzatish sababini yozing');
        }

        DB::transaction(function () use ($user, $referral, $drafts, $revision, $reason) {
            $now = now();
            foreach ($drafts as $d) {
                $d->forceFill(['is_draft' => false, 'entered_at' => $now])->save();
            }
            $referral->forceFill([
                'status'          => 'completed',
                'completed_at'    => $referral->completed_at ?: $now,
                'result_revision' => $revision,
                'performer_name'  => $user->name,
                'revision_reason' => $revision > 1 ? self::text($reason, 2000) : null,
                'reviewed_at'     => null,
                'reviewed_by_name'=> null,
                'last_event_at'   => $now,
            ])->save();

            $this->outbox->queue($referral->peer, 'referral.result', [
                'uuid'            => $referral->uuid,
                'revision'        => $revision,
                'results'         => $drafts->values()->map(fn (InterlabResult $r) => [
                    'symbol'     => $r->symbol,
                    'value_text' => $r->value_text,
                    'value'      => $r->value,
                    'unit'       => $r->unit,
                    'method'     => $r->method,
                    'notes'      => $r->notes,
                ])->all(),
                'result_note'     => $referral->result_note,
                'performer_name'  => $user->name,
                'performer_position' => $user->position,
                'performed_at'    => $now->toIso8601String(),
                'revision_reason' => $referral->revision_reason,
            ], $referral->uuid);
        });

        $this->notifier->audit('interlab.referral_result',
            "Natija yuborildi: {$referral->number}" . ($revision > 1 ? " ({$revision}-tahrir)" : ''),
            ['revision' => $revision], 'InterlabReferral', $referral->id);
        $this->outbox->flush($referral->peer, true);

        return $referral->fresh();
    }

    // ================================================================ kiruvchi xabarlar

    public function onCreate(InterlabPeer $peer, array $body, InterlabMessage $message): void
    {
        $uuid = self::uuid($body['uuid'] ?? null);
        if (InterlabReferral::where('uuid', $uuid)->exists()) {
            return; // allaqachon bor
        }

        $elements = [];
        foreach (array_slice((array) ($body['elements'] ?? []), 0, self::MAX_ELEMENTS) as $el) {
            if (! is_array($el) || empty($el['symbol'])) {
                continue;
            }
            $elements[] = [
                'symbol'  => mb_substr((string) $el['symbol'], 0, 16),
                'name_uz' => self::text($el['name_uz'] ?? null, 191),
                'name_ru' => self::text($el['name_ru'] ?? null, 191),
                'name_en' => self::text($el['name_en'] ?? null, 191),
                'unit'    => self::text($el['unit'] ?? null, 32),
            ];
        }
        if (! $elements) {
            throw new InterlabException('Yo\'llanmada element yo\'q');
        }

        $hide = (bool) ($body['hide_code'] ?? false);
        $referral = InterlabReferral::create([
            'uuid'            => $uuid,
            'number'          => mb_substr((string) ($body['number'] ?? $uuid), 0, 40),
            'direction'       => 'in',
            'peer_id'         => $peer->id,
            'status'          => 'sent',
            'sample_code'     => $hide ? null : self::text($body['sample_code'] ?? null, 64),
            'sample_number'   => $hide ? null : self::text($body['sample_number'] ?? null, 64),
            'hide_code'       => $hide,
            'sample_info'     => is_array($body['sample_info'] ?? null) ? array_map(
                fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 191) : null, $body['sample_info']
            ) : null,
            'elements'        => $elements,
            'description'     => self::text($body['description'] ?? null, 2000),
            'note'            => self::text($body['note'] ?? null, 2000),
            'deadline'        => self::date($body['deadline'] ?? null),
            'sender_name'     => self::text($body['sender_name'] ?? null, 191),
            'sender_position' => self::text($body['sender_position'] ?? null, 191),
            'sender_lab'      => self::text($body['sender_lab'] ?? null, 191) ?? $peer->name,
            'sent_at'         => self::datetime($body['sent_at'] ?? null) ?? now(),
            'delivered_at'    => now(),
            'last_event_at'   => now(),
        ]);

        $this->notifier->audit('interlab.referral_received', "Tekshiruv yo'llanmasi keldi: {$referral->number} ({$peer->name})",
            ['elements' => array_column($elements, 'symbol')], 'InterlabReferral', $referral->id);
        $this->notifier->notify(
            $this->notifier->usersWith('interlab.process'),
            "Yangi tekshiruv yo'llanmasi: {$referral->number}",
            $peer->name . ': ' . implode(', ', array_column($elements, 'symbol')),
            'info', 'interlab-detail', $referral->id, "interlab-in-{$referral->uuid}"
        );
    }

    public function onAccept(InterlabPeer $peer, array $body): void
    {
        $referral = $this->findForPeer($peer, $body, 'out');
        if (! in_array($referral->status, ['sending', 'sent'], true)) {
            return; // bekor qilingan yoki allaqachon qabul qilingan
        }
        $referral->forceFill([
            'status'           => 'accepted',
            'accepted_at'      => self::datetime($body['at'] ?? null) ?? now(),
            'accepted_by_name' => self::text($body['by'] ?? null, 191),
            'delivered_at'     => $referral->delivered_at ?: now(),
            'last_event_at'    => now(),
        ])->save();

        $this->notifier->audit('interlab.referral_accepted', "Yo'llanmani {$peer->name} qabul qildi: {$referral->number}",
            null, 'InterlabReferral', $referral->id);
        $this->notifier->notify($this->ownerIds($referral),
            "Yo'llanma qabul qilindi: {$referral->number}", $peer->name, 'info',
            'interlab-detail', $referral->id, "interlab-accepted-{$referral->uuid}");
    }

    public function onReject(InterlabPeer $peer, array $body): void
    {
        $referral = $this->findForPeer($peer, $body, 'out');
        if (in_array($referral->status, ['rejected', 'cancelled', 'completed'], true)) {
            return;
        }
        $reason = self::text($body['reason'] ?? null, 2000);
        $referral->forceFill([
            'status' => 'rejected', 'rejected_at' => now(), 'reject_reason' => $reason, 'last_event_at' => now(),
            'delivered_at' => $referral->delivered_at ?: now(),
        ])->save();

        $this->notifier->audit('interlab.referral_rejected', "Yo'llanmani {$peer->name} rad etdi: {$referral->number}. Sabab: {$reason}",
            null, 'InterlabReferral', $referral->id);
        $this->notifier->notify($this->ownerIds($referral),
            "Yo'llanma rad etildi: {$referral->number}", $reason, 'warning',
            'interlab-detail', $referral->id, "interlab-rejected-{$referral->uuid}");
    }

    public function onResult(InterlabPeer $peer, array $body): void
    {
        $referral = $this->findForPeer($peer, $body, 'out');
        $revision = (int) ($body['revision'] ?? 0);
        if ($revision < 1) {
            throw new InterlabException('Natija tahriri noto\'g\'ri');
        }
        if ($revision <= $referral->result_revision) {
            return; // eski yoki takroriy
        }
        if ($referral->status === 'rejected') {
            throw new InterlabException('Yo\'llanma rad etilgan', 409);
        }

        $symbols = collect($referral->elements)->pluck('symbol')->mapWithKeys(fn ($s) => [strtoupper($s) => $s])->all();
        $performedAt = self::datetime($body['performed_at'] ?? null) ?? now();
        $performer = self::text($body['performer_name'] ?? null, 191);

        foreach ((array) ($body['results'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = strtoupper((string) ($row['symbol'] ?? ''));
            if (! isset($symbols[$key])) {
                continue;
            }
            [$text, $value] = self::parseValue($row['value_text'] ?? null);
            $referral->results()->create([
                'revision'        => $revision,
                'is_draft'        => false,
                'symbol'          => $symbols[$key],
                'value_text'      => $text,
                'value'           => $value,
                'unit'            => self::text($row['unit'] ?? null, 32),
                'method'          => self::text($row['method'] ?? null, 255),
                'notes'           => self::text($row['notes'] ?? null, 500),
                'entered_by_name' => $performer,
                'entered_at'      => $performedAt,
            ]);
        }

        $wasCancelled = $referral->status === 'cancelled';
        $referral->forceFill([
            'status'           => 'completed',
            'completed_at'     => $referral->completed_at ?: now(),
            'result_revision'  => $revision,
            'result_note'      => self::text($body['result_note'] ?? null, 2000),
            'revision_reason'  => self::text($body['revision_reason'] ?? null, 2000),
            'performer_name'   => $performer,
            'reviewed_at'      => null,
            'reviewed_by_name' => null,
            'delivered_at'     => $referral->delivered_at ?: now(),
            'last_event_at'    => now(),
        ])->save();

        $this->notifier->audit('interlab.referral_result_received',
            "Natija keldi: {$referral->number} ({$peer->name})" . ($revision > 1 ? ", {$revision}-tahrir" : '') . ($wasCancelled ? ' — bekor qilinganidan keyin' : ''),
            ['revision' => $revision], 'InterlabReferral', $referral->id);
        $this->notifier->notify($this->ownerIds($referral),
            $revision > 1 ? "Natija tuzatildi: {$referral->number}" : "Natija keldi: {$referral->number}",
            $peer->name, $revision > 1 ? 'warning' : 'info',
            'interlab-detail', $referral->id, "interlab-result-{$referral->uuid}-{$revision}");
    }

    public function onCancel(InterlabPeer $peer, array $body): void
    {
        $referral = $this->findForPeer($peer, $body, 'in');
        if (in_array($referral->status, ['completed', 'rejected', 'cancelled'], true)) {
            return; // natija allaqachon yuborilgan — bekor qilish e'tiborsiz
        }
        $reason = self::text($body['reason'] ?? null, 2000);
        $referral->forceFill([
            'status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'last_event_at' => now(),
        ])->save();
        $referral->results()->where('is_draft', true)->delete();

        $this->notifier->audit('interlab.referral_cancelled', "{$peer->name} yo'llanmani bekor qildi: {$referral->number}. Sabab: {$reason}",
            null, 'InterlabReferral', $referral->id);
        $this->notifier->notify(
            $this->notifier->usersWith('interlab.process', $referral->group_id),
            "Yo'llanma bekor qilindi: {$referral->number}", $reason, 'warning',
            'interlab-detail', $referral->id, "interlab-cancelled-{$referral->uuid}");
    }

    public function onReviewed(InterlabPeer $peer, array $body): void
    {
        $referral = $this->findForPeer($peer, $body, 'in');
        if ((int) ($body['revision'] ?? 0) !== $referral->result_revision) {
            return; // eski tahrir ko'rib chiqilgan
        }
        $referral->forceFill([
            'reviewed_at' => now(), 'reviewed_by_name' => self::text($body['by'] ?? null, 191), 'last_event_at' => now(),
        ])->save();
    }

    // ================================================================ ko'rsatish

    public function present(InterlabReferral $r, User $user, bool $detail = false): array
    {
        $r->loadMissing('peer', 'group');
        $out = $r->isOutgoing();

        $data = [
            'id'              => $r->id,
            'uuid'            => $r->uuid,
            'number'          => $r->number,
            'direction'       => $r->direction,
            'status'          => $r->status,
            'peer'            => $r->peer ? [
                'id' => $r->peer->id, 'name' => $r->peer->name, 'code' => $r->peer->code,
                'online' => $r->peer->online, 'status' => $r->peer->status,
            ] : null,
            'sample_id'       => $out ? $r->sample_id : null,
            'sample_code'     => $r->sample_code,
            'sample_number'   => $r->sample_number,
            'hide_code'       => $r->hide_code,
            'elements'        => $r->elements,
            'deadline'        => $r->deadline?->toDateString(),
            'overdue'         => $r->deadline && $r->isOpen() && $r->deadline->lt(now()->startOfDay()),
            'sender_name'     => $r->sender_name,
            'sender_lab'      => $r->sender_lab,
            'group'           => $r->group ? [
                'id' => $r->group->id, 'name_uz' => $r->group->name_uz, 'name_ru' => $r->group->name_ru, 'name_en' => $r->group->name_en,
            ] : null,
            'result_revision' => $r->result_revision,
            'sent_at'         => $r->sent_at,
            'delivered_at'    => $r->delivered_at,
            'accepted_at'     => $r->accepted_at,
            'completed_at'    => $r->completed_at,
            'cancelled_at'    => $r->cancelled_at,
            'rejected_at'     => $r->rejected_at,
            'reviewed_at'     => $r->reviewed_at,
            'last_event_at'   => $r->last_event_at,
            'created_at'      => $r->created_at,
            'unreviewed'      => $out && $r->status === 'completed' && ! $r->reviewed_at,
            'sync'            => $this->syncState($r),
        ];

        if (! $detail) {
            return $data;
        }

        $results = $r->results()->orderBy('revision')->orderBy('id')->get();
        $sent = $results->where('is_draft', false);
        $revisions = $sent->groupBy('revision')->map(fn ($rows, $rev) => [
            'revision'  => (int) $rev,
            'performer' => $rows->first()->entered_by_name,
            'at'        => $rows->max('entered_at'),
            'rows'      => $rows->values()->map(fn ($x) => $this->presentResult($x))->all(),
        ])->values()->all();

        $can = fn (string $p) => $user->isAdmin() || $user->hasPermission($p);

        $data += [
            'description'      => $r->description,
            'note'             => $r->note,
            'sample_info'      => $r->sample_info,
            'tolerance_pct'    => $out ? $r->tolerance_pct : null,
            'sender_position'  => $r->sender_position,
            'accepted_by_name' => $r->accepted_by_name,
            'reject_reason'    => $r->reject_reason,
            'cancel_reason'    => $r->cancel_reason,
            'result_note'      => $r->result_note,
            'revision_reason'  => $r->revision_reason,
            'performer_name'   => $r->performer_name,
            'reviewed_by_name' => $r->reviewed_by_name,
            'revisions'        => $revisions,
            'drafts'           => $out ? [] : $results->where('is_draft', true)->values()->map(fn ($x) => $this->presentResult($x))->all(),
            'comparison'       => $out ? $this->comparison($r, $sent) : null,
            'messages'         => InterlabMessage::where('referral_uuid', $r->uuid)->orderBy('id')->get()
                ->map(fn (InterlabMessage $m) => [
                    'id' => $m->id, 'type' => $m->type, 'direction' => $m->direction, 'status' => $m->status,
                    'attempts' => $m->attempts, 'next_attempt_at' => $m->next_attempt_at, 'sent_at' => $m->sent_at,
                    'processed_at' => $m->processed_at, 'last_error' => $m->last_error, 'created_at' => $m->created_at,
                ])->all(),
            'can' => [
                'cancel'       => $out && $can('interlab.send') && in_array($r->status, ['sending', 'sent', 'accepted'], true),
                'review'       => $out && $can('interlab.send') && $r->status === 'completed' && ! $r->reviewed_at,
                'accept'       => ! $out && $can('interlab.process') && $r->status === 'sent',
                'reject'       => ! $out && $can('interlab.process') && in_array($r->status, ['sent', 'accepted'], true),
                'edit_results' => ! $out && $can('interlab.process') && in_array($r->status, ['accepted', 'completed'], true),
                'retry'        => $can('interlab.send') || $can('interlab.process') || $can('interlab.manage'),
            ],
        ];

        return $data;
    }

    private function presentResult(InterlabResult $x): array
    {
        return [
            'id' => $x->id, 'revision' => $x->revision, 'symbol' => $x->symbol,
            'value_text' => $x->value_text, 'value' => $x->value, 'unit' => $x->unit,
            'method' => $x->method, 'notes' => $x->notes,
            'entered_by_name' => $x->entered_by_name, 'entered_at' => $x->entered_at,
        ];
    }

    /** Bizning natija (probadagi) va tekshiruvchi natijasi yonma-yon */
    private function comparison(InterlabReferral $r, $sent): array
    {
        $own = [];
        $sample = $r->sample_id ? Sample::withTrashed()->with('sampleElements.element')->find($r->sample_id) : null;
        foreach ($sample?->sampleElements ?? [] as $se) {
            if ($se->element) {
                $own[strtoupper($se->element->symbol)] = $se;
            }
        }
        $latest = $sent->where('revision', $r->result_revision)->keyBy(fn ($x) => strtoupper($x->symbol));

        return collect($r->elements)->map(function ($el) use ($own, $latest, $r) {
            $key = strtoupper($el['symbol']);
            $se = $own[$key] ?? null;
            $theirs = $latest->get($key);
            $a = $se && $se->result_value !== null ? (float) $se->result_value : null;
            $b = $theirs?->value;

            $diff = $rel = $within = null;
            if ($a !== null && $b !== null) {
                $diff = $b - $a;
                $mean = (abs($a) + abs($b)) / 2;
                $rel = $mean > 0 ? abs($diff) / $mean * 100 : 0.0;
                $within = $r->tolerance_pct !== null ? $rel <= $r->tolerance_pct + 1e-9 : null;
            }
            $ownUnit = $se?->result_unit;
            $theirUnit = $theirs?->unit;

            return [
                'symbol'        => $el['symbol'],
                'name_uz'       => $el['name_uz'] ?? null,
                'name_ru'       => $el['name_ru'] ?? null,
                'name_en'       => $el['name_en'] ?? null,
                'unit'          => $el['unit'] ?? null,
                'own_value'     => $a,
                'own_unit'      => $ownUnit,
                'own_status'    => $se?->status,
                'their_text'    => $theirs?->value_text,
                'their_value'   => $b,
                'their_unit'    => $theirUnit,
                'their_method'  => $theirs?->method,
                'their_notes'   => $theirs?->notes,
                'diff'          => $diff !== null ? round($diff, 8) : null,
                'rel_pct'       => $rel !== null ? round($rel, 3) : null,
                'within'        => $within,
                'unit_mismatch' => $ownUnit && $theirUnit && mb_strtolower(trim($ownUnit)) !== mb_strtolower(trim($theirUnit)),
            ];
        })->values()->all();
    }

    /** Ushbu yo'llanma bo'yicha yuborilmagan xabarlar */
    private function syncState(InterlabReferral $r): array
    {
        $rows = InterlabMessage::where('referral_uuid', $r->uuid)->where('direction', 'out')
            ->whereIn('status', ['pending', 'sending', 'failed'])
            ->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        return [
            'pending' => (int) ($rows['pending'] ?? 0) + (int) ($rows['sending'] ?? 0),
            'failed'  => (int) ($rows['failed'] ?? 0),
        ];
    }

    // ================================================================ yordamchilar

    private function nextNumber(InterlabIdentity $me): string
    {
        $prefix = 'IL-' . $me->code . '-' . now()->format('Y') . '-';
        $last = InterlabReferral::where('direction', 'out')->where('number', 'like', $prefix . '%')
            ->pluck('number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix)))
            ->max() ?? 0;

        return $prefix . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    private function findForPeer(InterlabPeer $peer, array $body, string $direction): InterlabReferral
    {
        $uuid = self::uuid($body['uuid'] ?? null);
        $referral = InterlabReferral::where('uuid', $uuid)->where('direction', $direction)
            ->where('peer_id', $peer->id)->lockForUpdate()->first();
        if (! $referral) {
            throw new InterlabException('Yo\'llanma topilmadi', 404);
        }

        return $referral;
    }

    private function ownerIds(InterlabReferral $r): array
    {
        $ids = [];
        if ($r->created_by) {
            $ids[] = $r->created_by;
        }
        if ($r->group_id) {
            $ids = array_merge($ids, $this->notifier->usersWith('interlab.send', $r->group_id)->all());
        }
        if (! $ids) {
            $ids = $this->notifier->usersWith('interlab.send')->all();
        }

        return array_values(array_unique($ids));
    }

    private function canonicalSymbol(InterlabReferral $r, string $upper): string
    {
        foreach ($r->elements as $el) {
            if (strtoupper($el['symbol']) === $upper) {
                return $el['symbol'];
            }
        }

        return $upper;
    }

    private function assertDirection(InterlabReferral $r, string $direction): void
    {
        if ($r->direction !== $direction) {
            throw new InterlabException($direction === 'out'
                ? 'Bu amal faqat jo\'natuvchi tomonda bajariladi'
                : 'Bu amal faqat tekshiruvchi tomonda bajariladi', 409);
        }
    }

    /**
     * «41,82» → ['41.82', 41.82];  «<0.01» → ['<0.01', null];  bo'sh → [null, null]
     *
     * @return array{0: ?string, 1: ?float}
     */
    public static function parseValue($input): array
    {
        if ($input === null) {
            return [null, null];
        }
        $text = trim((string) $input);
        if ($text === '') {
            return [null, null];
        }
        $text = mb_substr($text, 0, 64);
        $normalized = str_replace([',', ' '], ['.', ''], $text);
        if (preg_match('/^[-+]?\d+(\.\d+)?([eE][-+]?\d+)?$/', $normalized) || preg_match('/^[-+]?\.\d+$/', $normalized)) {
            return [$normalized, (float) $normalized];
        }

        return [$text, null];
    }

    private static function text($value, int $max): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function uuid($value): string
    {
        if (! is_string($value) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw new InterlabException('Yo\'llanma identifikatori noto\'g\'ri', 400);
        }

        return strtolower($value);
    }

    private static function date($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private static function datetime($value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
