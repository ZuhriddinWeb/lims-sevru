<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\Element;
use App\Models\RequestSchedule;
use App\Models\ScheduleRun;
use App\Services\Samples\RequestSchedules;
use App\Services\Samples\SampleIntake;
use App\Services\Samples\SampleRequests;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * [Paket 36] Laboratoriya — «Grafik bo'yicha qabul».
 *
 *   GET  /api/schedule-board?date=                      kunning partiyalari (grafik yoqilgan bo'limlar)
 *   POST /api/schedule-board/{id}/arrive                «Keldi» {positions[], handed_over_by, handed_over_phone, received_by}
 *   GET  /api/schedule-board/settings                   bo'limlar: grafik yoqilganmi, kechikish (daqiqa)
 *   PUT  /api/schedule-board/settings/{departmentId}    {enabled, late_minutes}
 *
 * Laboratoriya namuna olish nuqtalarini ko'rmaydi (faqat shifr va ko'rsatkichlar).
 * Guruhga biriktirilgan xodim — faqat o'z guruhiga yuborilgan partiyalarni ko'radi.
 */
class LabScheduleController extends Controller
{
    public function __construct(private RequestSchedules $schedules)
    {
    }

    public function index(Request $request)
    {
        abort_unless(RequestSchedules::available(), 503, "Grafik jadvallari yo'q — php artisan migrate");
        $user = $request->user();
        $date = $request->filled('date') ? Carbon::parse($request->input('date'))->toDateString() : today()->toDateString();
        $enabled = $this->schedules->enabledDepartmentIds();
        $this->schedules->ensureRuns($date, $enabled);

        $runs = ScheduleRun::with('department:id,name')->whereIn('customer_department_id', $enabled ?: [0])
            ->whereDate('run_date', $date)->orderBy('planned_time')->orderBy('id')->get();
        $gid = (int) $user->laboratory_group_id;
        if ($gid && ! SampleRequests::seesAllGroups($user)) {
            $runs = $runs->filter(fn ($r) => ! ($r->payload['laboratory_group_id'] ?? null) || (int) $r->payload['laboratory_group_id'] === $gid)->values();
        }
        $schedules = RequestSchedule::withTrashed()->whereIn('id', $runs->pluck('schedule_id')->all() ?: [0])->get()->keyBy('id');
        $elements = Element::pluck('symbol', 'id');

        return response()->json([
            'date'        => $date,
            'today'       => today()->toDateString(),
            'departments' => CustomerDepartment::whereIn('id', $enabled ?: [0])->orderBy('name')->get(['id', 'name'])
                ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])->values(),
            'can_manage'  => $user->isAdmin() || $user->hasPermission('schedules.manage'),
            'receivers'   => SampleIntake::receivers($user),
            'me'          => (int) $user->id,
            'data'        => $runs->map(fn ($r) => $this->schedules->presentRun($r, true, $schedules, $elements))->values(),
        ]);
    }

    public function arrive(Request $request, int $id)
    {
        abort_unless(RequestSchedules::available(), 503);
        $data = $request->validate([
            'positions'         => ['nullable', 'array'],
            'positions.*'       => ['integer', 'min:1'],
            'handed_over_by'    => ['nullable', 'string', 'max:191'],
            'handed_over_phone' => ['nullable', 'string', 'max:64'],
            'received_by'       => ['nullable', 'integer'],
        ]);
        $run = ScheduleRun::findOrFail($id);
        $user = $request->user();
        $gid = (int) ($run->payload['laboratory_group_id'] ?? 0);
        abort_if($gid && $user->laboratory_group_id && (int) $user->laboratory_group_id !== $gid && ! SampleRequests::seesAllGroups($user), 404);

        $run = $this->schedules->arrive($run, $data, $user);

        return response()->json($this->schedules->presentRun($run->load('department:id,name'), true));
    }

    public function settings(Request $request)
    {
        abort_unless(RequestSchedules::available(), 503);
        $this->assertManage($request);

        return response()->json(['data' => CustomerDepartment::where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'schedule_enabled', 'schedule_late_minutes'])
            ->map(fn ($d) => [
                'id' => (int) $d->id, 'name' => $d->name,
                'enabled' => (bool) $d->schedule_enabled, 'late_minutes' => (int) ($d->schedule_late_minutes ?: 30),
                'schedules' => RequestSchedule::where('customer_department_id', $d->id)->where('is_active', true)->count(),
            ])->values()]);
    }

    public function saveSettings(Request $request, int $departmentId)
    {
        abort_unless(RequestSchedules::available(), 503);
        $this->assertManage($request);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'late_minutes' => ['nullable', 'integer', 'between:5,600']]);
        $dept = CustomerDepartment::findOrFail($departmentId);
        $dept->forceFill(['schedule_enabled' => $data['enabled'], 'schedule_late_minutes' => $data['late_minutes'] ?? ($dept->schedule_late_minutes ?: 30)])->save();
        $this->schedules->log($request->user(), $dept, "Grafik rejimi: {$dept->name} — " . ($data['enabled'] ? 'yoqildi' : "o'chirildi")
            . ', kechikish ' . $dept->schedule_late_minutes . ' daq.');

        return response()->json(['ok' => true, 'enabled' => (bool) $dept->schedule_enabled, 'late_minutes' => (int) $dept->schedule_late_minutes]);
    }

    private function assertManage(Request $request): void
    {
        $u = $request->user();
        abort_unless($u->isAdmin() || $u->hasPermission('schedules.manage'), 403, "Grafik sozlamasi uchun ruxsat yo'q");
    }
}
