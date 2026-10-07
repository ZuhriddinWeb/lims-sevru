<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\Laboratory;
use App\Models\LaboratoryGroup;
use App\Models\Sample;
use App\Models\SampleElement;
use App\Models\WaterOrder;
use App\Models\WaterOrderResult;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Rahbariyat paneli — umumiy ko'rsatkichlar, dinamika va nazorat bloklari.
 */
class ExecutiveController extends Controller
{
    public function index(Request $request)
    {
        [$from, $to] = $this->resolvePeriod($request);
        [$prevFrom, $prevTo] = $this->previousPeriod($from, $to);

        $labId = $request->input('laboratory_id');

        return response()->json([
            'period' => [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $request->input('period', 'month'),
            ],
            'kpi'        => $this->kpi($from, $to, $prevFrom, $prevTo, $labId),
            'monthly'    => $this->monthlyDynamics($labId),
            'by_group'   => $this->loadByGroup($from, $to),
            'turnaround' => $this->turnaroundByGroup($from, $to),
            'equipment'  => $this->equipmentOverview($labId),
            'ecology'    => $this->ecologyOverview($from, $to),
            'overdue'    => $this->overdueItems(),
        ]);
    }

    /* ================= 1. ASOSIY KO'RSATKICHLAR ================= */

    private function kpi(Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo, $labId): array
    {
        // Probalar soni
        $samples = $this->sampleQuery($labId)
            ->whereBetween('registered_at', [$from, $to])->count();
        $samplesPrev = $this->sampleQuery($labId)
            ->whereBetween('registered_at', [$prevFrom, $prevTo])->count();

        // Bajarilgan tahlillar (element bo'yicha natijalar)
        $tests = SampleElement::where('status', 'done')
            ->whereBetween('result_entered_at', [$from, $to])->count();
        $testsPrev = SampleElement::where('status', 'done')
            ->whereBetween('result_entered_at', [$prevFrom, $prevTo])->count();

        // O'rtacha bajarilish vaqti (soatda): to'liq sikl va analitik ishi
        $full    = $this->avgHours($from, $to, 'registered_at', $labId);
        $fullPrev = $this->avgHours($prevFrom, $prevTo, 'registered_at', $labId);
        $lab     = $this->avgHours($from, $to, 'assigned_at', $labId);

        // Kechikkanlar — 7 kundan ortiq ishda turganlar
        $overdue = $this->sampleQuery($labId)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '<=', now()->subDays(7))
            ->count();

        return [
            'samples'           => ['value' => $samples, 'prev' => $samplesPrev, 'change' => $this->pct($samples, $samplesPrev)],
            'tests'             => ['value' => $tests, 'prev' => $testsPrev, 'change' => $this->pct($tests, $testsPrev)],
            'turnaround_full'   => ['value' => $full, 'prev' => $fullPrev, 'change' => $this->pct($full, $fullPrev, true)],
            'turnaround_lab'    => ['value' => $lab],
            'overdue'           => ['value' => $overdue],
        ];
    }

    /**
     * O'rtacha bajarilish vaqti soatda.
     * $startField: registered_at (to'liq sikl) yoki assigned_at (analitik ishi)
     */
    private function avgHours(Carbon $from, Carbon $to, string $startField, $labId): ?float
    {
        $rows = $this->sampleQuery($labId)
            ->whereNotNull('completed_at')
            ->whereNotNull($startField)
            ->whereBetween('completed_at', [$from, $to])
            ->get([$startField, 'completed_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $sum = $rows->sum(fn ($r) => $r->{$startField}->diffInMinutes($r->completed_at));

        return round($sum / $rows->count() / 60, 1);
    }

    /* ================= 2. OYLAR BO'YICHA DINAMIKA ================= */

    private function monthlyDynamics($labId): array
    {
        $out = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $key   = $month->format('Y-m');

            $registered = $this->sampleQuery($labId)->where('period_month', $key)->count();
            $completed  = $this->sampleQuery($labId)
                ->where('period_month', $key)->where('status', 'completed')->count();

            $out[] = [
                'month'      => $key,
                'label'      => $month->format('m.y'),
                'registered' => $registered,
                'completed'  => $completed,
            ];
        }

        return $out;
    }

    /* ================= 3. GURUHLAR BO'YICHA YUKLAMA ================= */

    private function loadByGroup(Carbon $from, Carbon $to): array
    {
        $groups = LaboratoryGroup::with('laboratory')->where('is_active', true)->get();
        $out = [];

        foreach ($groups as $group) {
            $count = Sample::where('assigned_group_id', $group->id)
                ->whereBetween('registered_at', [$from, $to])->count();

            if ($count === 0) {
                continue;
            }

            $out[] = [
                'id'         => $group->id,
                'code'       => $group->code,
                'name_ru'    => $group->name_ru,
                'name_uz'    => $group->name_uz,
                'name_en'    => $group->name_en,
                'laboratory' => $group->laboratory?->only(['id', 'code', 'name_ru', 'name_uz', 'name_en']),
                'count'      => $count,
            ];
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /* ================= 4. GURUHLAR BO'YICHA BAJARILISH VAQTI ================= */

    private function turnaroundByGroup(Carbon $from, Carbon $to): array
    {
        $groups = LaboratoryGroup::where('is_active', true)
            ->whereIn('kind', ['executor', 'both'])->get();

        $out = [];

        foreach ($groups as $group) {
            $rows = Sample::where('assigned_group_id', $group->id)
                ->whereNotNull('assigned_at')
                ->whereNotNull('completed_at')
                ->whereBetween('completed_at', [$from, $to])
                ->get(['assigned_at', 'completed_at']);

            if ($rows->isEmpty()) {
                continue;
            }

            $avg = $rows->sum(fn ($r) => $r->assigned_at->diffInMinutes($r->completed_at)) / $rows->count() / 60;

            $out[] = [
                'id'      => $group->id,
                'name_ru' => $group->name_ru,
                'name_uz' => $group->name_uz,
                'name_en' => $group->name_en,
                'hours'   => round($avg, 1),
                'count'   => $rows->count(),
            ];
        }

        usort($out, fn ($a, $b) => $a['hours'] <=> $b['hours']);

        return $out;
    }

    /* ================= 5. USKUNALAR NAZORATI ================= */

    private function equipmentOverview($labId): array
    {
        $query = Equipment::query();
        if ($labId) {
            $query->where('laboratory_id', $labId);
        }

        $total   = (clone $query)->count();
        $blocked = (clone $query)->where('is_blocked', true)->count();
        $waiting = (clone $query)->where('status', 'awaiting_calibration')->count();
        $working = (clone $query)->where('status', 'working')->where('is_blocked', false)->count();

        $overdueCalibration = (clone $query)
            ->whereNotNull('next_calibration_at')
            ->whereDate('next_calibration_at', '<', now())
            ->count();

        return [
            'total'    => $total,
            'working'  => $working,
            'blocked'  => $blocked,
            'waiting'  => $waiting,
            'overdue'  => $overdueCalibration,
            'readiness' => $total > 0 ? round($working / $total * 100) : 0,
        ];
    }

    /* ================= 6. EKOLOGIYA ================= */

    private function ecologyOverview(Carbon $from, Carbon $to): array
    {
        $results = WaterOrderResult::with('order.monitoringPoint')
            ->whereBetween('measured_at', [$from, $to])
            ->whereNotNull('value')
            ->get();

        $critical = $results->where('assessment', 'critical');
        $warning  = $results->where('assessment', 'warning');

        // Qaysi nuqtalarda muammo bor
        $points = $critical->groupBy(fn ($r) => $r->order?->monitoringPoint?->name ?? '—')
            ->map(fn ($items, $name) => [
                'name'       => $name,
                'count'      => $items->count(),
                'parameters' => $items->pluck('parameter')->unique()->values(),
            ])->values()->take(6);

        return [
            'measurements' => $results->count(),
            'critical'     => $critical->count(),
            'warning'      => $warning->count(),
            'normal'       => $results->where('assessment', 'normal')->count(),
            'orders'       => WaterOrder::whereBetween('registered_at', [$from, $to])->count(),
            'problem_points' => $points,
        ];
    }

    /* ================= 7. MUDDATI O'TGANLAR ================= */

    private function overdueItems(): array
    {
        $equipment = Equipment::with('laboratory')
            ->whereNotNull('next_calibration_at')
            ->whereDate('next_calibration_at', '<', now())
            ->orderBy('next_calibration_at')
            ->limit(8)
            ->get(['id', 'name', 'serial_number', 'laboratory_id', 'next_calibration_at', 'is_blocked']);

        $samples = Sample::with('assignedGroup')
            ->whereIn('status', ['assigned', 'in_progress'])
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '<=', now()->subDays(7))
            ->orderBy('assigned_at')
            ->limit(8)
            ->get(['id', 'code', 'sample_number', 'assigned_group_id', 'assigned_at', 'status']);

        return [
            'equipment' => $equipment,
            'samples'   => $samples,
        ];
    }

    /* ================= YORDAMCHI METODLAR ================= */

    private function sampleQuery($labId)
    {
        $query = Sample::query();

        if ($labId) {
            // Laboratoriya bo'yicha: ro'yxatga oluvchi yoki bajaruvchi guruh orqali
            $groupIds = LaboratoryGroup::where('laboratory_id', $labId)->pluck('id');
            $query->where(function ($q) use ($labId, $groupIds) {
                $q->where('registering_lab_id', $labId)
                  ->orWhereIn('assigned_group_id', $groupIds);
            });
        }

        return $query;
    }

    private function resolvePeriod(Request $request): array
    {
        $period = $request->input('period', 'month');

        if ($request->filled('date_from') && $request->filled('date_to')) {
            return [
                Carbon::parse($request->input('date_from'))->startOfDay(),
                Carbon::parse($request->input('date_to'))->endOfDay(),
            ];
        }

        return match ($period) {
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'year'    => [now()->startOfYear(), now()->endOfYear()],
            default   => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private function previousPeriod(Carbon $from, Carbon $to): array
    {
        $days = max($from->diffInDays($to), 1);

        return [
            $from->copy()->subDays($days + 1)->startOfDay(),
            $from->copy()->subDay()->endOfDay(),
        ];
    }

    /**
     * Foizdagi o'zgarish. $inverse = true bo'lsa, kamayish yaxshi hisoblanadi (vaqt uchun).
     */
    private function pct($current, $previous, bool $inverse = false): ?float
    {
        if ($previous === null || $current === null || $previous == 0) {
            return null;
        }

        $change = round(($current - $previous) / abs($previous) * 100, 1);

        return $inverse ? -$change : $change;
    }
}
