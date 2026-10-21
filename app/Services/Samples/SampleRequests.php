<?php

namespace App\Services\Samples;

use App\Models\ActivityLog;
use App\Models\CustomerDepartment;
use App\Models\Element;
use App\Models\Notification;
use App\Models\Sample;
use App\Models\SampleRequest;
use App\Models\SampleRequestItem;
use App\Models\TestProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Buyurtma so'rovlari (qoralama buyurtma) — barcha qoidalar shu yerda.
 *
 *   draft ──yuborish──▶ submitted ──qabul (hammasi)──▶ received
 *     ▲                    │  │ └─qabul (bir qismi)──▶ partial ──qabul──▶ received
 *     └──qaytarib olish────┘  ├─rad etish─▶ rejected (qabul qilinganlari bo'lsa — received)
 *                             ├─bekor qilish (buyurtmachi)─▶ cancelled
 *                             └─muddat o'tdi (rejalashtirgich)─▶ expired ──qabul / qaytarib olish──▶ ...
 *
 * Qoralama — hali buyurtma emas: raqam ham, proba shifri ham berilmaydi. Raqam (B-2026-0001)
 * yuborilganda beriladi; proba shifri (PR-…) faqat namuna jismonan kelib, laboratoriya qabul qilganda.
 * Qabulda qoralamadagi ma'lumot faqat «taklif»: laborant hammasini to'g'rilay oladi.
 */
class SampleRequests
{
    public const PORTAL_PERMISSION = 'portal.request';
    public const STATES = ['liquid', 'solid'];

    public function __construct(private CustomerPortal $portal)
    {
    }

    /* =============================================================== tekshiruv qoidalari */

    /** Qoralamani saqlash: to'liq bo'lishi shart emas */
    public function rules(): array
    {
        $max = (int) config('sample_requests.max_items', 100);

        return [
            'delivery_date'          => ['nullable', 'date'],
            'contact_name'           => ['nullable', 'string', 'max:191'],
            'contact_phone'          => ['nullable', 'string', 'max:64'],
            'notes'                  => ['nullable', 'string', 'max:2000'],
            'items'                  => ['present', 'array', "max:$max"],
            'items.*.customer_code'  => ['nullable', 'string', 'max:64'],
            'items.*.state_of_matter'=> ['nullable', Rule::in(self::STATES)],
            'items.*.test_profile_id'=> ['nullable', 'integer', Rule::exists('test_profiles', 'id')->where('is_active', true)],
            'items.*.element_ids'    => ['nullable', 'array', 'max:60'],
            'items.*.element_ids.*'  => ['integer', Rule::exists('elements', 'id')->where('is_active', true)],
            'items.*.quantity'       => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'items.*.quantity_unit'  => ['nullable', Rule::in(QuantityUnit::UNITS)],
            'items.*.notes'          => ['nullable', 'string', 'max:500'],
        ];
    }

    /* =============================================================== yaratish / tahrirlash */

    public function create(CustomerDepartment $department, array $data, User $user, string $origin): SampleRequest
    {
        return DB::transaction(function () use ($department, $data, $user, $origin) {
            $request = SampleRequest::create([
                'customer_department_id' => $department->id,
                'origin'                 => $origin,
                'status'                 => 'draft',
                'created_by'             => $user->id,
                'updated_by'             => $user->id,
            ] + $this->headerAttrs($data));
            $this->syncItems($request, $data['items'] ?? []);
            $this->log('created', $request, "Buyurtma qoralamasi yaratildi ({$department->name})", $user);

            return $request->fresh('items');
        });
    }

    public function update(SampleRequest $request, array $data, User $user): SampleRequest
    {
        $this->assertStatus($request, ['draft'], "Faqat qoralamani tahrirlash mumkin");

        return DB::transaction(function () use ($request, $data, $user) {
            $request->fill($this->headerAttrs($data) + ['updated_by' => $user->id])->save();
            $this->syncItems($request, $data['items'] ?? []);
            $this->log('updated', $request, 'Buyurtma qoralamasi yangilandi', $user);

            return $request->fresh('items');
        });
    }

    public function delete(SampleRequest $request, User $user): void
    {
        $this->assertStatus($request, ['draft'], "Faqat qoralamani o'chirish mumkin");
        DB::transaction(function () use ($request, $user) {
            $this->log('deleted', $request, "Buyurtma qoralamasi o'chirildi", $user);
            SampleRequestItem::where('sample_request_id', $request->id)->delete();
            $request->delete();
        });
    }

    private function headerAttrs(array $data): array
    {
        $out = [];
        foreach (['delivery_date', 'contact_name', 'contact_phone', 'notes'] as $k) {
            if (array_key_exists($k, $data)) {
                $v = is_string($data[$k]) ? trim($data[$k]) : $data[$k];
                $out[$k] = $v === '' ? null : $v;
            }
        }

        return $out;
    }

    /** Qatorlar to'liq almashtiriladi (qoralama — tarixi kerak emas) */
    private function syncItems(SampleRequest $request, array $items): void
    {
        SampleRequestItem::where('sample_request_id', $request->id)->delete();
        foreach (array_values($items) as $i => $it) {
            $ids = array_values(array_unique(array_map('intval', (array) ($it['element_ids'] ?? []))));
            $code = trim((string) ($it['customer_code'] ?? ''));
            SampleRequestItem::create([
                'sample_request_id' => $request->id,
                'position'          => $i + 1,
                'customer_code'     => $code === '' ? null : $code,
                'state_of_matter'   => in_array($it['state_of_matter'] ?? null, self::STATES, true) ? $it['state_of_matter'] : 'solid',
                'test_profile_id'   => ! empty($it['test_profile_id']) ? (int) $it['test_profile_id'] : null,
                'element_ids'       => $ids,
                'quantity'          => isset($it['quantity']) && $it['quantity'] !== '' ? (float) $it['quantity'] : null,
                'quantity_unit'     => ! empty($it['quantity_unit']) ? $it['quantity_unit'] : (isset($it['quantity']) && $it['quantity'] !== '' ? 'g' : null),
                'notes'             => ($n = trim((string) ($it['notes'] ?? ''))) === '' ? null : $n,
                'status'            => 'pending',
            ]);
        }
    }

    /* =============================================================== holat o'zgarishlari */

    public function submit(SampleRequest $request, User $user): SampleRequest
    {
        $this->assertStatus($request, ['draft'], 'Faqat qoralamani yuborish mumkin');
        $request->load('items');
        $this->assertComplete($request);

        $request = $this->withNumberRetry(function () use ($request, $user) {
            return DB::transaction(function () use ($request, $user) {
                $now = now();
                $request->forceFill([
                    'number'       => $request->number ?: $this->nextNumber((int) $now->year),
                    'status'       => 'submitted',
                    'submitted_at' => $now,
                    'submitted_by' => $user->id,
                    'expires_at'   => $now->copy()->addDays(max(1, (int) config('sample_requests.expire_days', 14))),
                    'updated_by'   => $user->id,
                ])->save();
                $this->log('updated', $request, "Buyurtma yuborildi: {$request->number}", $user);

                return $request;
            });
        });

        $this->notifyLab($request);

        return $request->fresh('items');
    }

    /** Yuborilgan (yoki muddati o'tgan) so'rovni qoralamaga qaytarish — hech narsa qabul qilinmagan bo'lsa */
    public function withdraw(SampleRequest $request, User $user): SampleRequest
    {
        $this->assertStatus($request, ['submitted', 'expired'], "Faqat yuborilgan so'rovni qaytarib olish mumkin");
        $this->assertNothingReceived($request);
        $request->forceFill([
            'status' => 'draft', 'origin' => 'portal', 'expires_at' => null, 'updated_by' => $user->id,
        ])->save();
        $this->log('updated', $request, "Buyurtma qoralamaga qaytarildi: {$request->number}", $user);

        return $request->fresh('items');
    }

    /** Buyurtmachi bekor qiladi — hech narsa qabul qilinmagan bo'lsa */
    public function cancel(SampleRequest $request, User $user, ?string $reason = null): SampleRequest
    {
        $this->assertStatus($request, ['draft', 'submitted', 'expired'], "Bu so'rovni bekor qilib bo'lmaydi");
        $this->assertNothingReceived($request);
        DB::transaction(function () use ($request, $user, $reason) {
            SampleRequestItem::where('sample_request_id', $request->id)->where('status', 'pending')->update(['status' => 'rejected']);
            $request->forceFill([
                'status' => 'cancelled', 'closed_at' => now(), 'closed_by' => $user->id,
                'close_reason' => $reason ?: null, 'updated_by' => $user->id,
            ])->save();
            $this->log('updated', $request, "Buyurtma bekor qilindi: " . ($request->number ?: "#{$request->id}"), $user);
        });

        return $request->fresh('items');
    }

    /** Laboratoriya rad etadi: kutilayotgan namunalar rad etiladi (qabul qilinganlari saqlanadi) */
    public function reject(SampleRequest $request, User $user, string $reason): SampleRequest
    {
        $this->assertStatus($request, SampleRequest::WAITING, "Bu so'rovni rad etib bo'lmaydi");
        DB::transaction(function () use ($request, $user, $reason) {
            SampleRequestItem::where('sample_request_id', $request->id)->where('status', 'pending')->update(['status' => 'rejected']);
            $anyReceived = SampleRequestItem::where('sample_request_id', $request->id)->where('status', 'received')->exists();
            $request->forceFill([
                'status' => $anyReceived ? 'received' : 'rejected',
                'closed_at' => now(), 'closed_by' => $user->id, 'close_reason' => $reason, 'updated_by' => $user->id,
            ])->save();
            $this->log('updated', $request, "Buyurtma rad etildi: {$request->number} — {$reason}", $user);
        });
        $this->notifyCustomer($request, 'rejected', $reason);

        return $request->fresh('items');
    }

    /**
     * Namuna keldi: tanlangan qatorlardan haqiqiy probalar yaratiladi.
     *
     * @param  array  $data  ['handed_over_by' => ?, 'items' => [['id', 'sample_number', 'customer_code', 'state_of_matter',
     *                        'test_profile_id', 'element_ids', 'received_quantity', 'quantity_unit', 'assigned_group_id', 'notes'], ...]]
     * @return array{request: SampleRequest, samples: Sample[]}
     */
    public function receive(SampleRequest $request, array $data, User $user): array
    {
        $this->assertStatus($request, SampleRequest::WAITING, "Bu so'rov bo'yicha namuna qabul qilib bo'lmaydi");
        $request->load('items', 'department');

        $pending = $request->items->where('status', 'pending')->keyBy('id');
        $rows = array_values($data['items'] ?? []);
        $errors = [];
        if (! $rows) {
            $errors['items'][] = 'Qabul qilinadigan kamida bitta namunani belgilang';
        }

        // Tekshiruv: hammasi yozishdan OLDIN — xato bo'lsa hech narsa yaratilmaydi
        $plan = [];
        $seenCodes = [];
        foreach ($rows as $i => $row) {
            $item = $pending->get((int) ($row['id'] ?? 0));
            if (! $item) {
                $errors["items.$i.id"][] = "Namuna topilmadi yoki allaqachon qabul qilingan";
                continue;
            }
            $elementIds = array_values(array_unique(array_map('intval', (array) ($row['element_ids'] ?? []))));
            $profileId = array_key_exists('test_profile_id', $row) ? ($row['test_profile_id'] ?: null) : $item->test_profile_id;
            if (! $elementIds && $profileId) {
                $elementIds = TestProfile::find($profileId)?->elements()->pluck('elements.id')->map(fn ($v) => (int) $v)->all() ?? [];
            }
            if (! $elementIds && ! array_key_exists('element_ids', $row)) {
                $elementIds = $item->resolvedElementIds();
            }
            if (! $elementIds) {
                $errors["items.$i.element_ids"][] = "{$item->position}-namuna: aniqlanadigan ko'rsatkich tanlanmagan";
            } elseif (Element::whereIn('id', $elementIds)->count() !== count($elementIds)) {
                $errors["items.$i.element_ids"][] = "{$item->position}-namuna: ko'rsatkich topilmadi";
            }

            $code = trim((string) ($row['customer_code'] ?? $item->customer_code ?? ''));
            if ($code !== '') {
                if (isset($seenCodes[$code])) {
                    $errors["items.$i.customer_code"][] = "«{$code}» shifri bu qabulda takrorlangan";
                }
                $seenCodes[$code] = true;
                // [P26] shifr har oy qaytalanishi mumkin — takror faqat shu oyda (qabul oyi) taqiqlanadi
                $period = now()->format('Y-m');
                $dup = Sample::query()->where('customer_department_id', $request->customer_department_id)
                    ->where('customer_code', $code)->where('period_month', $period)
                    ->where('status', '!=', 'archived')->value('code');
                if ($dup) {
                    $errors["items.$i.customer_code"][] = "Bu bo'limning «{$code}» shifrli probasi {$period} oyida allaqachon ro'yxatda bor: {$dup}";
                }
            }

            $number = trim((string) ($row['sample_number'] ?? ''));
            if ($number === '') {
                $number = $code !== '' ? $code : "{$request->number}-{$item->position}";
            }
            $state = $row['state_of_matter'] ?? $item->state_of_matter;
            if (! in_array($state, self::STATES, true)) {
                $errors["items.$i.state_of_matter"][] = "Noto'g'ri holat";
            }
            $qty = $row['received_quantity'] ?? null;
            if ($qty !== null && $qty !== '' && (! is_numeric($qty) || (float) $qty <= 0)) {
                $errors["items.$i.received_quantity"][] = "Miqdor musbat son bo'lsin";
            }
            $unit = $row['quantity_unit'] ?? $item->quantity_unit ?? 'g';
            if (! in_array($unit, QuantityUnit::UNITS, true)) {
                $errors["items.$i.quantity_unit"][] = "Noto'g'ri birlik";
            }
            $group = $row['assigned_group_id'] ?? null;
            if ($group && ! DB::table('laboratory_groups')->where('id', (int) $group)->exists()) {
                $errors["items.$i.assigned_group_id"][] = 'Guruh topilmadi';
            }

            $plan[] = [
                'item'     => $item,
                'number'   => mb_substr($number, 0, 191),
                'code'     => $code === '' ? null : mb_substr($code, 0, 64),
                'state'    => $state,
                'elements' => $elementIds,
                'qty'      => $qty === null || $qty === '' ? null : (float) $qty,
                'unit'     => $unit,
                'group'    => $group ? (int) $group : null,
                'notes'    => trim((string) ($row['notes'] ?? $item->notes ?? '')) ?: null,
            ];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $handedOver = trim((string) ($data['handed_over_by'] ?? '')) ?: ($request->contact_name ?: null);
        $handedOver = $handedOver ? mb_substr($handedOver, 0, 191) : null;

        // Bir vaqtda ikki laborant qabul qilsa — proba shifri to'qnashadi, qayta urinamiz
        $samples = $this->withNumberRetry(fn () => DB::transaction(function () use ($request, $plan, $user, $handedOver) {
            $now = now();
            $created = [];
            foreach ($plan as $p) {
                $item = SampleRequestItem::whereKey($p['item']->id)->lockForUpdate()->first();
                if (! $item || $item->status !== 'pending') {
                    throw ValidationException::withMessages(['items' => ["{$p['item']->position}-namuna allaqachon qabul qilingan"]]);
                }

                $sample = Sample::create([
                    'code'                 => $this->nextSampleCode(),
                    'sample_number'        => $p['number'],
                    'source_document'      => "Buyurtma {$request->number}",
                    'source_division'      => $request->department?->name,
                    'registering_lab_id'   => $user->laboratory_id,
                    'registering_group_id' => $user->laboratory_group_id,
                    'assigned_group_id'    => $p['group'],
                    'state_of_matter'      => $p['state'],
                    'status'               => $p['group'] ? 'assigned' : 'registered',
                    'period_month'         => $now->format('Y-m'),
                    'registered_by'        => $user->id,
                    'registered_at'        => $now,
                    'assigned_at'          => $p['group'] ? $now : null,
                    'notes'                => $p['notes'],
                ]);
                SampleIntake::apply($sample, [
                    'customer_department_id' => $request->customer_department_id,
                    'customer_code'          => $p['code'],
                    'received_quantity'      => $p['qty'],
                    'quantity_unit'          => $p['qty'] !== null ? $p['unit'] : null,
                    'handed_over_by'         => $handedOver,
                ]);

                $units = Element::whereIn('id', $p['elements'])->pluck('default_unit', 'id');
                foreach ($p['elements'] as $elementId) {
                    $sample->sampleElements()->create([
                        'element_id'  => $elementId,
                        'result_unit' => $units[$elementId] ?? null,
                        'status'      => 'pending',
                    ]);
                }

                $item->forceFill(['status' => 'received', 'sample_id' => $sample->id])->save();
                $this->log('created', $sample, "Proba ro'yxatga olindi: {$sample->code} (buyurtma {$request->number})", $user);
                $created[] = $sample;
            }

            $left = SampleRequestItem::where('sample_request_id', $request->id)->where('status', 'pending')->count();
            $request->forceFill([
                'status'         => $left ? 'partial' : 'received',
                'received_at'    => $request->received_at ?: $now,
                'received_by'    => $request->received_by ?: $user->id,
                'handed_over_by' => $handedOver ?? $request->handed_over_by,
                'closed_at'      => $left ? null : $now,
                'closed_by'      => $left ? null : $user->id,
                'updated_by'     => $user->id,
            ])->save();
            $this->log('updated', $request, "Buyurtma bo'yicha namuna qabul qilindi: {$request->number} (" . count($created) . ' ta)', $user);

            return $created;
        }));

        $request->refresh();
        $this->notifyCustomer($request, 'received', null, count($samples));

        return ['request' => $request->fresh('items'), 'samples' => $samples];
    }

    /** Rejalashtirgich: muddati o'tgan so'rovlar (hech narsa qabul qilinmagan) */
    public function expireOverdue(): int
    {
        $n = 0;
        SampleRequest::where('status', 'submitted')->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->orderBy('id')->each(function (SampleRequest $r) use (&$n) {
                $r->forceFill(['status' => 'expired'])->save();
                $this->log('updated', $r, "Buyurtma muddati o'tdi: {$r->number}", null);
                $this->notifyCustomer($r, 'expired');
                $n++;
            });

        return $n;
    }

    /* =============================================================== yordamchilar */

    private function assertStatus(SampleRequest $request, array $allowed, string $message): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    private function assertNothingReceived(SampleRequest $request): void
    {
        if (SampleRequestItem::where('sample_request_id', $request->id)->where('status', 'received')->exists()) {
            throw ValidationException::withMessages(['status' => ["Namunalarning bir qismi qabul qilingan — endi faqat laboratoriya yopishi mumkin"]]);
        }
    }

    /** Yuborishdan oldin: kamida bitta namuna, har birida aniqlanadigan ko'rsatkich, shifrlar takrorlanmasin */
    private function assertComplete(SampleRequest $request): void
    {
        $errors = [];
        if ($request->items->isEmpty()) {
            $errors['items'][] = "Kamida bitta namuna qo'shing";
        }
        $dept = CustomerDepartment::find($request->customer_department_id);
        if (! $dept || ! $dept->is_active) {
            $errors['department_id'][] = "Bo'lim faol emas";
        }
        $codes = [];
        foreach ($request->items as $i => $item) {
            if (! $item->resolvedElementIds()) {
                $errors["items.$i.element_ids"][] = "{$item->position}-namuna: nimani aniqlash kerakligini tanlang";
            }
            if ($item->customer_code !== null) {
                if (isset($codes[$item->customer_code])) {
                    $errors["items.$i.customer_code"][] = "«{$item->customer_code}» shifri takrorlangan";
                }
                $codes[$item->customer_code] = true;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** B-2026-0001 — yil bo'yicha ketma-ket */
    public function nextNumber(int $year): string
    {
        $prefix = "B-$year-";
        $last = SampleRequest::where('number', 'like', $prefix . '%')->orderByDesc('number')->value('number');
        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** Proba shifri — SampleController::generateCode() bilan bir xil qoida (PR-YYYYMM-0001) */
    public function nextSampleCode(): string
    {
        $prefix = 'PR-' . now()->format('Ym') . '-';
        $last = Sample::withTrashed()->where('code', 'like', $prefix . '%')->orderByDesc('code')->first();
        $num = $last ? ((int) substr($last->code, -4)) + 1 : 1;

        return $prefix . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }

    private function withNumberRetry(callable $fn)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (QueryException $e) {
                $unique = str_contains(strtolower($e->getMessage()), 'unique') || str_contains($e->getMessage(), '2627') || str_contains($e->getMessage(), '2601');
                if (! $unique || $attempt >= 5) {
                    throw $e;
                }
                usleep(random_int(20000, 120000));
            }
        }
    }

    private function log(string $action, $model, string $description, ?User $user): void
    {
        try {
            ActivityLog::create([
                'user_id'     => $user?->id,
                'action'      => $action,
                'model_type'  => class_basename($model),
                'model_id'    => $model->getKey(),
                'description' => $description,
                'properties'  => null,
                'ip_address'  => app()->runningInConsole() ? null : request()->ip(),
                'created_at'  => now(),
            ]);
        } catch (Throwable $e) {
            report($e); // jurnal yozilmasa ham amal bajarilsin
        }
    }

    /** Laboratoriya: so'rovni qabul qila oladigan faol xodimlarga bildirishnoma */
    private function notifyLab(SampleRequest $request): void
    {
        try {
            $dept = $request->department?->name ?? '';
            $count = $request->items()->count();
            foreach (User::where('is_active', true)->get() as $u) {
                if ($this->portal->isPortalUser($u) || ! $u->hasPermission('sample_requests.receive')) {
                    continue;
                }
                Notification::create([
                    'user_id'    => $u->id,
                    'type'       => 'sample',
                    'level'      => 'info',
                    'title'      => "Yangi buyurtma {$request->number}",
                    'body'       => "{$dept}: {$count} ta namuna. Namuna kelganda «Buyurtmalar» sahifasida qabul qiling.",
                    'link_route' => 'sample-request',
                    'link_id'    => $request->id,
                    'dedup_key'  => "sreq-submit-{$request->id}-{$u->id}-" . $request->submitted_at?->timestamp,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Buyurtmachi: so'rovni tuzgan/yuborgan portal foydalanuvchisiga */
    private function notifyCustomer(SampleRequest $request, string $event, ?string $reason = null, int $count = 0): void
    {
        try {
            $ids = array_unique(array_filter([(int) $request->created_by, (int) $request->submitted_by]));
            $titles = [
                'received' => "Buyurtma {$request->number}: namuna qabul qilindi",
                'rejected' => $request->status === 'received'
                    ? "Buyurtma {$request->number}: qolgan namunalar rad etildi"
                    : "Buyurtma {$request->number} rad etildi",
                'expired'  => "Buyurtma {$request->number}: muddati o'tdi",
            ];
            $bodies = [
                'received' => $request->status === 'partial'
                    ? "{$count} ta namuna qabul qilindi, qolganlari kutilmoqda."
                    : "{$count} ta namuna qabul qilindi. Holatini «Mening probalarim» da kuzating.",
                'rejected' => 'Sabab: ' . ($reason ?? '—'),
                'expired'  => "Namuna belgilangan muddatda olib kelinmadi. Qaytarib olib, qayta yuborishingiz mumkin.",
            ];
            foreach (User::whereIn('id', $ids)->where('is_active', true)->get() as $u) {
                if (! $this->portal->isPortalUser($u)) {
                    continue; // xodim yaratgan so'rov — buyurtmachiga emas
                }
                Notification::create([
                    'user_id'    => $u->id,
                    'type'       => 'sample',
                    'level'      => $event === 'received' ? 'info' : 'warning',
                    'title'      => $titles[$event],
                    'body'       => $bodies[$event],
                    'link_route' => 'portal-request',
                    'link_id'    => $request->id,
                    'dedup_key'  => "sreq-{$event}-{$request->id}-{$u->id}-" . now()->timestamp,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /* =============================================================== javob shakli */

    public function present(SampleRequest $r, bool $withItems = true): array
    {
        $r->loadMissing(['department:id,code,name', 'creator:id,name', 'submitter:id,name', 'receiver:id,name', 'closer:id,name']);
        $out = [
            'id'             => (int) $r->id,
            'number'         => $r->number,
            'status'         => $r->status,
            'origin'         => $r->origin,
            'department'     => $r->department ? ['id' => (int) $r->department->id, 'code' => $r->department->code, 'name' => $r->department->name] : null,
            'delivery_date'  => $r->delivery_date?->toDateString(),
            'contact_name'   => $r->contact_name,
            'contact_phone'  => $r->contact_phone,
            'notes'          => $r->notes,
            'created_at'     => $r->created_at?->toDateTimeString(),
            'created_by'     => $r->creator?->name,
            'submitted_at'   => $r->submitted_at?->toDateTimeString(),
            'submitted_by'   => $r->submitter?->name,
            'expires_at'     => $r->expires_at?->toDateTimeString(),
            'received_at'    => $r->received_at?->toDateTimeString(),
            'received_by'    => $r->receiver?->name,
            'handed_over_by' => $r->handed_over_by,
            'closed_at'      => $r->closed_at?->toDateTimeString(),
            'closed_by'      => $r->closer?->name,
            'close_reason'   => $r->close_reason,
            'updated_at'     => $r->updated_at?->toDateTimeString(),
        ];

        $items = $r->relationLoaded('items') ? $r->items : $r->items()->get();
        $out['counts'] = [
            'items'    => $items->count(),
            'pending'  => $items->where('status', 'pending')->count(),
            'received' => $items->where('status', 'received')->count(),
            'rejected' => $items->where('status', 'rejected')->count(),
        ];

        if (! $withItems) {
            $out['codes'] = $items->pluck('customer_code')->filter()->take(6)->values();
            return $out;
        }

        $items->loadMissing(['profile:id,code,name_uz,name_ru,name_en', 'sample:id,code,status']);
        $allIds = $items->flatMap(fn ($i) => (array) $i->element_ids)->map(fn ($v) => (int) $v)->unique()->values()->all();
        $elements = $allIds ? Element::whereIn('id', $allIds)->get(['id', 'symbol', 'name_uz', 'name_ru', 'name_en', 'default_unit'])->keyBy('id') : collect();

        $out['items'] = $items->map(function (SampleRequestItem $i) use ($elements) {
            $ids = array_map('intval', (array) ($i->element_ids ?? []));

            return [
                'id'              => (int) $i->id,
                'position'        => (int) $i->position,
                'customer_code'   => $i->customer_code,
                'state_of_matter' => $i->state_of_matter,
                'test_profile_id' => $i->test_profile_id,
                'profile'         => $i->profile ? [
                    'id' => (int) $i->profile->id, 'code' => $i->profile->code,
                    'name_uz' => $i->profile->name_uz, 'name_ru' => $i->profile->name_ru, 'name_en' => $i->profile->name_en,
                ] : null,
                'element_ids'     => $ids,
                'elements'        => collect($ids)->map(fn ($id) => $elements->get($id))->filter()->map(fn ($e) => [
                    'id' => (int) $e->id, 'symbol' => $e->symbol, 'name_uz' => $e->name_uz,
                    'name_ru' => $e->name_ru, 'name_en' => $e->name_en, 'default_unit' => $e->default_unit,
                ])->values(),
                'quantity'        => $i->quantity !== null ? QuantityUnit::number($i->quantity) : null,
                'quantity_unit'   => $i->quantity_unit,
                'notes'           => $i->notes,
                'status'          => $i->status,
                'sample'          => $i->sample ? ['id' => (int) $i->sample->id, 'code' => $i->sample->code, 'status' => $i->sample->status] : null,
            ];
        })->values();

        return $out;
    }

    /** Tanlov ro'yxatlari: faol ko'rsatkichlar va profillar (portal foydalanuvchisi /elements ga kira olmaydi) */
    public function options(): array
    {
        return [
            'elements' => Element::where('is_active', true)->orderBy('sort_order')->orderBy('symbol')
                ->get(['id', 'symbol', 'name_uz', 'name_ru', 'name_en', 'default_unit'])
                ->map(fn ($e) => [
                    'id' => (int) $e->id, 'symbol' => $e->symbol, 'name_uz' => $e->name_uz,
                    'name_ru' => $e->name_ru, 'name_en' => $e->name_en, 'default_unit' => $e->default_unit,
                ])->values(),
            'profiles' => TestProfile::where('is_active', true)->with('elements:id')->orderBy('sort_order')->orderBy('code')
                ->get(['id', 'code', 'name_uz', 'name_ru', 'name_en'])
                ->map(fn ($p) => [
                    'id' => (int) $p->id, 'code' => $p->code, 'name_uz' => $p->name_uz, 'name_ru' => $p->name_ru,
                    'name_en' => $p->name_en, 'element_ids' => $p->elements->pluck('id')->map(fn ($v) => (int) $v)->values(),
                ])->values(),
            'units'       => QuantityUnit::UNITS,
            'expire_days' => (int) config('sample_requests.expire_days', 14),
        ];
    }
}
