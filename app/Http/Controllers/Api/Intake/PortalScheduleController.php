<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\Element;
use App\Models\RequestSchedule;
use App\Models\ScheduleRun;
use App\Services\Samples\CustomerPortal;
use App\Services\Samples\RequestSchedules;
use App\Services\Samples\SampleRequests;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * [Paket 36] Buyurtmachi — «Grafigim»: grafik qatorlari va har kungi partiyalar.
 *
 *   GET    /api/portal/schedules                    grafik qatorlari (+ grafik yoqilgan bo'limlar)
 *   POST   /api/portal/schedules                    {department_id, name, time, weekdays[], is_active, payload}
 *   PUT    /api/portal/schedules/{id}
 *   DELETE /api/portal/schedules/{id}
 *   GET    /api/portal/schedule-runs?date=          shu kunning partiyalari (bugun/kelgusi — o'zi tayyorlanadi)
 *   GET    /api/portal/schedule-runs/history?from=&to=   kunlar bo'yicha
 *   GET    /api/portal/schedule-runs/{id}
 *   PUT    /api/portal/schedule-runs/{id}           shu kunga namunalarni o'zgartirish {payload}
 *   POST   /api/portal/schedule-runs/{id}/skip      «Bugun yo'q» {reason_id, note} — buyurtma yaratilgan bo'lsa, u sabab bilan yopiladi
 *   POST   /api/portal/schedule-runs/{id}/unskip
 *   POST   /api/portal/schedule-runs/{id}/reset     shu kunning o'zgarishlarini bekor qilish (grafikdagidek)
 *
 * Grafik faqat o'z bo'linmasiniki; bo'linmada laboratoriya grafik rejimini yoqmagan bo'lsa — yozib bo'lmaydi.
 * Partiya namunalari natijalari: /api/portal/samples?run_id= (oddiy «Mening probalarim» bilan bir xil ko'rinish).
 */
class PortalScheduleController extends Controller
{
    public function __construct(private CustomerPortal $portal, private RequestSchedules $schedules)
    {
    }

    /** @return array{write: bool, ids: int[]|null, enabled: int[]} */
    private function ctx(Request $request): array
    {
        abort_unless(RequestSchedules::available(), 503, "Grafik jadvallari yo'q — php artisan migrate");
        $user = $request->user();
        $enabled = $this->schedules->enabledDepartmentIds();
        if ($this->portal->isPortalUser($user)) {
            $ids = $this->portal->linkedDepartments($user)->pluck('id')->map(fn ($v) => (int) $v)->all();

            return ['write' => $user->hasPermission(SampleRequests::PORTAL_PERMISSION), 'ids' => $ids, 'enabled' => array_values(array_intersect($ids, $enabled))];
        }
        abort_unless($this->portal->canPreview($user), 403, "Buyurtmachi portalini ko'rish uchun ruxsat yo'q");

        return ['write' => false, 'ids' => null, 'enabled' => $enabled];
    }

    private function scopeIds(Request $request, array $ctx): array
    {
        $ids = $ctx['ids'] === null ? $ctx['enabled'] : array_values(array_intersect($ctx['ids'], $ctx['enabled']));
        if ($request->filled('department_id')) {
            $want = (int) $request->input('department_id');
            $ids = in_array($want, $ids, true) ? [$want] : [];
        }

        return $ids;
    }

    private function findSchedule(int $id, array $ctx): RequestSchedule
    {
        $s = RequestSchedule::query()
            ->when($ctx['ids'] !== null, fn ($q) => $q->whereIn('customer_department_id', $ctx['ids'] ?: [0]))
            ->find($id);
        abort_unless($s, 404, 'Grafik topilmadi');

        return $s;
    }

    private function findRun(int $id, array $ctx): ScheduleRun
    {
        $r = ScheduleRun::query()->with('department:id,name')
            ->when($ctx['ids'] !== null, fn ($q) => $q->whereIn('customer_department_id', $ctx['ids'] ?: [0]))
            ->find($id);
        abort_unless($r, 404, 'Partiya topilmadi');

        return $r;
    }

    private function points(array $deptIds)
    {
        return Schema::hasTable('svodka_points')
            ? DB::table('svodka_points')->whereIn('customer_department_id', $deptIds ?: [0])->pluck('name', 'id')->mapWithKeys(fn ($v, $k) => [(int) $k => $v])
            : collect();
    }

    /* ---------------------------------------------------------------- grafik qatorlari */

    public function index(Request $request)
    {
        $ctx = $this->ctx($request);
        $ids = $this->scopeIds($request, $ctx);
        $list = RequestSchedule::whereIn('customer_department_id', $ids ?: [0])
            ->orderBy('customer_department_id')->orderBy('planned_time')->orderBy('id')->get();

        return response()->json([
            'can_write'           => $ctx['write'],
            'enabled_departments' => CustomerDepartment::whereIn('id', $ctx['enabled'] ?: [0])->orderBy('name')->get()
                ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name,
                    'svodka_manual' => \App\Services\Samples\RequestSchedules::svodkaManual($d)])->values(), // [P42]
            'data'                => $list->map(fn ($s) => $this->schedules->presentSchedule($s))->values(),
            'reasons'             => app(\App\Services\Samples\SampleRequests::class)->reasons(), // [P39] «Bugun yo'q» sabablari
            'code_time'           => (bool) app(\App\Services\Samples\RequestSettings::class)->get()['schedule_code_time'], // [P41]
        ]);
    }

    public function store(Request $request)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Grafik tuzish uchun ruxsat yo'q");
        [$dept, $attrs] = $this->validated($request, $ctx, null);
        $s = RequestSchedule::create($attrs + ['customer_department_id' => $dept, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->schedules->log($request->user(), $s, "Grafik qatori yaratildi: {$s->planned_time} «{$s->name}»");

        return response()->json($this->schedules->presentSchedule($s), 201);
    }

    public function update(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Grafikni o'zgartirish uchun ruxsat yo'q");
        $s = $this->findSchedule($id, $ctx);
        [, $attrs] = $this->validated($request, $ctx, $s);
        $s->fill($attrs + ['updated_by' => $request->user()->id])->save();
        $this->schedules->syncFutureRuns($s);
        $this->schedules->log($request->user(), $s, "Grafik qatori o'zgartirildi: {$s->planned_time} «{$s->name}»");

        return response()->json($this->schedules->presentSchedule($s->fresh()));
    }

    public function destroy(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Grafikni o'chirish uchun ruxsat yo'q");
        $s = $this->findSchedule($id, $ctx);
        DB::transaction(function () use ($s) {
            // kelmagan bugungi/kelgusi partiyalar o'chadi; o'tganlari (tarix) qoladi
            ScheduleRun::where('schedule_id', $s->id)->where('status', 'planned')->whereDate('run_date', '>=', today()->toDateString())->delete();
            $s->forceFill(['is_active' => false])->save();
            $s->delete();
        });
        $this->schedules->log($request->user(), $s, "Grafik qatori o'chirildi: {$s->planned_time} «{$s->name}»");

        return response()->json(['ok' => true]);
    }

    /** @return array{0:int, 1:array} */
    private function validated(Request $request, array $ctx, ?RequestSchedule $s): array
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'integer'],
            'name'          => ['required', 'string', 'max:120'],
            'time'          => ['required', 'string', 'max:5'],
            'weekdays'      => ['required', 'array', 'min:1'],
            'weekdays.*'    => ['integer', 'between:1,7'],
            'is_active'     => ['nullable', 'boolean'],
            'payload'       => ['required', 'array'],
        ]);
        $dept = (int) ($data['department_id'] ?? 0) ?: (int) ($s?->customer_department_id ?? 0);
        if (! $dept && count($ctx['enabled']) === 1) {
            $dept = $ctx['enabled'][0];
        }
        if (! $dept || ! in_array($dept, $ctx['ids'] ?? [], true)) {
            throw ValidationException::withMessages(['department_id' => ["Bo'limni tanlang"]]);
        }
        if (! in_array($dept, $ctx['enabled'], true)) {
            throw ValidationException::withMessages(['department_id' => ["Bu bo'lim uchun laboratoriya grafik rejimini hali yoqmagan"]]);
        }
        $time = RequestSchedules::normalizeTime($data['time']);
        if (! $time) {
            throw ValidationException::withMessages(['time' => ['Vaqtni SS:DD ko\'rinishida kiriting (masalan 08:00)']]);
        }
        $name = trim($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Nomini kiriting']]);
        }

        return [$dept, [
            'name'         => mb_substr($name, 0, 120),
            'planned_time' => $time,
            'weekdays'     => RequestSchedules::normalizeDays($data['weekdays']),
            'is_active'    => array_key_exists('is_active', $data) && $data['is_active'] !== null ? (bool) $data['is_active'] : ($s?->is_active ?? true),
            'payload'      => $this->schedules->cleanPayload($dept, $data['payload']),
        ]];
    }

    /* ---------------------------------------------------------------- partiyalar */

    public function runs(Request $request)
    {
        $ctx = $this->ctx($request);
        $ids = $this->scopeIds($request, $ctx);
        $date = $request->filled('date') ? Carbon::parse($request->input('date'))->toDateString() : today()->toDateString();
        $this->schedules->ensureRuns($date, $ids);
        $runs = ScheduleRun::with('department:id,name')->whereIn('customer_department_id', $ids ?: [0])
            ->whereDate('run_date', $date)->orderBy('planned_time')->orderBy('id')->get();
        $schedules = RequestSchedule::withTrashed()->whereIn('id', $runs->pluck('schedule_id')->all() ?: [0])->get()->keyBy('id');
        $elements = Element::pluck('symbol', 'id');
        $points = $this->points($ids);

        return response()->json([
            'date'      => $date,
            'can_write' => $ctx['write'],
            'data'      => $runs->map(fn ($r) => $this->schedules->presentRun($r, false, $schedules, $elements, $points))->values(),
        ]);
    }

    public function history(Request $request)
    {
        $ctx = $this->ctx($request);
        $ids = $this->scopeIds($request, $ctx);
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : today();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : $to->copy()->subDays(13);
        if ($from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92);
        }
        $runs = ScheduleRun::whereIn('customer_department_id', $ids ?: [0])
            ->whereDate('run_date', '>=', $from->toDateString())->whereDate('run_date', '<=', $to->toDateString())
            ->orderByDesc('run_date')->orderBy('planned_time')->get();
        $schedules = RequestSchedule::withTrashed()->whereIn('id', $runs->pluck('schedule_id')->all() ?: [0])->get()->keyBy('id');

        $days = [];
        foreach ($runs as $r) {
            $d = $r->run_date->toDateString();
            $st = $this->schedules->displayStatus($r);
            $days[$d] ??= ['date' => $d, 'runs' => [], 'planned' => 0, 'received' => 0, 'samples' => 0, 'missing' => 0];
            $days[$d]['runs'][] = [
                'id' => (int) $r->id, 'time' => $r->planned_time, 'name' => $schedules->get((int) $r->schedule_id)?->name,
                'status' => $st, 'received_count' => (int) $r->received_count, 'missing_count' => (int) $r->missing_count,
                'items_count' => count($r->payload['items'] ?? []), 'arrived_at' => $r->arrived_at?->format('H:i'),
            ];
            $days[$d]['planned'] += $st === 'skipped' ? 0 : 1;
            $days[$d]['received'] += in_array($st, ['received', 'partial'], true) ? 1 : 0;
            $days[$d]['samples'] += (int) $r->received_count;
            $days[$d]['missing'] += (int) $r->missing_count;
        }

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'data' => array_values($days)]);
    }

    public function show(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $run = $this->findRun($id, $ctx);

        return response()->json($this->schedules->presentRun($run, false, null, null, $this->points([(int) $run->customer_department_id])) + ['can_write' => $ctx['write']]);
    }

    public function customize(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Ruxsat yo'q");
        $run = $this->findRun($id, $ctx);
        $data = $request->validate(['payload' => ['required', 'array']]);
        $this->schedules->customize($run, $data['payload'], $request->user());

        return $this->show($request, $id);
    }

    public function skip(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Ruxsat yo'q");
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500'], 'reason_id' => ['nullable', 'integer']]); // [P39] sabab
        $this->schedules->skip($this->findRun($id, $ctx), $request->user(), $data['note'] ?? null, isset($data['reason_id']) ? (int) $data['reason_id'] : null);

        return $this->show($request, $id);
    }

    public function unskip(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Ruxsat yo'q");
        $this->schedules->unskip($this->findRun($id, $ctx), $request->user());

        return $this->show($request, $id);
    }

    public function reset(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Ruxsat yo'q");
        $this->schedules->reset($this->findRun($id, $ctx), $request->user());

        return $this->show($request, $id);
    }
}
