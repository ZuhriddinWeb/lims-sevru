<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Element;
use App\Models\Equipment;
use App\Models\Laboratory;
use App\Models\LaboratoryGroup;
use App\Models\MonitoringPoint;
use App\Models\Sample;
use App\Models\SampleElement;
use App\Models\WaterOrderResult;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rahbariyat uchun jadval ko'rinishidagi hisobotlar.
 * Har bir hisobot bir xil tuzilishda qaytadi: columns + rows + totals.
 */
class ManagementReportController extends Controller
{
    private const TYPES = [
        'monthly', 'groups', 'laboratories', 'elements', 'equipment', 'ecology',
    ];

    public function index(Request $request)
    {
        $type = $request->input('type', 'monthly');

        abort_unless(in_array($type, self::TYPES, true), 422, 'Noma\'lum hisobot turi');

        [$from, $to] = $this->resolvePeriod($request);
        $labId = $request->input('laboratory_id');

        $report = match ($type) {
            'groups'       => $this->groupsReport($from, $to),
            'laboratories' => $this->laboratoriesReport($from, $to),
            'elements'     => $this->elementsReport($from, $to, $labId),
            'equipment'    => $this->equipmentReport($labId),
            'ecology'      => $this->ecologyReport($from, $to),
            default        => $this->monthlyReport($labId),
        };

        return response()->json(array_merge($report, [
            'type'   => $type,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]));
    }

    /* ================= 1. OYLAR BO'YICHA JAMLANMA ================= */

    private function monthlyReport($labId): array
    {
        $rows = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $key   = $month->format('Y-m');

            $base = $this->sampleQuery($labId)->where('period_month', $key);

            $registered = (clone $base)->count();
            $completed  = (clone $base)->where('status', 'completed')->count();
            $archived   = (clone $base)->where('status', 'archived')->count();
            $inWork     = (clone $base)->whereIn('status', ['registered', 'assigned', 'in_progress'])->count();

            // Bajarilgan aniqlashlar
            $tests = SampleElement::where('status', 'done')
                ->whereYear('result_entered_at', $month->year)
                ->whereMonth('result_entered_at', $month->month)
                ->count();

            // O'rtacha muddat
            $done = (clone $base)->whereNotNull('completed_at')->whereNotNull('registered_at')
                ->get(['registered_at', 'completed_at']);
            $avg = $done->isEmpty() ? null
                : round($done->sum(fn ($r) => $r->registered_at->diffInMinutes($r->completed_at)) / $done->count() / 60, 1);

            $rows[] = [
                'month'      => $month->format('m.Y'),
                'registered' => $registered,
                'completed'  => $completed + $archived,
                'in_work'    => $inWork,
                'tests'      => $tests,
                'avg_hours'  => $avg,
            ];
        }

        return [
            'columns' => [
                ['key' => 'month', 'labelKey' => 'rep.col.month'],
                ['key' => 'registered', 'labelKey' => 'rep.col.registered', 'align' => 'right'],
                ['key' => 'completed', 'labelKey' => 'rep.col.completed', 'align' => 'right'],
                ['key' => 'in_work', 'labelKey' => 'rep.col.inWork', 'align' => 'right'],
                ['key' => 'tests', 'labelKey' => 'rep.col.tests', 'align' => 'right'],
                ['key' => 'avg_hours', 'labelKey' => 'rep.col.avgHours', 'align' => 'right'],
            ],
            'rows'   => $rows,
            'totals' => [
                'month'      => 'rep.total',
                'registered' => array_sum(array_column($rows, 'registered')),
                'completed'  => array_sum(array_column($rows, 'completed')),
                'in_work'    => array_sum(array_column($rows, 'in_work')),
                'tests'      => array_sum(array_column($rows, 'tests')),
                'avg_hours'  => null,
            ],
        ];
    }

    /* ================= 2. GURUHLAR BO'YICHA ================= */

    private function groupsReport(Carbon $from, Carbon $to): array
    {
        $groups = LaboratoryGroup::with('laboratory')->where('is_active', true)->get();
        $rows = [];

        foreach ($groups as $group) {
            $assigned = Sample::where('assigned_group_id', $group->id)
                ->whereBetween('registered_at', [$from, $to]);

            $total = (clone $assigned)->count();
            if ($total === 0) {
                continue;
            }

            $completed = (clone $assigned)->whereIn('status', ['completed', 'archived'])->count();

            $done = (clone $assigned)->whereNotNull('assigned_at')->whereNotNull('completed_at')
                ->get(['assigned_at', 'completed_at']);
            $avg = $done->isEmpty() ? null
                : round($done->sum(fn ($r) => $r->assigned_at->diffInMinutes($r->completed_at)) / $done->count() / 60, 1);

            $rows[] = [
                'group'      => $this->ml($group, 'name'),
                'laboratory' => $this->ml($group->laboratory, 'name'),
                'total'      => $total,
                'completed'  => $completed,
                'in_work'    => $total - $completed,
                'percent'    => $total > 0 ? round($completed / $total * 100) : 0,
                'avg_hours'  => $avg,
            ];
        }

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'columns' => [
                ['key' => 'group', 'labelKey' => 'rep.col.group'],
                ['key' => 'laboratory', 'labelKey' => 'rep.col.laboratory'],
                ['key' => 'total', 'labelKey' => 'rep.col.received', 'align' => 'right'],
                ['key' => 'completed', 'labelKey' => 'rep.col.completed', 'align' => 'right'],
                ['key' => 'in_work', 'labelKey' => 'rep.col.inWork', 'align' => 'right'],
                ['key' => 'percent', 'labelKey' => 'rep.col.percent', 'align' => 'right', 'suffix' => '%'],
                ['key' => 'avg_hours', 'labelKey' => 'rep.col.avgHours', 'align' => 'right'],
            ],
            'rows'   => $rows,
            'totals' => [
                'group'     => 'rep.total',
                'total'     => array_sum(array_column($rows, 'total')),
                'completed' => array_sum(array_column($rows, 'completed')),
                'in_work'   => array_sum(array_column($rows, 'in_work')),
            ],
        ];
    }

    /* ================= 3. LABORATORIYALAR BO'YICHA ================= */

    private function laboratoriesReport(Carbon $from, Carbon $to): array
    {
        $labs = Laboratory::with('groups')->where('is_active', true)->orderBy('sort_order')->get();
        $rows = [];

        foreach ($labs as $lab) {
            $groupIds = $lab->groups->pluck('id');

            $samples = Sample::where(function ($q) use ($lab, $groupIds) {
                $q->where('registering_lab_id', $lab->id)->orWhereIn('assigned_group_id', $groupIds);
            })->whereBetween('registered_at', [$from, $to]);

            $total     = (clone $samples)->count();
            $completed = (clone $samples)->whereIn('status', ['completed', 'archived'])->count();

            $equipment = Equipment::where('laboratory_id', $lab->id);
            $eqTotal   = (clone $equipment)->count();
            $eqBlocked = (clone $equipment)->where('is_blocked', true)->count();

            $rows[] = [
                'laboratory'   => $this->ml($lab, 'name'),
                'groups'       => $lab->groups->count(),
                'samples'      => $total,
                'completed'    => $completed,
                'equipment'    => $eqTotal,
                'eq_blocked'   => $eqBlocked,
                'eq_readiness' => $eqTotal > 0 ? round(($eqTotal - $eqBlocked) / $eqTotal * 100) : null,
            ];
        }

        return [
            'columns' => [
                ['key' => 'laboratory', 'labelKey' => 'rep.col.laboratory'],
                ['key' => 'groups', 'labelKey' => 'rep.col.groups', 'align' => 'right'],
                ['key' => 'samples', 'labelKey' => 'rep.col.samples', 'align' => 'right'],
                ['key' => 'completed', 'labelKey' => 'rep.col.completed', 'align' => 'right'],
                ['key' => 'equipment', 'labelKey' => 'rep.col.equipment', 'align' => 'right'],
                ['key' => 'eq_blocked', 'labelKey' => 'rep.col.blocked', 'align' => 'right'],
                ['key' => 'eq_readiness', 'labelKey' => 'rep.col.readiness', 'align' => 'right', 'suffix' => '%'],
            ],
            'rows'   => $rows,
            'totals' => [
                'laboratory' => 'rep.total',
                'samples'    => array_sum(array_column($rows, 'samples')),
                'completed'  => array_sum(array_column($rows, 'completed')),
                'equipment'  => array_sum(array_column($rows, 'equipment')),
                'eq_blocked' => array_sum(array_column($rows, 'eq_blocked')),
            ],
        ];
    }

    /* ================= 4. ELEMENTLAR BO'YICHA ================= */

    private function elementsReport(Carbon $from, Carbon $to, $labId): array
    {
        $elements = Element::where('is_active', true)->orderBy('sort_order')->get();
        $rows = [];

        foreach ($elements as $element) {
            $query = SampleElement::where('element_id', $element->id)
                ->whereHas('sample', fn ($q) => $q->whereBetween('registered_at', [$from, $to]));

            $total = (clone $query)->count();
            if ($total === 0) {
                continue;
            }

            $done = (clone $query)->where('status', 'done');
            $doneCount = (clone $done)->count();
            $values = (clone $done)->whereNotNull('result_value')->pluck('result_value');

            $rows[] = [
                'element'  => $element->symbol . ' · ' . $this->ml($element, 'name'),
                'unit'     => $element->default_unit,
                'ordered'  => $total,
                'done'     => $doneCount,
                'pending'  => $total - $doneCount,
                'avg'      => $values->isNotEmpty() ? round($values->avg(), 4) : null,
                'min'      => $values->isNotEmpty() ? round($values->min(), 4) : null,
                'max'      => $values->isNotEmpty() ? round($values->max(), 4) : null,
            ];
        }

        usort($rows, fn ($a, $b) => $b['ordered'] <=> $a['ordered']);

        return [
            'columns' => [
                ['key' => 'element', 'labelKey' => 'rep.col.element'],
                ['key' => 'unit', 'labelKey' => 'rep.col.unit'],
                ['key' => 'ordered', 'labelKey' => 'rep.col.ordered', 'align' => 'right'],
                ['key' => 'done', 'labelKey' => 'rep.col.done', 'align' => 'right'],
                ['key' => 'pending', 'labelKey' => 'rep.col.pending', 'align' => 'right'],
                ['key' => 'avg', 'labelKey' => 'rep.col.avg', 'align' => 'right'],
                ['key' => 'min', 'labelKey' => 'rep.col.min', 'align' => 'right'],
                ['key' => 'max', 'labelKey' => 'rep.col.max', 'align' => 'right'],
            ],
            'rows'   => $rows,
            'totals' => [
                'element' => 'rep.total',
                'ordered' => array_sum(array_column($rows, 'ordered')),
                'done'    => array_sum(array_column($rows, 'done')),
                'pending' => array_sum(array_column($rows, 'pending')),
            ],
        ];
    }

    /* ================= 5. USKUNALAR REYESTRI ================= */

    private function equipmentReport($labId): array
    {
        $query = Equipment::with(['laboratory', 'group']);
        if ($labId) {
            $query->where('laboratory_id', $labId);
        }

        $rows = [];
        foreach ($query->orderBy('name')->get() as $eq) {
            $daysLeft = $eq->next_calibration_at
                ? now()->startOfDay()->diffInDays($eq->next_calibration_at->startOfDay(), false)
                : null;

            $rows[] = [
                'name'       => $eq->name,
                'serial'     => $eq->serial_number,
                'laboratory' => $this->ml($eq->laboratory, 'name'),
                'status'     => $eq->status,
                'blocked'    => $eq->is_blocked,
                'next_cal'   => optional($eq->next_calibration_at)->format('d.m.Y'),
                'days_left'  => $daysLeft,
                'usage'      => $eq->max_usage_before_calibration
                    ? $eq->usage_count . ' / ' . $eq->max_usage_before_calibration
                    : (string) $eq->usage_count,
            ];
        }

        return [
            'columns' => [
                ['key' => 'name', 'labelKey' => 'rep.col.equipmentName'],
                ['key' => 'serial', 'labelKey' => 'rep.col.serial', 'mono' => true],
                ['key' => 'laboratory', 'labelKey' => 'rep.col.laboratory'],
                ['key' => 'status', 'labelKey' => 'rep.col.status', 'type' => 'status'],
                ['key' => 'next_cal', 'labelKey' => 'rep.col.nextCal'],
                ['key' => 'days_left', 'labelKey' => 'rep.col.daysLeft', 'align' => 'right', 'type' => 'days'],
                ['key' => 'usage', 'labelKey' => 'rep.col.usage', 'align' => 'right'],
            ],
            'rows'   => $rows,
            'totals' => null,
        ];
    }

    /* ================= 6. EKOLOGIYA ================= */

    private function ecologyReport(Carbon $from, Carbon $to): array
    {
        $points = MonitoringPoint::where('is_active', true)->get();
        $rows = [];

        foreach ($points as $point) {
            $results = WaterOrderResult::whereHas('order', fn ($q) => $q->where('monitoring_point_id', $point->id))
                ->whereBetween('measured_at', [$from, $to])
                ->whereNotNull('value')
                ->get();

            if ($results->isEmpty()) {
                continue;
            }

            $critical = $results->where('assessment', 'critical')->count();
            $warning  = $results->where('assessment', 'warning')->count();
            $normal   = $results->where('assessment', 'normal')->count();

            $rows[] = [
                'point'      => $point->code . ' · ' . $point->name,
                'type'       => $point->type,
                'total'      => $results->count(),
                'normal'     => $normal,
                'warning'    => $warning,
                'critical'   => $critical,
                'parameters' => $results->where('assessment', 'critical')
                    ->pluck('parameter')->unique()->take(3)->implode(', ') ?: '—',
            ];
        }

        usort($rows, fn ($a, $b) => $b['critical'] <=> $a['critical']);

        return [
            'columns' => [
                ['key' => 'point', 'labelKey' => 'rep.col.point'],
                ['key' => 'type', 'labelKey' => 'rep.col.pointType', 'type' => 'pointType'],
                ['key' => 'total', 'labelKey' => 'rep.col.measurements', 'align' => 'right'],
                ['key' => 'normal', 'labelKey' => 'status.normal', 'align' => 'right'],
                ['key' => 'warning', 'labelKey' => 'status.warning', 'align' => 'right'],
                ['key' => 'critical', 'labelKey' => 'status.critical', 'align' => 'right'],
                ['key' => 'parameters', 'labelKey' => 'rep.col.deviations'],
            ],
            'rows'   => $rows,
            'totals' => [
                'point'    => 'rep.total',
                'total'    => array_sum(array_column($rows, 'total')),
                'normal'   => array_sum(array_column($rows, 'normal')),
                'warning'  => array_sum(array_column($rows, 'warning')),
                'critical' => array_sum(array_column($rows, 'critical')),
            ],
        ];
    }

    /* ================= EXCEL (CSV) EKSPORTI ================= */

    public function export(Request $request): StreamedResponse
    {
        $data = $this->index($request)->getData(true);
        $type = $data['type'];
        $locale = $request->input('locale', 'ru');

        // Ustun sarlavhalarini joriy tilga o'girish uchun oddiy lug'at
        $labels = $this->exportLabels($locale);

        $name = "hisobot_{$type}_" . now()->format('Y-m-d_Hi') . '.csv';

        return response()->streamDownload(function () use ($data, $labels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $keys = array_column($data['columns'], 'key');
            fputcsv($out, array_map(
                fn ($c) => $labels[$c['labelKey']] ?? $c['labelKey'],
                $data['columns']
            ), ';');

            foreach ($data['rows'] as $row) {
                fputcsv($out, array_map(function ($k) use ($row) {
                    $v = $row[$k] ?? '';
                    if (is_bool($v)) {
                        return $v ? 'да' : 'нет';
                    }
                    return is_float($v) ? str_replace('.', ',', (string) $v) : $v;
                }, $keys), ';');
            }

            if (! empty($data['totals'])) {
                fputcsv($out, array_map(function ($k) use ($data, $labels) {
                    $v = $data['totals'][$k] ?? '';
                    return $v === 'rep.total' ? ($labels['rep.total'] ?? 'ИТОГО') : $v;
                }, $keys), ';');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function exportLabels(string $locale): array
    {
        $ru = [
            'rep.total' => 'ИТОГО',
            'rep.col.month' => 'Месяц', 'rep.col.registered' => 'Зарегистрировано',
            'rep.col.completed' => 'Завершено', 'rep.col.inWork' => 'В работе',
            'rep.col.tests' => 'Определений', 'rep.col.avgHours' => 'Средний срок, ч',
            'rep.col.group' => 'Группа', 'rep.col.laboratory' => 'Лаборатория',
            'rep.col.received' => 'Получено', 'rep.col.percent' => 'Выполнено, %',
            'rep.col.groups' => 'Групп', 'rep.col.samples' => 'Проб',
            'rep.col.equipment' => 'Оборудование', 'rep.col.blocked' => 'Заблокировано',
            'rep.col.readiness' => 'Готовность, %',
            'rep.col.element' => 'Элемент', 'rep.col.unit' => 'Ед. изм.',
            'rep.col.ordered' => 'Назначено', 'rep.col.done' => 'Выполнено',
            'rep.col.pending' => 'Ожидает', 'rep.col.avg' => 'Среднее',
            'rep.col.min' => 'Минимум', 'rep.col.max' => 'Максимум',
            'rep.col.equipmentName' => 'Наименование', 'rep.col.serial' => 'Серийный номер',
            'rep.col.status' => 'Состояние', 'rep.col.nextCal' => 'Следующая калибровка',
            'rep.col.daysLeft' => 'Дней осталось', 'rep.col.usage' => 'Использований',
            'rep.col.point' => 'Точка мониторинга', 'rep.col.pointType' => 'Тип',
            'rep.col.measurements' => 'Измерений', 'rep.col.deviations' => 'Отклонения',
            'status.normal' => 'В норме', 'status.warning' => 'Внимание', 'status.critical' => 'Критично',
        ];

        return $ru; // Hisobot fayli rasmiy hujjat sifatida rus tilida chiqadi
    }

    /* ================= YORDAMCHI ================= */

    private function sampleQuery($labId)
    {
        $query = Sample::query();

        if ($labId) {
            $groupIds = LaboratoryGroup::where('laboratory_id', $labId)->pluck('id');
            $query->where(function ($q) use ($labId, $groupIds) {
                $q->where('registering_lab_id', $labId)->orWhereIn('assigned_group_id', $groupIds);
            });
        }

        return $query;
    }

    private function resolvePeriod(Request $request): array
    {
        if ($request->filled('date_from') && $request->filled('date_to')) {
            return [
                Carbon::parse($request->input('date_from'))->startOfDay(),
                Carbon::parse($request->input('date_to'))->endOfDay(),
            ];
        }

        return match ($request->input('period', 'month')) {
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'year'    => [now()->startOfYear(), now()->endOfYear()],
            default   => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private function ml($model, string $base): ?string
    {
        if (! $model) {
            return null;
        }
        $locale = request()->input('locale', 'ru');
        return $model->{"{$base}_{$locale}"} ?? $model->{"{$base}_ru"} ?? $model->{$base} ?? null;
    }
}
