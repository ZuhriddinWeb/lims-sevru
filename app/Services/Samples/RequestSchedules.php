<?php

namespace App\Services\Samples;

use App\Models\ActivityLog;
use App\Models\CustomerDepartment;
use App\Models\Element;
use App\Models\Notification;
use App\Models\RequestSchedule;
use App\Models\SampleRequest;
use App\Models\ScheduleRun;
use App\Models\User;
use App\Services\Svodka\SvodkaService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * [Paket 36] Grafik bo'yicha namunalar.
 *
 *   Buyurtmachi grafik tuzadi: «08:00 — 20 ta namuna, ish kunlari». Har kuni shu qatordan partiya (schedule_run)
 *   tayyorlanadi. [P39] Grafik vaqtidan N daqiqa oldin (sozlama) partiyadan ODDIY buyurtma (origin=schedule)
 *   yaratilib yuboriladi — laboratoriya uni «Buyurtmalar» da odatdagidek qabul qiladi (qisman qabul, kim olib keldi,
 *   kechiktirib kiritish, sabab bilan yopish — hammasi umumiy). Shifr: grafikdagi shifr + «-» + vaqt.
 *
 *   Holatlar: planned (buyurtma hali yaratilmagan) | ordered (buyurtma kutilmoqda) | skipped (bugun yo'q) |
 *   received | partial (qisman) | missed (kelmadi). «Kechikmoqda» — saqlanmaydi (ko'rsatishda hisoblanadi).
 *   Imkoniyat ixtiyoriy: laboratoriya har bo'linma uchun yoqadi (customer_departments.schedule_enabled).
 */
class RequestSchedules
{
    public const ORIGIN = 'schedule';
    public const MAX_ITEMS = 200;

    public function __construct(private SampleRequests $requests, private CustomerPortal $portal)
    {
    }

    public static function available(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('request_schedules') && Schema::hasTable('schedule_runs')
            && Schema::hasColumn('customer_departments', 'schedule_enabled');
    }

    public function isEnabled(?int $deptId): bool
    {
        return $deptId && self::available()
            && CustomerDepartment::whereKey($deptId)->where('is_active', true)->where('schedule_enabled', true)->exists();
    }

    /** @return int[] */
    public function enabledDepartmentIds(): array
    {
        if (! self::available()) {
            return [];
        }

        return CustomerDepartment::where('is_active', true)->where('schedule_enabled', true)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function lateMinutes(int $deptId): int
    {
        return max(5, (int) (CustomerDepartment::whereKey($deptId)->value('schedule_late_minutes') ?: 30));
    }

    /* =============================================================== tekshiruvlar */

    public static function normalizeTime(?string $t): ?string
    {
        if (! preg_match('/^\s*(\d{1,2})[:.](\d{2})\s*$/', (string) $t, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];

        return $h <= 23 && $i <= 59 ? sprintf('%02d:%02d', $h, $i) : null;
    }

    /** @param mixed $days */
    public static function normalizeDays($days): string
    {
        $d = array_values(array_unique(array_filter(array_map('intval', (array) $days), fn ($v) => $v >= 1 && $v <= 7)));
        sort($d);

        return implode(',', $d);
    }

    /** Shablondagi kabi: namunalar va sarlavha — tozalangan (nuqta faqat shu bo'limniki) */
    public function cleanPayload(int $deptId, array $p): array
    {
        $str = fn ($v, $max) => ($v = trim((string) ($v ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $int = fn ($v) => (int) ($v ?? 0) ?: null;
        $pointIds = Schema::hasTable('svodka_points')
            ? DB::table('svodka_points')->where('customer_department_id', $deptId)->pluck('id')->map(fn ($v) => (int) $v)->flip()
            : collect();
        $activeEls = Element::where('is_active', true)->pluck('id')->map(fn ($v) => (int) $v)->flip();

        $errors = [];
        $items = [];
        foreach (array_values($p['items'] ?? []) as $i => $it) {
            $point = $int($it['svodka_point_id'] ?? null);
            $qty = $it['quantity'] ?? null;
            $els = array_values(array_unique(array_filter(array_map('intval', (array) ($it['element_ids'] ?? [])), fn ($e) => $activeEls->has($e))));
            $profile = $int($it['test_profile_id'] ?? null);
            if (! $els && ! $profile) {
                $errors["payload.items.$i.element_ids"][] = ($i + 1) . "-namuna: nimani aniqlash kerakligini tanlang";
            }
            $unit = $it['quantity_unit'] ?? 'g';
            $items[] = [
                'customer_code'   => $str($it['customer_code'] ?? null, 64),
                'state_of_matter' => in_array($it['state_of_matter'] ?? null, SampleRequests::STATES, true) ? $it['state_of_matter'] : 'solid',
                'test_profile_id' => $profile,
                'element_ids'     => $els,
                'quantity'        => $qty === null || $qty === '' || ! is_numeric($qty) || (float) $qty <= 0 ? null : (float) $qty,
                'quantity_unit'   => in_array($unit, QuantityUnit::UNITS, true) ? $unit : 'g',
                'notes'           => $str($it['notes'] ?? null, 500),
                'svodka_point_id' => $point && $pointIds->has($point) ? $point : null,
                'group_code'      => $str($it['group_code'] ?? null, 64),
            ];
        }
        if (! $items) {
            $errors['payload.items'][] = "Kamida bitta namuna qo'shing";
        }
        if (count($items) > self::MAX_ITEMS) {
            $errors['payload.items'][] = 'Namunalar ' . self::MAX_ITEMS . ' tadan oshmasin';
        }
        $group = $int($p['laboratory_group_id'] ?? null);
        if ($this->requests->hasGroupColumns() && DB::table('laboratory_groups')->where('is_active', true)->exists()) {
            if (! $group || ! DB::table('laboratory_groups')->where('id', $group)->where('is_active', true)->exists()) {
                $errors['payload.laboratory_group_id'][] = 'Laboratoriya guruhini tanlang';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'laboratory_group_id' => $group,
            'contact_name'        => $str($p['contact_name'] ?? null, 191),
            'contact_phone'       => $str($p['contact_phone'] ?? null, 64),
            'notes'               => $str($p['notes'] ?? null, 2000),
            'items'               => $items,
        ];
    }

    /* =============================================================== partiyalar */

    /** Shu kunga partiyalarni tayyorlash (bo'lsa — tegilmaydi). Faqat bugun va kelgusi 14 kun. */
    public function ensureRuns(Carbon|string $date, ?array $deptIds = null): void
    {
        if (! self::available()) {
            return;
        }
        $d = Carbon::parse($date)->startOfDay();
        if ($d->lt(today()) || $d->gt(today()->addDays(14))) {
            return;
        }
        $enabled = $this->enabledDepartmentIds();
        $ids = $deptIds === null ? $enabled : array_values(array_intersect($deptIds, $enabled));
        if (! $ids) {
            return;
        }
        $dow = (string) $d->isoWeekday();
        $have = ScheduleRun::whereDate('run_date', $d->toDateString())->pluck('schedule_id')->map(fn ($v) => (int) $v)->flip();
        $schedules = RequestSchedule::whereIn('customer_department_id', $ids)->where('is_active', true)->get();
        foreach ($schedules as $s) {
            if ($have->has((int) $s->id) || ! in_array((int) $dow, $s->days(), true)) {
                continue;
            }
            try {
                ScheduleRun::create([
                    'schedule_id'            => $s->id,
                    'customer_department_id' => $s->customer_department_id,
                    'run_date'               => $d->toDateString(),
                    'planned_time'           => $s->planned_time,
                    'status'                 => 'planned',
                    'payload'                => $s->payload,
                ]);
            } catch (Throwable $e) {
                // bir vaqtda ikki so'rov (unikal kalit) — ikkinchisi e'tiborsiz
            }
        }
    }

    /** Grafik o'zgarsa: bugungi/kelgusi, shu kunga o'zgartirilmagan, hali kelmagan partiyalarni yangilash */
    public function syncFutureRuns(RequestSchedule $s): void
    {
        $q = ScheduleRun::where('schedule_id', $s->id)->where('status', 'planned')
            ->whereDate('run_date', '>=', today()->toDateString());
        if (! $s->is_active) {
            $q->delete();

            return;
        }
        foreach ($q->get() as $run) {
            if (! in_array($run->run_date->isoWeekday(), $s->days(), true)) {
                $run->delete();
                continue;
            }
            $run->forceFill(['planned_time' => $s->planned_time] + ($run->customized ? [] : ['payload' => $s->payload]))->save();
        }
    }

    /** Ko'rsatish holati: planned → late (vaqti o'tdi) yoki o'tgan kun — missed */
    public function displayStatus(ScheduleRun $run, ?int $late = null): string
    {
        if (! in_array($run->status, ['planned', 'ordered'], true)) {
            return $run->status;
        }
        if ($run->status === 'planned' && $run->run_date->lt(today())) {
            return 'missed';
        }
        $due = Carbon::parse($run->run_date->toDateString() . ' ' . $run->planned_time)->addMinutes($late ?? $this->lateMinutes((int) $run->customer_department_id));

        return now()->gte($due) ? 'late' : 'planned';
    }

    /* =============================================================== [P39] avtomatik buyurtma */

    /** Grafik vaqti (sana + soat) */
    public static function plannedAt(ScheduleRun $run): Carbon
    {
        return Carbon::parse($run->run_date->toDateString() . ' ' . $run->planned_time);
    }

    /**
     * Shifr: grafikdagi shifr — prefiks, unga «-» va grafik vaqti qo'shiladi («Xvost» → «Xvost-08:00»; bo'sh — «08:00»).
     * Bir qatorda bir xil prefiks takrorlansa — «Xvost-08:00/1», «Xvost-08:00/2».
     *
     * @return string[] har namuna uchun (tartib bo'yicha)
     */
    public static function codesFor(array $items, string $time): array
    {
        $prefixes = array_map(fn ($it) => trim((string) ($it['customer_code'] ?? '')), array_values($items));
        $count = array_count_values($prefixes);
        $seen = [];
        $out = [];
        foreach ($prefixes as $p) {
            $code = $p === '' ? $time : "{$p}-{$time}";
            if (($count[$p] ?? 0) > 1) {
                $seen[$p] = ($seen[$p] ?? 0) + 1;
                $code .= '/' . $seen[$p];
            }
            $out[] = mb_substr($code, 0, 64);
        }

        return $out;
    }

    /** Grafik buyurtmasi qachon «Muddati o'tdi» bo'ladi (sozlama: kun oxiri / smena oxiri / keyingi grafik vaqti / N soat) */
    public function expiresAt(ScheduleRun $run, ?array $set = null): Carbon
    {
        $set ??= app(RequestSettings::class)->forDepartment(CustomerDepartment::find($run->customer_department_id));
        $at = self::plannedAt($run);
        $dayEnd = $run->run_date->copy()->endOfDay();
        $res = match ($set['schedule_expire_mode']) {
            'hours'     => $at->copy()->addHours(max(1, (int) $set['schedule_expire_hours'])),
            'shift_end' => $this->shiftEnd($at) ?? $dayEnd,
            'next_run'  => $this->nextRunAt($run) ?? $dayEnd,
            default     => $dayEnd,
        };
        // kechikish chegarasidan oldin «muddati o'tdi» bo'lmasin
        $min = $at->copy()->addMinutes($this->lateMinutes((int) $run->customer_department_id));

        return $res->lt($min) ? $min : $res;
    }

    /** Grafik vaqti tushgan smenaning oxiri (tungi smena — ertasi kuni) */
    private function shiftEnd(Carbon $at): ?Carbon
    {
        if (! Schema::hasTable('lab_shifts')) {
            return null;
        }
        $t = $at->format('H:i');
        foreach (DB::table('lab_shifts')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['start_time', 'end_time']) as $sh) {
            $st = substr((string) $sh->start_time, 0, 5);
            $en = substr((string) $sh->end_time, 0, 5);
            if ($st === '' || $en === '') {
                continue;
            }
            if ($st < $en && $t >= $st && $t < $en) {
                return Carbon::parse($at->toDateString() . " $en");
            }
            if ($st >= $en && ($t >= $st || $t < $en)) { // tunda o'tadigan smena
                $end = Carbon::parse($at->toDateString() . " $en");

                return $t >= $st ? $end->addDay() : $end;
            }
        }

        return null;
    }

    /** Shu bo'linmaning shu kundagi keyingi grafik vaqti */
    private function nextRunAt(ScheduleRun $run): ?Carbon
    {
        $dow = $run->run_date->isoWeekday();
        $next = RequestSchedule::where('customer_department_id', $run->customer_department_id)->where('is_active', true)
            ->where('planned_time', '>', $run->planned_time)->orderBy('planned_time')->get()
            ->first(fn ($s) => in_array($dow, $s->days(), true));

        return $next ? Carbon::parse($run->run_date->toDateString() . ' ' . $next->planned_time) : null;
    }

    /** Buyurtmani kim nomidan yaratamiz: grafikni oxirgi saqlagan (yoki tuzgan) buyurtmachi */
    private function author(ScheduleRun $run): ?User
    {
        $s = RequestSchedule::withTrashed()->find($run->schedule_id);
        foreach ([$s?->updated_by, $s?->created_by] as $id) {
            if ($id && ($u = User::withTrashed()->find($id))) {
                return $u;
            }
        }

        return User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->orderBy('id')->first();
    }

    /**
     * Partiyadan oddiy buyurtma yaratadi va yuboradi (laboratoriya «Buyurtmalar» ro'yxatiga tushadi).
     * Bir marta: partiyada buyurtma bo'lsa — o'sha qaytadi.
     */
    public function materialize(ScheduleRun $run): ?SampleRequest
    {
        if ($run->sample_request_id) {
            return SampleRequest::find($run->sample_request_id);
        }
        if ($run->status !== 'planned' || ! $this->isEnabled((int) $run->customer_department_id)) {
            return null;
        }
        $payload = is_array($run->payload) ? $run->payload : [];
        $items = array_values($payload['items'] ?? []);
        $user = $this->author($run);
        if (! $items || ! $user) {
            return null;
        }
        $dept = CustomerDepartment::findOrFail($run->customer_department_id);
        $at = self::plannedAt($run);

        $codes = self::codesFor($items, $run->planned_time);
        foreach ($items as $i => $it) {
            $items[$i]['customer_code'] = $codes[$i];
        }
        $header = $payload;
        unset($header['items']);
        // jamlanma hisobot davri — grafik vaqti bo'yicha (08:00 → shu kun II-navbatchilik va h.k.)
        if (collect($items)->contains(fn ($i) => ! empty($i['svodka_point_id']))) {
            $svodka = app(SvodkaService::class);
            [$sd, $ss] = SvodkaService::periodOf($at, $svodka->settings((int) $dept->id));
            $header += ['svodka_date' => $sd, 'svodka_shift' => $ss];
        }
        $header['delivery_date'] = $run->run_date->toDateString();
        $schedule = RequestSchedule::withTrashed()->find($run->schedule_id);
        $header['notes'] = trim('Grafik: ' . $run->planned_time . ' «' . ($schedule?->name ?? '') . '»' . (($payload['notes'] ?? '') ? ' — ' . $payload['notes'] : ''));

        $this->requests->quiet = true; // har grafik uchun «yangi buyurtma» xabari yuborilmaydi — ro'yxatda ko'rinadi
        try {
            $req = DB::transaction(function () use ($dept, $header, $items, $user, $run, $at) {
                $fresh = ScheduleRun::whereKey($run->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->sample_request_id || $fresh->status !== 'planned') {
                    return $fresh?->sample_request_id ? SampleRequest::find($fresh->sample_request_id) : null;
                }
                $req = $this->requests->create($dept, $header + ['items' => $items], $user, self::ORIGIN);
                $req = $this->requests->submit($req, $user);
                $req->forceFill(['scheduled_at' => $at, 'expires_at' => $this->expiresAt($run)])->save();
                $fresh->forceFill(['sample_request_id' => $req->id, 'status' => 'ordered'])->save();

                return $req;
            });
        } catch (ValidationException $e) {
            // masalan, guruh o'chirilgan — buyurtmachiga xabar, partiya «planned» bo'lib qoladi
            report($e);
            $this->notifyCustomerProblem($run, collect($e->errors())->flatten()->first() ?? 'xato');

            return null;
        } finally {
            $this->requests->quiet = false;
        }
        if ($req) {
            $this->log(null, $run, "Grafik buyurtmasi yaratildi: {$dept->name}, {$run->run_date->format('d.m.Y')} {$run->planned_time} — {$req->number}");
        }

        return $req;
    }

    /**
     * Buyurtma holati o'zgarganda partiya holati (hisobot va «Grafigim» uchun).
     *   submitted → ordered, partial/received → partial|received, expired|rejected → missed, cancelled → skipped
     */
    public function syncRun(SampleRequest $req): void
    {
        $run = ScheduleRun::where('sample_request_id', $req->id)->first();
        if (! $run) {
            return;
        }
        $counts = DB::table('sample_request_items')->where('sample_request_id', $req->id)
            ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $recv = (int) ($counts['received'] ?? 0);
        $all = (int) $counts->sum();
        $status = match ($req->status) {
            'submitted'           => 'ordered',
            'partial', 'received' => $recv && $recv < $all ? 'partial' : ($recv ? 'received' : 'missed'),
            'cancelled'           => $req->auto_closed ? 'missed' : 'skipped',
            default               => 'missed', // expired, rejected
        };
        $run->forceFill([
            'status'         => $status,
            'arrived_at'     => $req->received_at,
            'received_by'    => $req->received_by,
            'received_count' => $recv,
            'missing_count'  => in_array($req->status, ['partial', 'submitted'], true) ? 0 : max(0, $all - $recv),
            'reason_id'      => $req->close_reason_id ?: $run->reason_id,
            'note'           => $status === 'skipped' ? mb_substr((string) $req->close_reason, 0, 500) : $run->note,
        ])->save();
    }

    /* =============================================================== buyurtmachi: shu kun */

    /**
     * «Bugun yo'q» — sabab (ro'yxatdan) bilan. Buyurtma allaqachon yaratilgan bo'lsa — u sabab bilan yopiladi.
     */
    public function skip(ScheduleRun $run, User $user, ?string $note, ?int $reasonId = null): ScheduleRun
    {
        if ($run->sample_request_id) {
            $req = SampleRequest::findOrFail($run->sample_request_id);
            $this->requests->cancel($req, $user, $note, $reasonId); // syncRun o'zi chaqiriladi
            $run->refresh();
            $this->notifyLab($run, 'skipped');

            return $run;
        }
        $this->assertEditable($run);
        [$reasonId, $text] = $this->requests->resolveReason($reasonId, $note, true);
        $run->forceFill(['status' => 'skipped', 'note' => $text, 'reason_id' => $reasonId, 'updated_by' => $user->id])->save();
        $this->log($user, $run, "Grafik partiyasi bekor qilindi (bugun yo'q): {$run->run_date->format('d.m.Y')} {$run->planned_time}" . ($text ? " — {$text}" : ''));
        $this->notifyLab($run, 'skipped');

        return $run;
    }

    public function unskip(ScheduleRun $run, User $user): ScheduleRun
    {
        if ($run->status !== 'skipped' || $run->run_date->lt(today()) || $run->sample_request_id) {
            throw ValidationException::withMessages(['status' => ["Faqat buyurtmasi hali yaratilmagan, bugungi yoki kelgusi bekor qilingan partiyani qaytarish mumkin"]]);
        }
        $run->forceFill(['status' => 'planned', 'note' => null, 'reason_id' => null, 'updated_by' => $user->id])->save();
        $this->log($user, $run, "Grafik partiyasi qayta tiklandi: {$run->run_date->format('d.m.Y')} {$run->planned_time}");

        return $run;
    }

    public function customize(ScheduleRun $run, array $payload, User $user): ScheduleRun
    {
        $this->assertEditable($run);
        $run->forceFill(['payload' => $this->cleanPayload((int) $run->customer_department_id, $payload), 'customized' => true, 'updated_by' => $user->id])->save();
        $this->log($user, $run, "Grafik partiyasi shu kun uchun o'zgartirildi: {$run->run_date->format('d.m.Y')} {$run->planned_time}");

        return $run;
    }

    public function reset(ScheduleRun $run, User $user): ScheduleRun
    {
        $this->assertEditable($run);
        $s = RequestSchedule::findOrFail($run->schedule_id);
        $run->forceFill(['payload' => $s->payload, 'customized' => false, 'updated_by' => $user->id])->save();

        return $run;
    }

    private function assertEditable(ScheduleRun $run): void
    {
        if ($run->sample_request_id) {
            throw ValidationException::withMessages(['status' => ["Bu partiya bo'yicha buyurtma allaqachon yaratilgan — o'zgartirib bo'lmaydi. Kerak bo'lmasa «Bugun yo'q» (sabab bilan)"]]);
        }
        if ($run->status !== 'planned' || $run->run_date->lt(today())) {
            throw ValidationException::withMessages(['status' => ["Faqat hali kelmagan bugungi yoki kelgusi partiyani o'zgartirish mumkin"]]);
        }
    }

    /* =============================================================== rejalashtirgich */

    /**
     * Har 5 daqiqada: partiyalar, avtomatik buyurtmalar (grafik vaqtidan N daqiqa oldin), muddati o'tganlar,
     * kechikish xabari, o'tgan kunlarning buyurtmasiz qolgan partiyalari.
     */
    public function tick(): array
    {
        if (! self::available()) {
            return ['created' => 0, 'ordered' => 0, 'late' => 0, 'missed' => 0];
        }
        $before = ScheduleRun::count();
        $this->ensureRuns(today());
        $this->ensureRuns(today()->addDay()); // yarim tundan keyingi grafik — oldingi kun kechqurun yaratilishi mumkin
        $created = ScheduleRun::count() - $before;

        // avtomatik buyurtma
        $ordered = 0;
        $settings = [];
        $runs = ScheduleRun::where('status', 'planned')->whereNull('sample_request_id')
            ->whereDate('run_date', '>=', today()->toDateString())->whereDate('run_date', '<=', today()->addDay()->toDateString())
            ->orderBy('run_date')->orderBy('planned_time')->get();
        foreach ($runs as $run) {
            $dept = (int) $run->customer_department_id;
            $settings[$dept] ??= app(RequestSettings::class)->forDepartment(CustomerDepartment::find($dept));
            try {
                if (now()->gte(self::plannedAt($run)->subMinutes($settings[$dept]['schedule_lead_minutes'])) && $this->materialize($run)) {
                    $ordered++;
                }
            } catch (Throwable $e) {
                report($e); // bitta partiya xatosi qolganlarini to'xtatmasin
            }
        }

        // muddati o'tganlar (grafik buyurtmasi smena oxiri va h.k. — soatlik kutmasdan)
        $this->requests->expireOverdue();

        // o'tgan kun, buyurtmasi yaratilmay qolgan (rejalashtirgich ishlamagan / grafik o'chirilgan)
        $missed = ScheduleRun::where('status', 'planned')->whereNull('sample_request_id')->whereDate('run_date', '<', today()->toDateString())
            ->update(['status' => 'missed', 'updated_at' => now()]);

        // kechikish xabari: buyurtma kutilmoqda, grafik vaqtidan late_minutes o'tdi
        $late = 0;
        $lateBy = [];
        foreach (ScheduleRun::where('status', 'ordered')->whereNull('late_notified_at')->whereDate('run_date', '<=', today()->toDateString())->get() as $run) {
            $dept = (int) $run->customer_department_id;
            $lateBy[$dept] ??= $this->lateMinutes($dept);
            if ($this->displayStatus($run, $lateBy[$dept]) === 'late') {
                $run->forceFill(['late_notified_at' => now()])->save();
                $this->notifyLab($run, 'late');
                $this->notifyCustomerLate($run);
                $late++;
            }
        }

        return ['created' => $created, 'ordered' => $ordered, 'late' => $late, 'missed' => $missed];
    }

    /* =============================================================== ko'rinish */

    /**
     * @param bool $forLab laboratoriya uchun — nuqta (svodka) ko'rsatilmaydi (tahlil xolis bo'lsin)
     */
    public function presentRun(ScheduleRun $run, bool $forLab, ?Collection $schedules = null, ?Collection $elements = null, ?Collection $points = null): array
    {
        $p = is_array($run->payload) ? $run->payload : [];
        $s = $schedules?->get((int) $run->schedule_id) ?? RequestSchedule::find($run->schedule_id);
        $elements ??= Element::pluck('symbol', 'id');
        $items = array_map(function ($it) use ($forLab, $elements, $points) {
            $out = [
                'customer_code'   => $it['customer_code'] ?? null,
                'state_of_matter' => $it['state_of_matter'] ?? 'solid',
                'elements'        => array_values(array_filter(array_map(fn ($e) => $elements[(int) $e] ?? null, (array) ($it['element_ids'] ?? [])))),
                'test_profile_id' => $it['test_profile_id'] ?? null,
                'quantity'        => $it['quantity'] ?? null,
                'quantity_unit'   => $it['quantity_unit'] ?? null,
            ];
            if (! $forLab) {
                $out['svodka_point'] = ! empty($it['svodka_point_id']) ? ($points?->get((int) $it['svodka_point_id']) ?? null) : null;
            }

            return $out;
        }, array_values($p['items'] ?? []));

        return [
            'id'             => (int) $run->id,
            'schedule_id'    => (int) $run->schedule_id,
            'name'           => $s?->name,
            'department'     => $run->department ? ['id' => (int) $run->department->id, 'name' => $run->department->name] : null,
            'date'           => $run->run_date->toDateString(),
            'time'           => $run->planned_time,
            'status'         => $this->displayStatus($run),
            'customized'     => (bool) $run->customized,
            'items_count'    => count($items),
            'items'          => $items,
            'payload'        => $forLab ? null : $p,
            'contact_name'   => $p['contact_name'] ?? null,
            'contact_phone'  => $p['contact_phone'] ?? null,
            'group_id'       => $p['laboratory_group_id'] ?? null,
            'request_id'     => $run->sample_request_id ? (int) $run->sample_request_id : null,
            'request_number' => $run->sample_request_id ? SampleRequest::whereKey($run->sample_request_id)->value('number') : null,
            'codes'          => self::codesFor($p['items'] ?? [], $run->planned_time), // [P39] buyurtmadagi shifrlar
            'arrived_at'     => $run->arrived_at?->toDateTimeString(),
            'received_by'    => $run->received_by ? User::withTrashed()->find($run->received_by)?->name : null,
            'received_count' => (int) $run->received_count,
            'missing_count'  => (int) $run->missing_count,
            'note'           => $run->note,
            'reason_id'      => $run->reason_id ? (int) $run->reason_id : null,
            'order_at'       => $run->sample_request_id ? null
                : self::plannedAt($run)->subMinutes(app(RequestSettings::class)->forDepartment($run->department)['schedule_lead_minutes'])->format('Y-m-d H:i'),
        ];
    }

    public function presentSchedule(RequestSchedule $s): array
    {
        $p = is_array($s->payload) ? $s->payload : [];

        return [
            'id'            => (int) $s->id,
            'department_id' => (int) $s->customer_department_id,
            'name'          => $s->name,
            'time'          => $s->planned_time,
            'weekdays'      => $s->days(),
            'is_active'     => (bool) $s->is_active,
            'payload'       => $p,
            'items_count'   => count($p['items'] ?? []),
            'updated_at'    => $s->updated_at?->toDateTimeString(),
        ];
    }

    /* =============================================================== xabarlar */

    /** Bo'linmaning faol buyurtmachi foydalanuvchilari */
    private function customerUsers(int $deptId): Collection
    {
        if (! Schema::hasTable('customer_department_user')) {
            return collect();
        }
        $ids = DB::table('customer_department_user')->where('customer_department_id', $deptId)->pluck('user_id');

        return User::whereIn('id', $ids)->where('is_active', true)->get()->filter(fn ($u) => $this->portal->isPortalUser($u));
    }

    /** Avtomatik buyurtma yaratilmadi (masalan, guruh o'chirilgan) — buyurtmachiga */
    private function notifyCustomerProblem(ScheduleRun $run, string $why): void
    {
        try {
            foreach ($this->customerUsers((int) $run->customer_department_id) as $u) {
                Notification::create([
                    'user_id' => $u->id, 'type' => 'sample', 'level' => 'warning',
                    'title' => "Grafik {$run->planned_time}: buyurtma yaratilmadi",
                    'body' => "Sabab: {$why}. «Grafigim» da grafik qatorini tuzating.",
                    'link_route' => 'portal-schedule-run', 'link_id' => $run->id, 'dedup_key' => "sched-fail-{$run->id}-{$u->id}",
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function notifyCustomerLate(ScheduleRun $run): void
    {
        try {
            $s = RequestSchedule::find($run->schedule_id);
            foreach ($this->customerUsers((int) $run->customer_department_id) as $u) {
                Notification::create([
                    'user_id' => $u->id, 'type' => 'sample', 'level' => 'warning',
                    'title' => "Grafik {$run->planned_time}: namunalar hali kelmadi",
                    'body' => "«" . ($s?->name ?? '') . "» (" . count($run->payload['items'] ?? []) . " ta) laboratoriyaga hali yetib kelmadi. Bugun bo'lmasa — «Grafigim» da «Bugun yo'q» ni (sabab bilan) belgilang.",
                    'link_route' => 'portal-schedule-run', 'link_id' => $run->id, 'dedup_key' => "sched-late-c-{$run->id}-{$u->id}",
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Laboratoriyaga: kechikish yoki buyurtmachi bekor qildi */
    private function notifyLab(ScheduleRun $run, string $event): void
    {
        try {
            $dept = CustomerDepartment::find($run->customer_department_id)?->name ?? '';
            $s = RequestSchedule::find($run->schedule_id);
            $p = is_array($run->payload) ? $run->payload : [];
            $gid = (int) ($p['laboratory_group_id'] ?? 0);
            $count = count($p['items'] ?? []);
            [$title, $body] = $event === 'late'
                ? ["Grafik {$run->planned_time}: {$dept} — namunalar kelmadi", "«" . ($s?->name ?? '') . "», {$count} ta namuna vaqtida kelmadi."]
                : ["Grafik {$run->planned_time}: {$dept} — bugun yo'q", "Buyurtmachi «" . ($s?->name ?? '') . "» partiyasini bugun bekor qildi" . ($run->note ? ": {$run->note}" : '.')];
            foreach (User::where('is_active', true)->get() as $u) {
                if ($this->portal->isPortalUser($u) || ! ($u->hasPermission('sample_requests.receive') || $u->isAdmin())) {
                    continue;
                }
                if ($gid && $u->laboratory_group_id && (int) $u->laboratory_group_id !== $gid && ! SampleRequests::seesAllGroups($u)) {
                    continue;
                }
                Notification::create([
                    'user_id' => $u->id, 'type' => 'sample', 'level' => $event === 'late' ? 'warning' : 'info',
                    'title' => mb_substr($title, 0, 191), 'body' => $body,
                    'link_route' => $run->sample_request_id ? 'sample-request' : 'sample-requests', 'link_id' => $run->sample_request_id ?: null,
                    'dedup_key' => "sched-{$event}-{$run->id}-{$u->id}",
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function log(?User $user, $model, string $text): void
    {
        try {
            ActivityLog::create([
                'user_id' => $user?->id, 'action' => 'updated', 'model_type' => class_basename($model), 'model_id' => $model->id,
                'description' => mb_substr($text, 0, 255), 'properties' => null,
                'ip_address' => app()->runningInConsole() ? null : request()->ip(), 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
