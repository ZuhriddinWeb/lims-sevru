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
 *   tayyorlanadi. Laboratoriya namuna kelganini belgilaydi («Keldi») — ichkarida oddiy buyurtma (origin=schedule)
 *   yaratilib, mavjud qabul jarayoni bilan qabul qilinadi (proba raqami, elementlar, jamlanma hisobot, xodimlar
 *   ro'yxati — hammasi odatdagidek). Kelmagan namunalar rad etilgan bo'ladi. Buyurtmachiga xabar boradi.
 *
 *   Holatlar: planned (kutilmoqda) | skipped (bugun yo'q) | received | partial (qisman) | missed (kelmadi).
 *   «Kechikmoqda» — saqlanmaydi: planned + vaqtidan late_minutes o'tgan (ko'rsatishda hisoblanadi).
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
        if ($run->status !== 'planned') {
            return $run->status;
        }
        if ($run->run_date->lt(today())) {
            return 'missed';
        }
        $due = Carbon::parse($run->run_date->toDateString() . ' ' . $run->planned_time)->addMinutes($late ?? $this->lateMinutes((int) $run->customer_department_id));

        return $run->run_date->isToday() && now()->gte($due) ? 'late' : 'planned';
    }

    /* =============================================================== qabul («Keldi») */

    /**
     * @param array $data ['positions' => int[] (1-dan; bo'sh — hammasi), 'handed_over_by', 'handed_over_phone', 'received_by']
     */
    public function arrive(ScheduleRun $run, array $data, User $user): ScheduleRun
    {
        if ($run->status !== 'planned') {
            throw ValidationException::withMessages(['status' => ['Bu partiya allaqachon belgilangan']]);
        }
        if ($run->run_date->gt(today())) {
            throw ValidationException::withMessages(['status' => ['Kelgusi kun partiyasini oldindan qabul qilib bo\'lmaydi']]);
        }
        if (! $this->isEnabled((int) $run->customer_department_id)) {
            throw ValidationException::withMessages(['status' => ["Bu bo'lim uchun grafik rejimi o'chirilgan"]]);
        }
        $payload = is_array($run->payload) ? $run->payload : [];
        $items = array_values($payload['items'] ?? []);
        $n = count($items);
        $positions = array_values(array_unique(array_filter(array_map('intval', (array) ($data['positions'] ?? [])), fn ($p) => $p >= 1 && $p <= $n)));
        if (! array_key_exists('positions', $data) || $data['positions'] === null) {
            $positions = range(1, max(1, $n));
        }
        if (! $n || ! $positions) {
            throw ValidationException::withMessages(['positions' => ['Kelgan kamida bitta namunani belgilang']]);
        }
        $dept = CustomerDepartment::findOrFail($run->customer_department_id);

        // jamlanma hisobot davri — rejadagi vaqt bo'yicha (08:00 → shu kun II-navbatchilik va h.k.)
        $header = $payload;
        if (collect($items)->contains(fn ($i) => ! empty($i['svodka_point_id']))) {
            $svodka = app(SvodkaService::class);
            [$sd, $ss] = SvodkaService::periodOf(Carbon::parse($run->run_date->toDateString() . ' ' . $run->planned_time), $svodka->settings((int) $dept->id));
            $header += ['svodka_date' => $sd, 'svodka_shift' => $ss];
        }
        $header['delivery_date'] = $run->run_date->toDateString();
        $schedule = RequestSchedule::find($run->schedule_id);
        $header['notes'] = trim('Grafik: ' . $run->planned_time . ' «' . ($schedule?->name ?? '') . '»' . (($payload['notes'] ?? '') ? ' — ' . $payload['notes'] : ''));

        $this->requests->quiet = true;
        try {
            $req = DB::transaction(function () use ($dept, $header, $items, $positions, $data, $user, $run) {
                $req = $this->requests->create($dept, $header + ['items' => $items], $user, self::ORIGIN);
                $req = $this->requests->submit($req, $user);
                $byPos = $req->items->sortBy('position')->values();
                $rows = [];
                foreach ($positions as $p) {
                    $item = $byPos[$p - 1] ?? null;
                    if ($item) {
                        $rows[] = ['id' => $item->id, 'received_quantity' => $item->quantity, 'quantity_unit' => $item->quantity_unit];
                    }
                }
                $this->requests->receive($req, [
                    'items'             => $rows,
                    'handed_over_by'    => $data['handed_over_by'] ?? null,
                    'handed_over_phone' => $data['handed_over_phone'] ?? null,
                    'received_by'       => $data['received_by'] ?? null,
                ], $user);
                $req->refresh();
                if (in_array($req->status, SampleRequest::WAITING, true)) {
                    $this->requests->reject($req, $user, "Grafik bo'yicha kelmadi");
                }

                return $req;
            });
        } finally {
            $this->requests->quiet = false;
        }

        $received = count($positions);
        $missing = max(0, $n - $received);
        $run->forceFill([
            'status'            => $missing ? 'partial' : 'received',
            'sample_request_id' => $req->id,
            'arrived_at'        => now(),
            'received_by'       => $req->received_by ?: $user->id,
            'received_count'    => $received,
            'missing_count'     => $missing,
            'updated_by'        => $user->id,
        ])->save();
        $this->log($user, $run, "Grafik bo'yicha namunalar qabul qilindi: {$dept->name}, {$run->run_date->format('d.m.Y')} {$run->planned_time} — {$received} ta"
            . ($missing ? ", {$missing} tasi kelmadi" : '') . " (buyurtma {$req->number})");
        $this->notifyCustomerReceived($run->fresh(), $req);

        return $run->fresh();
    }

    /* =============================================================== buyurtmachi: shu kun */

    public function skip(ScheduleRun $run, User $user, ?string $note): ScheduleRun
    {
        $this->assertEditable($run);
        $run->forceFill(['status' => 'skipped', 'note' => $note ? mb_substr($note, 0, 500) : null, 'updated_by' => $user->id])->save();
        $this->log($user, $run, "Grafik partiyasi bekor qilindi (bugun yo'q): {$run->run_date->format('d.m.Y')} {$run->planned_time}");
        $this->notifyLab($run, 'skipped');

        return $run;
    }

    public function unskip(ScheduleRun $run, User $user): ScheduleRun
    {
        if ($run->status !== 'skipped' || $run->run_date->lt(today())) {
            throw ValidationException::withMessages(['status' => ['Faqat bugungi yoki kelgusi bekor qilingan partiyani qaytarish mumkin']]);
        }
        $run->forceFill(['status' => 'planned', 'note' => null, 'updated_by' => $user->id])->save();
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
        if ($run->status !== 'planned' || $run->run_date->lt(today())) {
            throw ValidationException::withMessages(['status' => ["Faqat hali kelmagan bugungi yoki kelgusi partiyani o'zgartirish mumkin"]]);
        }
    }

    /* =============================================================== rejalashtirgich */

    /** Har 5 daqiqada: bugungi partiyalar, kechikish xabari, o'tgan kunlarning kelmaganlari */
    public function tick(): array
    {
        if (! self::available()) {
            return ['created' => 0, 'late' => 0, 'missed' => 0];
        }
        $before = ScheduleRun::count();
        $this->ensureRuns(today());
        $created = ScheduleRun::count() - $before;

        $missed = ScheduleRun::where('status', 'planned')->whereDate('run_date', '<', today()->toDateString())
            ->update(['status' => 'missed', 'updated_at' => now()]);

        $late = 0;
        $runs = ScheduleRun::where('status', 'planned')->whereDate('run_date', today()->toDateString())->whereNull('late_notified_at')->get();
        $lateBy = [];
        foreach ($runs as $run) {
            $dept = (int) $run->customer_department_id;
            $lateBy[$dept] ??= $this->lateMinutes($dept);
            if ($this->displayStatus($run, $lateBy[$dept]) === 'late') {
                $run->forceFill(['late_notified_at' => now()])->save();
                $this->notifyLab($run, 'late');
                $this->notifyCustomerLate($run);
                $late++;
            }
        }

        return ['created' => $created, 'late' => $late, 'missed' => $missed];
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
            'arrived_at'     => $run->arrived_at?->toDateTimeString(),
            'received_by'    => $run->received_by ? User::withTrashed()->find($run->received_by)?->name : null,
            'received_count' => (int) $run->received_count,
            'missing_count'  => (int) $run->missing_count,
            'note'           => $run->note,
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

    private function notifyCustomerReceived(ScheduleRun $run, SampleRequest $req): void
    {
        try {
            $s = RequestSchedule::find($run->schedule_id);
            $who = $run->received_by ? User::find($run->received_by)?->name : null;
            $title = "Grafik {$run->planned_time}: {$run->received_count} ta namunangiz qabul qilindi";
            $body = "«" . ($s?->name ?? '') . "», " . $run->run_date->format('d.m.Y') . ' — qabul: ' . $run->arrived_at?->format('H:i')
                . ($who ? ", qabul qilib oldi: {$who}" : '') . '.'
                . ($run->missing_count ? " {$run->missing_count} tasi kelmadi." : '') . " Natijalar «Grafigim» sahifasida.";
            foreach ($this->customerUsers((int) $run->customer_department_id) as $u) {
                Notification::create([
                    'user_id' => $u->id, 'type' => 'sample', 'level' => $run->missing_count ? 'warning' : 'info',
                    'title' => mb_substr($title, 0, 191), 'body' => $body, 'link_route' => 'portal-schedule-run', 'link_id' => $run->id,
                    'dedup_key' => "sched-recv-{$run->id}-{$u->id}",
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
                    'body' => "«" . ($s?->name ?? '') . "» (" . count($run->payload['items'] ?? []) . " ta) laboratoriyaga hali yetib kelmadi. Bugun bo'lmasa — «Grafigim» da «Bugun yo'q» ni belgilang.",
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
                if ($this->portal->isPortalUser($u) || ! ($u->hasPermission('schedules.receive') || $u->isAdmin())) {
                    continue;
                }
                if ($gid && $u->laboratory_group_id && (int) $u->laboratory_group_id !== $gid && ! SampleRequests::seesAllGroups($u)) {
                    continue;
                }
                Notification::create([
                    'user_id' => $u->id, 'type' => 'sample', 'level' => $event === 'late' ? 'warning' : 'info',
                    'title' => mb_substr($title, 0, 191), 'body' => $body, 'link_route' => 'lab-schedule', 'link_id' => $run->id,
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
