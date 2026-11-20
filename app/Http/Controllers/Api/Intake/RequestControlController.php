<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\CloseReason;
use App\Models\CustomerDepartment;
use App\Models\SampleRequest;
use App\Services\Insights\SimpleXlsx;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 39] «Buyurtmalar nazorati» — davr bo'yicha: o'z vaqtida qabul, kechiktirib kiritilgan,
 * sabab bilan yopilgan (sabablar kesimida), avtomatik yopilgan, hali yopilmagan.
 *   GET /api/request-control?from=&to=&department_id=&source=request|schedule&export=1
 * Davr — buyurtma sanasi: grafik buyurtmasida grafik vaqti, odatiyda yuborilgan vaqt.
 */
class RequestControlController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(Schema::hasColumn('sample_requests', 'late_entry'), 503, "Paket 39 migratsiyasi bajarilmagan — php artisan migrate");
        $to = $this->date($request->input('to')) ?? today();
        $from = $this->date($request->input('from')) ?? $to->copy()->startOfMonth();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        $q = SampleRequest::query()->where('status', '!=', 'draft')
            ->whereRaw('COALESCE(scheduled_at, submitted_at) >= ?', [$from->copy()->startOfDay()])
            ->whereRaw('COALESCE(scheduled_at, submitted_at) <= ?', [$to->copy()->endOfDay()]);
        if ($request->filled('department_id')) {
            $q->where('customer_department_id', (int) $request->input('department_id'));
        }
        if ($request->input('source') === 'schedule') {
            $q->where('origin', 'schedule');
        } elseif ($request->input('source') === 'request') {
            $q->where('origin', '!=', 'schedule');
        }
        $rows = $q->get(['id', 'number', 'customer_department_id', 'origin', 'status', 'late_entry', 'auto_closed', 'close_reason_id',
            'close_reason', 'closed_by', 'scheduled_at', 'submitted_at', 'received_at', 'received_entered_at', 'received_by']);

        $depts = CustomerDepartment::withTrashed()->whereIn('id', $rows->pluck('customer_department_id')->unique()->all() ?: [0])->pluck('name', 'id');
        $reasons = CloseReason::pluck('name_uz', 'id');

        $sum = function ($list) {
            $closedWithReason = $list->filter(fn ($r) => in_array($r->status, ['cancelled', 'rejected'], true) && ! $r->auto_closed);
            $received = $list->whereIn('status', ['received', 'partial']);

            return [
                'total'        => $list->count(),
                'schedule'     => $list->where('origin', 'schedule')->count(),
                'received'     => $received->count(),
                'on_time'      => $received->filter(fn ($r) => ! $r->late_entry)->count(),
                'late_entry'   => $list->filter(fn ($r) => $r->late_entry)->count(),
                'closed'       => $closedWithReason->count(),
                'auto_closed'  => $list->filter(fn ($r) => $r->auto_closed)->count(),
                'unclosed'     => $list->where('status', 'expired')->count(),
                'waiting'      => $list->whereIn('status', ['submitted'])->count(),
            ];
        };

        $byDept = $rows->groupBy('customer_department_id')->map(fn ($list, $id) => ['id' => (int) $id, 'name' => $depts[$id] ?? "#$id"] + $sum($list))
            ->sortBy('name')->values();
        $byReason = $rows->filter(fn ($r) => in_array($r->status, ['cancelled', 'rejected'], true) || $r->close_reason_id)
            ->groupBy(fn ($r) => $r->auto_closed ? 'auto' : ($r->close_reason_id ?: 'text'))
            ->map(fn ($list, $k) => [
                'key'   => (string) $k,
                'name'  => $k === 'auto' ? null : ($k === 'text' ? null : ($reasons[$k] ?? "#$k")),
                'count' => $list->count(),
            ])->sortByDesc('count')->values();
        $late = $rows->filter(fn ($r) => $r->late_entry)->sortByDesc('received_at')->take(300)->map(fn ($r) => [
            'id'          => (int) $r->id,
            'number'      => $r->number,
            'department'  => $depts[$r->customer_department_id] ?? null,
            'origin'      => $r->origin,
            'scheduled_at'=> $r->scheduled_at?->format('Y-m-d H:i'),
            'received_at' => $r->received_at?->format('Y-m-d H:i'),
            'entered_at'  => $r->received_entered_at?->format('Y-m-d H:i'),
            'delay_hours' => $r->received_at && $r->received_entered_at ? round($r->received_at->diffInMinutes($r->received_entered_at) / 60, 1) : null,
        ])->values();

        $out = [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'totals' => $sum($rows), 'departments' => $byDept, 'reasons' => $byReason, 'late' => $late,
        ];

        return $request->boolean('export') ? $this->export($out) : response()->json($out);
    }

    private function date($v): ?Carbon
    {
        try {
            return $v ? Carbon::parse((string) $v)->startOfDay() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function export(array $o)
    {
        $head = ["Bo'linma", 'Jami', 'Grafik', 'Qabul qilingan', "O'z vaqtida", 'Kechiktirib kiritilgan', 'Sabab bilan yopilgan', 'Avtomatik yopilgan', 'Yopilmagan', 'Kutilmoqda'];
        $line = fn ($name, $s) => [$name, $s['total'], $s['schedule'], $s['received'], $s['on_time'], $s['late_entry'], $s['closed'], $s['auto_closed'], $s['unclosed'], $s['waiting']];
        $rows = [$head];
        foreach ($o['departments'] as $d) {
            $rows[] = $line($d['name'], $d);
        }
        $rows[] = $line('JAMI', $o['totals']);
        $reasons = [['Sabab', 'Soni']];
        foreach ($o['reasons'] as $r) {
            $reasons[] = [$r['key'] === 'auto' ? "Avtomatik yopilgan (sababsiz)" : ($r['name'] ?? 'Matn bilan (ro\'yxatsiz)'), $r['count']];
        }
        $late = [['Buyurtma', "Bo'linma", 'Manba', 'Grafik vaqti', 'Haqiqiy qabul', 'Kiritilgan', 'Kechikish, soat']];
        foreach ($o['late'] as $l) {
            $late[] = [$l['number'], $l['department'], $l['origin'] === 'schedule' ? 'Grafik' : 'Buyurtma', $l['scheduled_at'], $l['received_at'], $l['entered_at'], $l['delay_hours']];
        }
        $name = "buyurtmalar_nazorati_{$o['from']}_{$o['to']}";
        if (! SimpleXlsx::available()) {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                foreach ($rows as $r) {
                    fputcsv($out, $r, ';');
                }
                fclose($out);
            }, "$name.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $path = (new SimpleXlsx())
            ->sheet("Bo'linmalar", $rows, [32, 8, 8, 12, 12, 14, 14, 14, 12, 12], true)
            ->sheet('Sabablar', $reasons, [48, 10])
            ->sheet('Kechiktirib kiritilgan', $late, [16, 32, 10, 17, 17, 17, 12])
            ->save();

        return response()->download($path, "$name.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
}
