<?php

namespace App\Services\Samples;

use App\Models\ActivityLog;
use App\Models\CustomerDepartment;
use App\Models\Element;
use App\Models\LabShift;
use App\Models\Notification;
use App\Models\Sample;
use App\Models\SampleRequest;
use App\Models\SampleRequestItem;
use App\Models\TestProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;
use App\Models\CloseReason;
use Carbon\Carbon;

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

    /** [P36] grafik bo'yicha qabul: oddiy «yangi buyurtma» / «qabul qilindi» xabarlari yuborilmaydi (o'zinikini yuboradi) */
    public bool $quiet = false;

    /** [P42] oxirgi submit() natijasi: guruhlar bo'yicha bo'lingan bo'lsa — hammasi (birinchisi — asosiy) */
    public array $lastSubmitted = [];

    public function __construct(private CustomerPortal $portal)
    {
    }

    /** [P34] bo'linma xodimlari ma'lumotnomasi */
    private function contacts(): CustomerContacts
    {
        return app(CustomerContacts::class);
    }

    /* =============================================================== tekshiruv qoidalari */

    /** Qoralamani saqlash: to'liq bo'lishi shart emas */
    public function rules(): array
    {
        $max = (int) config('sample_requests.max_items', 100);

        return [
            'delivery_date'          => ['nullable', 'date'],
            // [P28] smena: faqat faol smena (jadval bo'lmasa — tekshiruvsiz e'tiborsiz qoldiriladi)
            'delivery_shift_id'      => Schema::hasTable('lab_shifts')
                ? ['nullable', 'integer', Rule::exists('lab_shifts', 'id')->where('is_active', true)]
                : ['nullable', 'integer'],
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
            // [P29] buyurtmachi svodkasi: nuqta (bo'lim tegishliligi create/update da tekshiriladi), sana, navbatchilik
            'items.*.svodka_point_id'=> ['nullable', 'integer'],
            'svodka_date'            => ['nullable', 'date'],
            'svodka_shift'           => ['nullable', Rule::in([1, 2])],
            // [P30] qaysi laboratoriya guruhiga (faqat faol guruh) va har namunaning «guruh shifri»
            'laboratory_group_id'    => Schema::hasTable('laboratory_groups')
                ? ['nullable', 'integer', Rule::exists('laboratory_groups', 'id')->where('is_active', true)]
                : ['nullable', 'integer'],
            'items.*.group_code'     => ['nullable', 'string', 'max:64'],
            // [P42] namuna qaysi guruhga (bo'sh — profilning 1-mas'ul guruhi)
            'items.*.laboratory_group_id' => ['nullable', 'integer'],
        ];
    }

    /* =============================================================== yaratish / tahrirlash */

    public function create(CustomerDepartment $department, array $data, User $user, string $origin): SampleRequest
    {
        $this->assertSvodkaPoints((int) $department->id, $data['items'] ?? []);

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
        $this->assertSvodkaPoints((int) $request->customer_department_id, $data['items'] ?? []);

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
                // [P34] F.I.Sh. va telefon bir xil ko'rinishda saqlanadi (ortiqcha probel, +998 90 123-45-67)
                if ($k === 'contact_name' && is_string($v)) {
                    $v = CustomerContacts::cleanName($v);
                } elseif ($k === 'contact_phone' && is_string($v)) {
                    $v = CustomerContacts::phone($v)['display'];
                }
                $out[$k] = $v === '' ? null : $v;
            }
        }
        // [P28] smena: id + tanlangan paytdagi nomi (keyin smena o'zgarsa ham buyurtmada saqlanadi)
        if (array_key_exists('delivery_shift_id', $data) && $this->hasShiftColumns()) {
            $shift = $data['delivery_shift_id'] ? LabShift::find((int) $data['delivery_shift_id']) : null;
            $out['delivery_shift_id'] = $shift?->id;
            $out['delivery_shift'] = $shift ? self::shiftLabel($shift) : null;
        }
        // [P30] laboratoriya guruhi
        if (array_key_exists('laboratory_group_id', $data) && $this->hasGroupColumns()) {
            $out['laboratory_group_id'] = $data['laboratory_group_id'] ? (int) $data['laboratory_group_id'] : null;
        }
        // [P29] namuna olingan sana va navbatchilik (buyurtmachi svodkasi)
        if ($this->hasSvodkaColumns()) {
            foreach (['svodka_date', 'svodka_shift'] as $k) {
                if (array_key_exists($k, $data)) {
                    $out[$k] = $data[$k] === '' ? null : $data[$k];
                }
            }
        }

        return $out;
    }

    /** [P30] guruh ustunlari (migratsiya bajarilganmi) */
    public function hasGroupColumns(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('sample_requests', 'laboratory_group_id') && Schema::hasColumn('sample_request_items', 'group_code');
    }

    /**
     * [P30] «Guruh shifri»ni kim ko'radi: buyurtmachi (portal javobida — alohida) va buyurtma yuborilgan guruh xodimlari.
     * Boshqa guruhlar, guruhsiz xodimlar, rahbariyat — ko'rmaydi.
     */
    public static function canSeeGroupCode(?User $user, ?int $groupId): bool
    {
        return $user && $groupId && (int) $user->laboratory_group_id === (int) $groupId;
    }

    /** [P30] Faol guruhlar bormi (bo'lsa — buyurtmada guruh tanlash majburiy) */
    private function groupsRequired(): bool
    {
        return $this->hasGroupColumns() && Schema::hasTable('laboratory_groups')
            && DB::table('laboratory_groups')->where('is_active', true)->exists();
    }

    /** [P42] profil guruhlari va namuna guruhi ustunlari (migratsiya bajarilganmi) */
    public function hasItemGroups(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('sample_request_items', 'laboratory_group_id') && Schema::hasTable('test_profile_groups');
    }

    /** @var array<int, int[]> */
    private array $profileGroupCache = [];

    /** [P42] Profilning faol mas'ul guruhlari — tartib bo'yicha (nofaoli o'tkazib yuboriladi) */
    public function profileGroupIds(?int $profileId): array
    {
        if (! $profileId || ! $this->hasItemGroups()) {
            return [];
        }
        if (! array_key_exists($profileId, $this->profileGroupCache)) {
            $this->profileGroupCache[$profileId] = DB::table('test_profile_groups')
                ->join('laboratory_groups', 'laboratory_groups.id', '=', 'test_profile_groups.laboratory_group_id')
                ->where('test_profile_groups.test_profile_id', $profileId)->where('laboratory_groups.is_active', true)
                ->orderBy('test_profile_groups.sort_order')->orderBy('test_profile_groups.id')
                ->pluck('laboratory_groups.id')->map(fn ($v) => (int) $v)->all();
        }

        return $this->profileGroupCache[$profileId];
    }

    /**
     * [P42] Namuna qaysi guruhga: qo'lda tanlangani → profilning 1-faol mas'ul guruhi → buyurtma guruhi (eski buyurtmalar).
     */
    public function effectiveGroupId(?int $itemGroup, ?int $profileId, ?int $requestGroup): ?int
    {
        if ($itemGroup) {
            return $itemGroup;
        }

        return $this->profileGroupIds($profileId)[0] ?? ($requestGroup ?: null);
    }

    private function hasSvodkaColumns(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('sample_request_items', 'svodka_point_id') && Schema::hasColumn('sample_requests', 'svodka_date');
    }

    /** [P29] Tanlangan nuqtalar shu bo'limning spravochnigidanmi */
    private function assertSvodkaPoints(int $deptId, array $items): void
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($it) => (int) ($it['svodka_point_id'] ?? 0), $items))));
        if (! $ids || ! $this->hasSvodkaColumns()) {
            return;
        }
        $ok = DB::table('svodka_points')->where('customer_department_id', $deptId)->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $errors = [];
        foreach (array_values($items) as $i => $it) {
            $pid = (int) ($it['svodka_point_id'] ?? 0);
            if ($pid && ! in_array($pid, $ok, true)) {
                $errors["items.$i.svodka_point_id"][] = 'Namuna nomi spravochnikda topilmadi';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasShiftColumns(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('lab_shifts') && Schema::hasColumn('sample_requests', 'delivery_shift_id');
    }

    /** «Kunduzgi 08:00–20:00» */
    public static function shiftLabel(LabShift $s): string
    {
        return trim($s->name . ' ' . $s->start_time . '–' . $s->end_time);
    }

    /** Qatorlar to'liq almashtiriladi (qoralama — tarixi kerak emas) */
    private function syncItems(SampleRequest $request, array $items): void
    {
        // [P29] nuqta so'rovda kelmasa (masalan, laboratoriya tahrirlasa) — avvalgisi o'rni bo'yicha saqlanadi
        $oldPoints = $this->hasSvodkaColumns()
            ? SampleRequestItem::where('sample_request_id', $request->id)->pluck('svodka_point_id', 'position')->all()
            : [];
        // [P30] guruh shifri ham — kelmasa, o'rni bo'yicha saqlanadi
        $oldGroupCodes = $this->hasGroupColumns()
            ? SampleRequestItem::where('sample_request_id', $request->id)->pluck('group_code', 'position')->all()
            : [];
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
            ] + ($this->hasSvodkaColumns() ? [
                'svodka_point_id'   => array_key_exists('svodka_point_id', $it)
                    ? ((int) $it['svodka_point_id'] ?: null)
                    : (isset($oldPoints[$i + 1]) ? (int) $oldPoints[$i + 1] : null),
            ] : []) + ($this->hasItemGroups() ? [
                'laboratory_group_id' => ((int) ($it['laboratory_group_id'] ?? 0)) ?: null,
            ] : []) + ($this->hasGroupColumns() ? [
                'group_code'        => array_key_exists('group_code', $it)
                    ? (($g = trim((string) $it['group_code'])) === '' ? null : mb_substr($g, 0, 64))
                    : ($oldGroupCodes[$i + 1] ?? null),
            ] : []));
        }
    }

    /* =============================================================== holat o'zgarishlari */

    public function submit(SampleRequest $request, User $user): SampleRequest
    {
        $this->assertStatus($request, ['draft'], 'Faqat qoralamani yuborish mumkin');
        $request->load('items');
        $this->assertComplete($request);

        $origNumber = $request->number;
        $all = $this->withNumberRetry(function () use ($request, $user, $origNumber) {
            return DB::transaction(function () use ($request, $user, $origNumber) {
                $request->number = $origNumber; // takroriy urinishda — raqam qaytadan olinadi
                $now = now();
                $all = $this->splitByGroup($request, $user); // [P42] har xil guruhli namunalar — alohida buyurtmalar
                foreach ($all as $r) {
                    $r->forceFill([
                        'number'       => $r->number ?: $this->nextNumber((int) $now->year),
                        'status'       => 'submitted',
                        'submitted_at' => $now,
                        'submitted_by' => $user->id,
                        'expires_at'   => $now->copy()->addDays(max(1, (int) config('sample_requests.expire_days', 14))),
                        'updated_by'   => $user->id,
                    ])->save();
                    $this->log('updated', $r, "Buyurtma yuborildi: {$r->number}" . (count($all) > 1 ? ' (guruhlar bo\'yicha bo\'lingan: ' . count($all) . ' ta)' : ''), $user);
                }

                return $all;
            });
        });
        $this->lastSubmitted = $all;
        $request = $all[0];

        foreach ($all as $r) {
            $this->notifyLab($r);
        }
        // [P34] kim olib keladi — bo'linma xodimlari ma'lumotnomasiga (yangi bo'lsa qo'shiladi, telefon yangilanadi)
        $this->contacts()->remember((int) $request->customer_department_id, $request->contact_name, $request->contact_phone, $user, 'buyurtma ' . $request->number);

        return $request->fresh('items');
    }

    /**
     * [P42] Namunalar guruhi aniqlanadi va namunaga yoziladi. Guruhlar bir nechta bo'lsa — birinchi guruh shu
     * buyurtmada qoladi, qolganlari uchun sarlavhasi bir xil yangi buyurtmalar yaratiladi (split_key bilan bog'lanadi).
     *
     * @return SampleRequest[] birinchisi — asosiy (shu buyurtma)
     */
    private function splitByGroup(SampleRequest $request, User $user): array
    {
        if (! $this->hasItemGroups()) {
            return [$request];
        }
        $reqGroup = $request->laboratory_group_id ? (int) $request->laboratory_group_id : null;
        $byGroup = [];
        foreach ($request->items()->get() as $item) { // items() o'zi position, id bo'yicha tartiblaydi (SQL Server takrorni qabul qilmaydi)
            $gid = $this->effectiveGroupId($item->laboratory_group_id ? (int) $item->laboratory_group_id : null,
                $item->test_profile_id ? (int) $item->test_profile_id : null, $reqGroup);
            if ($gid && (int) $item->laboratory_group_id !== $gid) {
                $item->forceFill(['laboratory_group_id' => $gid])->save();
            }
            $byGroup[$gid ?? 0][] = $item;
        }
        $groups = array_keys($byGroup);
        $request->forceFill(['laboratory_group_id' => $groups[0] ?: $reqGroup])->save();
        if (count($groups) < 2) {
            return [$request->fresh('items')];
        }

        $key = $request->getAttribute('split_key') ?: (string) \Illuminate\Support\Str::uuid();
        $request->forceFill(['split_key' => $key])->save();
        $out = [$request];
        foreach (array_slice($groups, 1) as $gid) {
            $copy = $request->replicate(['number', 'submitted_at', 'submitted_by', 'expires_at']);
            $copy->forceFill(['laboratory_group_id' => $gid ?: null, 'status' => 'draft', 'split_key' => $key,
                'created_by' => $request->created_by, 'updated_by' => $user->id])->save();
            foreach (array_values($byGroup[$gid]) as $n => $item) {
                $item->forceFill(['sample_request_id' => $copy->id, 'position' => $n + 1])->save();
            }
            $this->log('created', $copy, "Buyurtma guruhlar bo'yicha bo'lindi (asosiy: #{$request->id})", $user);
            $out[] = $copy;
        }
        foreach (array_values($byGroup[$groups[0]]) as $n => $item) {
            $item->forceFill(['position' => $n + 1])->save();
        }

        return array_map(fn ($r) => $r->fresh('items'), $out);
    }

    /** Yuborilgan (yoki muddati o'tgan) so'rovni qoralamaga qaytarish — hech narsa qabul qilinmagan bo'lsa */
    public function withdraw(SampleRequest $request, User $user): SampleRequest
    {
        $this->assertStatus($request, ['submitted', 'expired'], "Faqat yuborilgan so'rovni qaytarib olish mumkin");
        if ($request->origin === RequestSchedules::ORIGIN) { // [P39] grafik buyurtmasi — faqat sabab bilan yopiladi
            throw ValidationException::withMessages(['status' => ["Grafik buyurtmasini qoralamaga qaytarib bo'lmaydi — kerak bo'lmasa sabab bilan yoping"]]);
        }
        $this->assertNothingReceived($request);
        $request->forceFill([
            'status' => 'draft', 'origin' => 'portal', 'expires_at' => null, 'updated_by' => $user->id,
        ])->save();
        $this->log('updated', $request, "Buyurtma qoralamaga qaytarildi: {$request->number}", $user);

        return $request->fresh('items');
    }

    /** Buyurtmachi bekor qiladi — hech narsa qabul qilinmagan bo'lsa */
    /**
     * Buyurtmachi bekor qiladi (yopadi) — hech narsa qabul qilinmagan bo'lsa.
     * [P39] Muddati o'tgan yoki grafik buyurtmasi — ro'yxatdan sabab majburiy («Boshqa» — izoh bilan).
     */
    public function cancel(SampleRequest $request, User $user, ?string $reason = null, ?int $reasonId = null): SampleRequest
    {
        $this->assertStatus($request, ['draft', 'submitted', 'expired'], "Bu so'rovni bekor qilib bo'lmaydi");
        $this->assertNothingReceived($request);
        $mustGiveReason = $request->status === 'expired' || ($request->origin === RequestSchedules::ORIGIN && $request->status !== 'draft');
        [$reasonId, $reasonText] = $this->resolveReason($reasonId, $reason, $mustGiveReason);
        DB::transaction(function () use ($request, $user, $reasonText, $reasonId) {
            SampleRequestItem::where('sample_request_id', $request->id)->where('status', 'pending')->update(['status' => 'rejected']);
            $request->forceFill([
                'status' => 'cancelled', 'closed_at' => now(), 'closed_by' => $user->id,
                'close_reason' => $reasonText, 'updated_by' => $user->id,
            ] + ($this->hasP39Columns() ? ['close_reason_id' => $reasonId] : []))->save();
            $this->log('updated', $request, "Buyurtma yopildi (bekor qilindi): " . ($request->number ?: "#{$request->id}") . ($reasonText ? " — {$reasonText}" : ''), $user);
        });
        $this->syncSchedule($request);

        return $request->fresh('items');
    }

    /* =============================================================== [P39] sabab, sozlama */

    public function hasP39Columns(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('sample_requests', 'close_reason_id') && Schema::hasTable('close_reasons');
    }

    /** Faol sabablar (ro'yxat) */
    public function reasons(): array
    {
        if (! $this->hasP39Columns()) {
            return [];
        }

        return CloseReason::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name_uz' => $r->name_uz, 'name_ru' => $r->name_ru, 'name_en' => $r->name_en, 'needs_note' => (bool) $r->needs_note])
            ->values()->all();
    }

    /**
     * @return array{0: ?int, 1: ?string} sabab id va matni («Texnologik jarayon to'xtagan: 2-sex ta'mirda»)
     */
    public function resolveReason(?int $reasonId, ?string $note, bool $required): array
    {
        $note = trim((string) $note) ?: null;
        $note = $note ? mb_substr($note, 0, 400) : null;
        if (! $this->hasP39Columns()) {
            return [null, $note];
        }
        $reason = $reasonId ? CloseReason::where('is_active', true)->find($reasonId) : null;
        if ($reasonId && ! $reason) {
            throw ValidationException::withMessages(['reason_id' => ['Sabab topilmadi']]);
        }
        if (! $reason && $required && CloseReason::where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['reason_id' => ["Sababni ro'yxatdan tanlang"]]);
        }
        if ($reason && $reason->needs_note && ! $note) {
            throw ValidationException::withMessages(['reason' => ["Bu sabab uchun izoh yozing"]]);
        }
        if ($required && ! $reason && ! $note) {
            throw ValidationException::withMessages(['reason' => ['Sababni yozing']]);
        }
        $text = $reason ? $reason->name_uz . ($note ? ": {$note}" : '') : $note;

        return [$reason?->id, $text ? mb_substr($text, 0, 500) : null];
    }

    /** [P39] Grafik buyurtmasi o'zgarsa — grafik partiyasi holati ham */
    private function syncSchedule(SampleRequest $request): void
    {
        if ($request->origin !== RequestSchedules::ORIGIN) {
            return;
        }
        try {
            app(RequestSchedules::class)->syncRun($request->fresh());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Laboratoriya rad etadi: kutilayotgan namunalar rad etiladi (qabul qilinganlari saqlanadi) */
    public function reject(SampleRequest $request, User $user, ?string $reason, ?int $reasonId = null): SampleRequest
    {
        $this->assertStatus($request, SampleRequest::WAITING, "Bu so'rovni rad etib bo'lmaydi");
        [$reasonId, $reason] = $this->resolveReason($reasonId, $reason, true); // [P39] ro'yxatdan sabab yoki matn
        DB::transaction(function () use ($request, $user, $reason, $reasonId) {
            SampleRequestItem::where('sample_request_id', $request->id)->where('status', 'pending')->update(['status' => 'rejected']);
            $anyReceived = SampleRequestItem::where('sample_request_id', $request->id)->where('status', 'received')->exists();
            $request->forceFill([
                'status' => $anyReceived ? 'received' : 'rejected',
                'closed_at' => now(), 'closed_by' => $user->id, 'close_reason' => $reason, 'updated_by' => $user->id,
            ] + ($this->hasP39Columns() ? ['close_reason_id' => $reasonId] : []))->save();
            $this->log('updated', $request, "Buyurtma rad etildi: {$request->number} — {$reason}", $user);
        });
        $this->notifyCustomer($request, 'rejected', $reason);
        $this->syncSchedule($request);

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

            // [P30] guruh: qabulda tanlanmasa — buyurtmachi tanlagan guruh
            $group = ($row['assigned_group_id'] ?? null) ?: ($item->getAttribute('laboratory_group_id') ?: ($request->laboratory_group_id ?: null)); // [P42]
            $code = trim((string) ($row['customer_code'] ?? $item->customer_code ?? ''));
            // [P33] shifr takrorlanishi taqiqlanmaydi (na shu qabulda, na shu oyda) — interfeys faqat ogohlantiradi
            $number = trim((string) ($row['sample_number'] ?? ''));
            if ($number === '') {
                // [P40] grafik buyurtmasida ham: laboratoriya o'zgartirmasa — buyurtmachi shifri («123-09:00»)
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
                'gcode'    => $this->hasGroupColumns() ? ($item->group_code ?: null) : null, // [P30]
            ];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $handedOver = CustomerContacts::cleanName((string) ($data['handed_over_by'] ?? '')) ?: ($request->contact_name ?: null); // [P34]
        $handedOver = $handedOver ? mb_substr($handedOver, 0, 191) : null;

        // [P33] olib kelgan shaxs telefoni (buyurtmadagi telefon — taklif) va qabul qilib olgan xodim (ro'yxatdan)
        $handedPhone = CustomerContacts::phoneValue((string) ($data['handed_over_phone'] ?? '')) ?: ($request->contact_phone ?: null); // [P34]
        $handedPhone = $handedPhone ? mb_substr($handedPhone, 0, 64) : null;
        // [P39] haqiqiy qabul vaqti (kechiktirib kiritish) — tekshiruv yozishdan oldin
        $actual = $this->actualReceivedAt($request, $data['received_at'] ?? null, $user);
        $lateEntry = $this->hasP39Columns() && ($request->status === 'expired' || $actual->lt(now()->subMinutes(60)));

        $receiverId = (int) ($data['received_by'] ?? 0) ?: (int) $user->id;
        if ($receiverId !== (int) $user->id && ! SampleIntake::isReceiver($receiverId, $user)) {
            throw ValidationException::withMessages(['received_by' => ["Qabul qilib olgan xodim ro'yxatda yo'q"]]);
        }
        $hasPhone = \Illuminate\Support\Facades\Schema::hasColumn('sample_requests', 'handed_over_phone');

        // Bir vaqtda ikki laborant qabul qilsa — proba shifri to'qnashadi, qayta urinamiz
        $samples = $this->withNumberRetry(fn () => DB::transaction(function () use ($request, $plan, $user, $handedOver, $handedPhone, $receiverId, $hasPhone, $actual, $lateEntry) {
            $now = $actual->copy(); // [P39] probalar haqiqiy qabul vaqti bilan (jamlanma hisobot davri shundan)
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
                    'received_by'            => $receiverId, // [P33]
                ]);
                if ($p['gcode'] !== null) {
                    $sample->forceFill(['group_code' => $p['gcode']])->save(); // [P30] guruh shifri probaga
                }

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
                'received_by'    => $request->received_by ?: $receiverId, // [P33] tanlangan xodim
                'handed_over_by' => $handedOver ?? $request->handed_over_by,
                'closed_at'      => $left ? null : now(),
                'closed_by'      => $left ? null : $user->id,
                'updated_by'     => $user->id,
            ] + ($hasPhone ? ['handed_over_phone' => $handedPhone ?? $request->handed_over_phone] : [])
              + ($this->hasP39Columns() ? [
                  'late_entry'          => $request->late_entry || $lateEntry,
                  'received_entered_at' => $request->received_entered_at ?: now(),
              ] : []))->save();
            $this->log('updated', $request, "Buyurtma bo'yicha namuna qabul qilindi: {$request->number} (" . count($created) . ' ta)'
                . ($handedOver ? "; olib keldi: {$handedOver}" : '')
                . '; qabul qilib oldi: ' . (\App\Models\User::find($receiverId)?->name ?? "#$receiverId")
                . ($lateEntry ? '; KECHIKTIRIB KIRITILDI, haqiqiy vaqt ' . $now->format('d.m.Y H:i') : ''), $user);

            return $created;
        }));

        $request->refresh();
        $this->notifyCustomer($request, 'received', null, count($samples));
        $this->syncSchedule($request);
        // [P34] olib kelgan shaxs — bo'linma ma'lumotnomasiga (laboratoriya yozgan yangi ism ham qo'shiladi)
        if ($handedOver) {
            $this->contacts()->remember((int) $request->customer_department_id, $handedOver, $handedPhone, $user, 'qabul ' . $request->number);
        }

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
                $this->syncSchedule($r);
                $n++;
            });

        return $n;
    }

    /** [P39] Haqiqiy qabul vaqti: bo'sh — hozir. Kelajak yoki buyurtmadan oldin — xato; eski — faqat ruxsati borga */
    private function actualReceivedAt(SampleRequest $request, $value, User $user): Carbon
    {
        $now = now();
        if ($value === null || trim((string) $value) === '' || ! $this->hasP39Columns()) {
            return $now;
        }
        try {
            $at = Carbon::parse((string) $value)->seconds(0);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['received_at' => ["Qabul vaqti noto'g'ri"]]);
        }
        if ($at->gt($now->copy()->addMinutes(5))) {
            throw ValidationException::withMessages(['received_at' => ["Qabul vaqti kelajakda bo'lishi mumkin emas"]]);
        }
        $from = ($request->created_at ?? $now)->copy()->subMinutes(5);
        if ($at->lt($from)) {
            throw ValidationException::withMessages(['received_at' => ['Qabul vaqti buyurtma yaratilgan vaqtdan (' . $request->created_at?->format('d.m.Y H:i') . ') oldin bo\'lishi mumkin emas']]);
        }
        $limit = app(RequestSettings::class)->get()['late_entry_hours'];
        if ($at->lt($now->copy()->subHours($limit)) && ! ($user->isAdmin() || $user->hasPermission('sample_requests.late_entry'))) {
            throw ValidationException::withMessages(['received_at' => ["{$limit} soatdan eski qabulni faqat laboratoriya boshlig'i kiritadi"]]);
        }

        return $at;
    }

    /** [P39] Muddati o'tib, N kun ichida yopilmaganlar — avtomatik «Yopilmadi (avtomatik)» */
    public function autoCloseExpired(): int
    {
        $days = app(RequestSettings::class)->get()['auto_close_days'];
        if (! $days || ! $this->hasP39Columns()) {
            return 0;
        }
        $n = 0;
        SampleRequest::where('status', 'expired')->whereNotNull('expires_at')->where('expires_at', '<', now()->subDays($days))
            ->orderBy('id')->each(function (SampleRequest $r) use (&$n, $days) {
                DB::transaction(function () use ($r, $days) {
                    SampleRequestItem::where('sample_request_id', $r->id)->where('status', 'pending')->update(['status' => 'rejected']);
                    $any = SampleRequestItem::where('sample_request_id', $r->id)->where('status', 'received')->exists();
                    $r->forceFill([
                        'status' => $any ? 'received' : 'cancelled', 'closed_at' => now(), 'closed_by' => null, 'auto_closed' => true,
                        'close_reason' => "Avtomatik yopildi: {$days} kun ichida sabab ko'rsatilmadi",
                    ])->save();
                    $this->log('updated', $r, "Buyurtma avtomatik yopildi (sababsiz): {$r->number}", null);
                });
                $this->syncSchedule($r);
                $n++;
            });

        return $n;
    }

    /** [P39] Kunlik eslatma: muddati o'tib yopilmagan buyurtmalar — buyurtmachiga (bo'linmasi bo'yicha) va laboratoriyaga */
    public function remindUnclosed(): int
    {
        $rows = SampleRequest::where('status', 'expired')->get(['id', 'customer_department_id', 'laboratory_group_id']);
        if ($rows->isEmpty()) {
            return 0;
        }
        $day = now()->format('Y-m-d');
        $sent = 0;
        try {
            foreach ($rows->groupBy('customer_department_id') as $deptId => $list) {
                $ids = Schema::hasTable('customer_department_user')
                    ? DB::table('customer_department_user')->where('customer_department_id', $deptId)->pluck('user_id') : collect();
                foreach (User::whereIn('id', $ids)->where('is_active', true)->get() as $u) {
                    if (! $this->portal->isPortalUser($u)) {
                        continue;
                    }
                    Notification::create([
                        'user_id' => $u->id, 'type' => 'sample', 'level' => 'warning',
                        'title' => 'Yopilmagan buyurtmalar: ' . $list->count() . ' ta',
                        'body' => "Muddati o'tgan buyurtmalar bor. Namuna olib kelinmagan bo'lsa — sababini ko'rsatib yoping, olib kelingan bo'lsa — laboratoriyaga ayting.",
                        'link_route' => 'portal-requests', 'link_id' => null, 'dedup_key' => "sreq-unclosed-{$day}-{$u->id}",
                    ]);
                    $sent++;
                }
            }
            foreach (User::where('is_active', true)->get() as $u) {
                if ($this->portal->isPortalUser($u) || ! ($u->isAdmin() || $u->hasPermission('sample_requests.late_entry'))) {
                    continue; // laboratoriya boshlig'i (yopadi yoki kechiktirib kiritadi)
                }
                Notification::create([
                    'user_id' => $u->id, 'type' => 'sample', 'level' => 'warning',
                    'title' => 'Yopilmagan buyurtmalar: ' . $rows->count() . ' ta',
                    'body' => "«Buyurtmalar» → «Muddati o'tgan». Namuna kelgan bo'lsa — haqiqiy vaqt bilan qabul qiling, kelmagan bo'lsa — sabab bilan yoping.",
                    'link_route' => 'sample-requests', 'link_id' => null, 'dedup_key' => "sreq-unclosed-{$day}-{$u->id}",
                ]);
                $sent++;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $sent;
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

    /** Yuborishdan oldin: kamida bitta namuna, har birida aniqlanadigan ko'rsatkich */
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
        // [P30] faol guruhlar bo'lsa — har namuna qaysi guruhga ekani aniq bo'lishi kerak
        // [P42] guruh — namunada (qo'lda yoki profilning mas'ul guruhi); eski buyurtmada — buyurtma guruhi
        if ($this->groupsRequired()) {
            $active = DB::table('laboratory_groups')->where('is_active', true)->pluck('id')->map(fn ($v) => (int) $v)->flip();
            if ($this->hasItemGroups()) {
                foreach ($request->items as $i => $item) {
                    $gid = $this->effectiveGroupId($item->laboratory_group_id ? (int) $item->laboratory_group_id : null,
                        $item->test_profile_id ? (int) $item->test_profile_id : null, $request->laboratory_group_id ? (int) $request->laboratory_group_id : null);
                    if (! $gid) {
                        $errors["items.$i.laboratory_group_id"][] = "{$item->position}-namuna: laboratoriya guruhini tanlang";
                    } elseif (! $active->has($gid)) {
                        $errors["items.$i.laboratory_group_id"][] = "{$item->position}-namuna: tanlangan guruh faol emas — boshqasini tanlang";
                    }
                }
            } else {
                $gid = (int) $request->laboratory_group_id;
                if (! $gid) {
                    $errors['laboratory_group_id'][] = 'Laboratoriya guruhini tanlang';
                } elseif (! $active->has($gid)) {
                    $errors['laboratory_group_id'][] = 'Tanlangan guruh faol emas — boshqasini tanlang';
                }
            }
        }
        foreach ($request->items as $i => $item) {
            if (! $item->resolvedElementIds()) {
                $errors["items.$i.element_ids"][] = "{$item->position}-namuna: nimani aniqlash kerakligini tanlang";
            }
            // [P33] bir buyurtmada takrorlangan shifr — xato emas (formada ogohlantirish chiqadi)
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
        if ($this->quiet) {
            return;
        }
        try {
            $dept = $request->department?->name ?? '';
            $count = $request->items()->count();
            // [P28] qachon olib kelinadi: sana + smena
            $when = trim(($request->delivery_date?->format('d.m.Y') ?? '') . ' ' . ($request->delivery_shift ?? ''));
            $gid = $this->hasGroupColumns() ? (int) $request->laboratory_group_id : 0;
            foreach (User::where('is_active', true)->get() as $u) {
                if ($this->portal->isPortalUser($u) || ! $u->hasPermission('sample_requests.receive')) {
                    continue;
                }
                // [P30] boshqa guruh xodimiga — yo'q (guruhsiz xodim va «hammasini ko'radi» — oladi)
                if ($gid && $u->laboratory_group_id && (int) $u->laboratory_group_id !== $gid && ! self::seesAllGroups($u)) {
                    continue;
                }
                Notification::create([
                    'user_id'    => $u->id,
                    'type'       => 'sample',
                    'level'      => 'info',
                    'title'      => "Yangi buyurtma {$request->number}",
                    'body'       => "{$dept}: {$count} ta namuna" . ($when !== '' ? ", olib kelinadi: {$when}" : '') . ". Namuna kelganda «Buyurtmalar» sahifasida qabul qiling.",
                    'link_route' => 'sample-request',
                    'link_id'    => $request->id,
                    'dedup_key'  => "sreq-submit-{$request->id}-{$u->id}-" . $request->submitted_at?->timestamp,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * [P30] Barcha guruhlar buyurtmalarini ko'radi: admin yoki hech qaysi guruhga biriktirilmagan xodim
     * (laboratoriya boshlig'i, qabulxona). Guruhga biriktirilgan xodim — faqat o'z guruhini
     * (samples.view_all ruxsati bo'lsa ham: «Buyurtmalar» guruh navbati).
     */
    public static function seesAllGroups(User $u): bool
    {
        return $u->isAdmin() || ! $u->laboratory_group_id;
    }

    /** Buyurtmachi: so'rovni tuzgan/yuborgan portal foydalanuvchisiga */
    private function notifyCustomer(SampleRequest $request, string $event, ?string $reason = null, int $count = 0): void
    {
        if ($this->quiet) {
            return;
        }
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
                'expired'  => $request->origin === RequestSchedules::ORIGIN
                    ? 'Grafik ' . ($request->scheduled_at?->format('d.m H:i') ?? '') . ": namuna kelmadi. Olib kelinmagan bo'lsa — «Buyurtmalarim» da sababini ko'rsatib yoping."
                    : "Namuna belgilangan muddatda olib kelinmadi. Olib kelinmagan bo'lsa — sababini ko'rsatib yoping yoki qaytarib olib, qayta yuboring.",
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

    /** @param bool $withGroupCode «guruh shifri»ni qo'shish (buyurtmachi yoki o'sha guruh xodimi uchun) */
    public function present(SampleRequest $r, bool $withItems = true, bool $withGroupCode = false): array
    {
        $r->loadMissing(['department:id,code,name', 'creator:id,name', 'submitter:id,name', 'receiver:id,name', 'closer:id,name']);
        $out = [
            'id'             => (int) $r->id,
            'number'         => $r->number,
            'status'         => $r->status,
            'origin'         => $r->origin,
            'department'     => $r->department ? ['id' => (int) $r->department->id, 'code' => $r->department->code, 'name' => $r->department->name] : null,
            'delivery_date'  => $r->delivery_date?->toDateString(),
            'delivery_shift_id' => $r->delivery_shift_id ? (int) $r->delivery_shift_id : null, // [P28]
            'delivery_shift'    => $r->delivery_shift,
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
            'handed_over_phone' => $r->getAttribute('handed_over_phone'), // [P33]
            'closed_at'      => $r->closed_at?->toDateTimeString(),
            'closed_by'      => $r->closer?->name,
            'close_reason'   => $r->close_reason,
            'updated_at'     => $r->updated_at?->toDateTimeString(),
        ];
        // [P39] grafik vaqti, kechiktirib kiritish, yopish sababi
        if ($this->hasP39Columns()) {
            $out += [
                'scheduled_at'        => $r->scheduled_at?->format('Y-m-d H:i'),
                'late_entry'          => (bool) $r->late_entry,
                'received_entered_at' => $r->received_entered_at?->toDateTimeString(),
                'close_reason_id'     => $r->close_reason_id ? (int) $r->close_reason_id : null,
                'auto_closed'         => (bool) $r->auto_closed,
            ];
        }
        // [P30] laboratoriya guruhi
        if ($this->hasGroupColumns()) {
            $g = $r->laboratory_group_id ? $this->groupInfo((int) $r->laboratory_group_id) : null;
            $out['laboratory_group_id'] = $r->laboratory_group_id ? (int) $r->laboratory_group_id : null;
            $out['laboratory_group'] = $g;
        }
        // [P42] bir formadan guruhlar bo'yicha bo'lingan boshqa buyurtmalar
        if ($withItems && $this->hasItemGroups() && $r->getAttribute('split_key')) {
            $out['split'] = SampleRequest::where('split_key', $r->split_key)->where('id', '!=', $r->id)->orderBy('id')
                ->get(['id', 'number', 'status', 'laboratory_group_id'])
                ->map(fn ($x) => ['id' => (int) $x->id, 'number' => $x->number, 'status' => $x->status,
                    'group' => $x->laboratory_group_id ? $this->groupInfo((int) $x->laboratory_group_id) : null])->values();
        }

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

        $showGroupCode = $withGroupCode && $this->hasGroupColumns();
        $out['items'] = $items->map(function (SampleRequestItem $i) use ($elements, $showGroupCode) {
            $ids = array_map('intval', (array) ($i->element_ids ?? []));

            $gid = $i->getAttribute('laboratory_group_id') ? (int) $i->getAttribute('laboratory_group_id') : null; // [P42]

            return ($showGroupCode ? ['group_code' => $i->group_code] : []) + [
                'laboratory_group_id' => $gid,
                'group'           => $gid ? $this->groupInfo($gid) : null,
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

    /** @var array<int, array|null> */
    private array $groupCache = [];

    /** [P30] Guruh nomi (javob uchun) */
    public function groupInfo(int $id): ?array
    {
        if (! array_key_exists($id, $this->groupCache)) {
            $g = DB::table('laboratory_groups')->where('id', $id)->first(['id', 'code', 'name_uz', 'name_ru', 'name_en']);
            $this->groupCache[$id] = $g ? ['id' => (int) $g->id, 'code' => $g->code, 'name_uz' => $g->name_uz, 'name_ru' => $g->name_ru, 'name_en' => $g->name_en] : null;
        }

        return $this->groupCache[$id];
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
                    'group_ids' => $this->profileGroupIds((int) $p->id), // [P42] mas'ul guruhlar (faol, tartib bo'yicha)
                ])->values(),
            'item_groups' => $this->hasItemGroups(), // [P42] guruh — har namunada
            'units'       => QuantityUnit::UNITS,
            'expire_days' => (int) config('sample_requests.expire_days', 14),
            // [P28] namuna qaysi smenada olib kelinadi
            'shifts'      => Schema::hasTable('lab_shifts')
                ? LabShift::where('is_active', true)->orderBy('sort_order')->orderBy('start_time')->orderBy('id')
                    ->get(['id', 'name', 'start_time', 'end_time'])
                    ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'start_time' => $s->start_time, 'end_time' => $s->end_time, 'label' => self::shiftLabel($s)])
                    ->values()
                : [],
            // [P30] buyurtma qaysi guruhga yuboriladi (portal foydalanuvchisi /laboratory-groups ga kira olmaydi)
            'groups'          => Schema::hasTable('laboratory_groups')
                ? DB::table('laboratory_groups')->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                    ->get(['id', 'code', 'name_uz', 'name_ru', 'name_en'])
                    ->map(fn ($g) => ['id' => (int) $g->id, 'code' => $g->code, 'name_uz' => $g->name_uz, 'name_ru' => $g->name_ru, 'name_en' => $g->name_en])
                    ->values()
                : [],
            'group_required'  => $this->groupsRequired(),
            'reasons'         => $this->reasons(),                                       // [P39]
            'late_entry_hours'=> app(RequestSettings::class)->get()['late_entry_hours'],  // [P39]
        ];
    }
}
