<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\Sample;
use App\Services\Insights\SimpleXlsx;
use App\Services\Samples\CustomerPortal;
use App\Services\Samples\PortalVisibility;
use App\Services\Samples\SampleAnalyses;
use App\Services\Svodka\SvodkaGate; // [P29]
use Illuminate\Http\Request;

/**
 * Buyurtmachi portali — natijalarni Excel'ga yuklab olish. [Paket 27]
 *   GET /api/portal/samples/export?search=&status=active|done|all&department_id=&date_from=&date_to=&locale=uz|ru|en
 *
 * «Mening probalarim» dagi filtrlar bilan aynan bir xil ro'yxat. Admin sozlamasiga bo'ysunadi:
 * eksport o'chirilgan bo'lsa — 403; yopilgan ma'lumot (dastlabki natija, bosqich) faylga ham yozilmaydi.
 *
 * Varaqlar: «Natijalar» (namuna — qator, element — ustun), «Batafsil» (har bir natija alohida qator,
 * holati bilan), [P28] «Varaqlar jami» (korotochkalar yig'indisi — o'z namunalari bo'yicha),
 * «Ma'lumot» (bo'lim, filtr, kim va qachon yuklagan).
 */
class PortalExportController extends Controller
{
    private const MAX = 3000;
    private const STEPS = ['registered', 'assigned', 'analysis', 'review', 'done', 'archived'];

    public function __construct(
        private CustomerPortal $portal,
        private PortalVisibility $visibility,
        private SampleAnalyses $analyses,
    ) {
    }

    public function export(Request $request)
    {
        $scope = $this->portal->scope($request);
        $vis = $this->visibility->get();
        abort_unless($vis['export'], 403, "Natijalarni yuklab olish laboratoriya tomonidan o'chirilgan");

        $locale = in_array($request->input('locale'), ['uz', 'ru', 'en'], true) ? $request->input('locale') : 'uz';
        $L = self::labels($locale);

        $query = Sample::query()->with([
            'sampleElements.element:id,symbol,name_uz,name_ru,name_en,sort_order',
            'customerDepartment:id,code,name',
        ]);
        if ($scope['preview'] && ! $scope['ids']) {
            $query->whereNotNull('customer_department_id');
        } else {
            $query->whereIn('customer_department_id', $scope['ids'] ?: [0]);
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_code', 'like', "%$search%")
                  ->orWhere('code', 'like', "%$search%")
                  ->orWhere('sample_number', 'like', "%$search%");
            });
        }
        $status = $request->input('status');
        if ($status === 'active') {
            $query->whereNotIn('status', ['completed', 'archived']);
        } elseif ($status === 'done') {
            $query->whereIn('status', ['completed', 'archived']);
        } elseif ($status && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('registered_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('registered_at', '<=', $request->input('date_to'));
        }

        $total = (clone $query)->count();
        abort_if($total > self::MAX, 422, str_replace(':n', (string) self::MAX, $L['too_many']));
        $samples = $query->orderBy('registered_at')->orderBy('id')->get();

        $meta = $vis['steps'] ? $this->analyses->worksheetMeta($samples->pluck('id')->map(fn ($v) => (int) $v)->all()) : [];

        // ---- har bir namuna: natijalar (yakuniy / dastlabki) ----
        $columns = [];   // element_id => [symbol, name, sort, units[unit=>count]]
        $records = [];
        $gates = SvodkaGate::for($samples); // [P29] svodka rejimi: faqat chiqarilgan va o'zgarmagan qiymat
        foreach ($samples as $s) {
            $g = $gates[(int) $s->id] ?? null;
            $gated = $g && $g['enabled'];
            $prelim = $vis['preliminary'] && ! $gated ? $this->preliminary($s) : [];
            $vals = [];
            foreach ($s->sampleElements as $se) {
                $el = $se->element;
                if (! $el) {
                    continue;
                }
                $eid = (int) $el->id;
                $columns[$eid] ??= ['symbol' => $el->symbol, 'name' => $el->{'name_' . $locale} ?: $el->name_uz, 'sort' => (int) $el->sort_order, 'units' => []];

                $final = $se->status === 'done' && $se->result_value !== null;
                if ($final && $vis['final_results'] && $gated && empty($g['visible'][$eid])) {
                    $vals[$eid] = ['value' => null, 'unit' => $se->result_unit, 'state' => 'locked', 'date' => null, 'ws' => null];
                } elseif ($final && $vis['final_results']) {
                    $vals[$eid] = ['value' => (float) $se->result_value, 'unit' => $se->result_unit, 'state' => 'final',
                        'date' => $se->result_entered_at?->format('d.m.Y H:i'), 'ws' => null];
                } elseif (! $final && isset($prelim[$el->symbol])) {
                    $p = $prelim[$el->symbol];
                    $vals[$eid] = ['value' => $p['value'], 'unit' => $p['unit'], 'state' => 'preliminary', 'date' => null, 'ws' => $p['worksheet']];
                } else {
                    $vals[$eid] = ['value' => null, 'unit' => $se->result_unit, 'state' => $final ? 'hidden' : 'waiting', 'date' => null, 'ws' => null];
                }
                if ($vals[$eid]['value'] !== null && $vals[$eid]['unit']) {
                    $columns[$eid]['units'][$vals[$eid]['unit']] = ($columns[$eid]['units'][$vals[$eid]['unit']] ?? 0) + 1;
                }
            }

            $stage = null;
            if ($vis['steps']) {
                $stage = $this->analyses->steps($s, $meta[(int) $s->id] ?? [])['current'] ?? null;
            }
            $records[] = ['sample' => $s, 'vals' => $vals, 'stage' => $stage];
        }

        uasort($columns, fn ($a, $b) => [$a['sort'], $a['symbol']] <=> [$b['sort'], $b['symbol']]);
        foreach ($columns as $eid => &$c) {
            arsort($c['units']);
            $c['unit'] = array_key_first($c['units']);
        }
        unset($c);

        // ---- 1-varaq: Natijalar (keng jadval) ----
        $head = [$L['customer_code'], $L['lab_code'], $L['number'], $L['department'], $L['registered'], $L['state']];
        foreach ($columns as $c) {
            $head[] = trim($c['symbol'] . ' — ' . $c['name'] . ($c['unit'] ? ', ' . $c['unit'] : ''));
        }
        if ($vis['preliminary']) {
            $head[] = $L['prelim_col'];
        }
        $wide = [$head];
        foreach ($records as $r) {
            $s = $r['sample'];
            $row = [
                $s->customer_code ?: '',
                $s->code,
                $s->sample_number,
                $s->customerDepartment?->name ?? '',
                $s->registered_at?->format('d.m.Y') ?? '',
                $this->stateLabel($s, $r['stage'], $L),
            ];
            $pre = [];
            foreach ($columns as $eid => $c) {
                $v = $r['vals'][$eid] ?? null;
                if (! $v || $v['value'] === null) {
                    $row[] = $v ? $L['cell_' . $v['state']] : '';
                    continue;
                }
                $row[] = ($v['unit'] && $c['unit'] && $v['unit'] !== $c['unit'])
                    ? (is_float($v['value']) ? self::num($v['value']) : $v['value']) . ' ' . $v['unit']
                    : $v['value'];
                if ($v['state'] === 'preliminary') {
                    $pre[] = $c['symbol'];
                }
            }
            if ($vis['preliminary']) {
                $row[] = implode(', ', array_unique($pre));
            }
            $wide[] = $row;
        }

        // ---- 2-varaq: Batafsil (har bir natija — qator) ----
        $detail = [[$L['customer_code'], $L['lab_code'], $L['registered'], $L['element'], $L['element_name'], $L['value'], $L['unit'], $L['status'], $L['entered'], $L['worksheet']]];
        foreach ($records as $r) {
            $s = $r['sample'];
            foreach ($columns as $eid => $c) {
                $v = $r['vals'][$eid] ?? null;
                if (! $v) {
                    continue;
                }
                $detail[] = [
                    $s->customer_code ?: '', $s->code, $s->registered_at?->format('d.m.Y') ?? '',
                    $c['symbol'], $c['name'], $v['value'] ?? '', $v['unit'] ?? '',
                    $L['st_' . $v['state']], $v['date'] ?? '', $v['ws'] ?? '',
                ];
            }
        }

        // ---- [P28] Varaqlar bo'yicha jami (korotochkalar yig'indisi) — faqat o'z namunalari bo'yicha ----
        $totalsSheet = null;
        if ($vis['totals'] && $vis['analyses'] && $samples->isNotEmpty()) {
            $wsIds = [];
            foreach ($this->analyses->worksheetMeta($samples->pluck('id')->map(fn ($v) => (int) $v)->all()) as $list) {
                foreach ($list as $m) {
                    $wsIds[] = $m['worksheet_id'];
                }
            }
            $wsTotals = SvodkaGate::totals($this->visibility->filterTotals(array_values($this->analyses->worksheetTotals($wsIds, $scope['ids'] ?: null))));
            if ($wsTotals) {
                $totalsSheet = [[$L['worksheet'], $L['kind'], $L['template'], $L['ws_date'], $L['status'], $L['samples'], $L['element_name'], $L['value'], $L['unit']]];
                foreach ($wsTotals as $t) {
                    foreach ($t['totals'] as $c) {
                        $tplName = $locale === 'ru' ? ($t['template']['name_ru'] ?: $t['template']['name_uz']) : $t['template']['name_uz'];
                        $totalsSheet[] = [
                            (string) $t['number'],
                            $L['kind_' . ($t['kind'] === 'control' ? 'control' : 'main')],
                            trim(($tplName ?? '') . ' · ' . ($t['template']['code'] ?? ''), ' ·'),
                            $t['worksheet_date'] ? date('d.m.Y', strtotime($t['worksheet_date'])) : '',
                            $t['preliminary'] ? $L['st_preliminary'] : $L['st_final'],
                            $t['partial'] ? $t['samples_own'] . ' / ' . $t['samples_all'] : (string) $t['samples_all'],
                            ($locale === 'ru' ? ($c['label_ru'] ?: $c['label_uz']) : $c['label_uz']),
                            $c['value'] !== null && is_numeric($c['value']) ? (float) $c['value'] : '',
                            $c['unit'] ?? '',
                        ];
                    }
                }
            }
        }

        // ---- 3-varaq: Ma'lumot ----
        $deps = $scope['ids'] ? CustomerDepartment::whereIn('id', $scope['ids'])->orderBy('name')->pluck('name')->implode(', ') : $L['all_departments'];
        $info = [
            [$L['info'], ''],
            [$L['department'], $request->filled('department_id') ? (CustomerDepartment::find((int) $request->input('department_id'))?->name ?? '') : $deps],
            [$L['period'], ($request->input('date_from') ?: '…') . ' — ' . ($request->input('date_to') ?: '…')],
            [$L['filter'], $L['tab_' . ($status ?: 'all')] ?? $status],
            [$L['search'], $search ?: '—'],
            [$L['count'], (string) count($records)],
            [$L['created'], now()->format('d.m.Y H:i') . ' · ' . $request->user()->name],
            [$L['note'], $vis['preliminary'] ? $L['note_prelim'] : $L['note_final']],
        ];
        if ($totalsSheet) {
            $info[] = ['', $L['note_totals']];
        }

        $name = 'natijalar_' . now()->format('Y-m-d_Hi');
        if (SimpleXlsx::available()) {
            $widths = array_merge([14, 18, 12, 24, 12, 22], array_fill(0, count($columns), 16), $vis['preliminary'] ? [18] : []);
            $x = (new SimpleXlsx())
                ->sheet($L['sheet_results'], $wide, $widths)
                ->sheet($L['sheet_detail'], $detail, [14, 18, 12, 9, 26, 12, 10, 16, 17, 12]);
            if ($totalsSheet) {
                $x->sheet($L['sheet_totals'], $totalsSheet, [12, 12, 30, 12, 16, 12, 32, 12, 8]);
            }
            $path = $x->sheet($L['info'], $info, [22, 70])->save();

            return response()->download($path, $name . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        // Zaxira: CSV (zip kengaytmasi yo'q bo'lsa) — UTF-8 BOM, «;» bilan
        return response()->streamDownload(function () use ($wide) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($wide as $r) {
                fputcsv($out, $r, ';');
            }
            fclose($out);
        }, $name . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Portal sahifasidagi bilan bir xil qoida: nazorat va qayta tahlildagi varaqlar hisobga olinmaydi */
    private function preliminary(Sample $s): array
    {
        if ($s->sampleElements->every(fn ($se) => $se->status === 'done' && $se->result_value !== null)) {
            return [];
        }
        $out = [];
        foreach ($this->analyses->blocks($s, false) as $b) {
            if ($b['kind'] === 'control' || $b['member_status'] === 'retest') {
                continue;
            }
            foreach ($b['summary_columns'] as $c) {
                $v = ((array) $b['summary'])[$c['key']] ?? null;
                if ($c['element_symbol'] && $v !== null && $v !== '') {
                    if (is_numeric($v)) {
                        $v = $c['decimals'] !== null ? round((float) $v, (int) $c['decimals']) : (float) $v;
                    }
                    $out[$c['element_symbol']] = ['value' => $v, 'unit' => $c['unit'], 'worksheet' => $b['number']];
                }
            }
        }

        return $out;
    }

    private function stateLabel(Sample $s, ?string $stage, array $L): string
    {
        if ($stage && isset($L['step_' . $stage])) {
            return $L['step_' . $stage];
        }

        return in_array($s->status, ['completed', 'archived'], true) ? $L['tab_done'] : $L['tab_active'];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }

    public static function labels(string $locale): array
    {
        $all = [
            'uz' => [
                'sheet_results' => 'Natijalar', 'sheet_detail' => 'Batafsil', 'info' => "Ma'lumot",
                'sheet_totals' => 'Varaqlar jami', 'kind' => 'Turi', 'kind_main' => 'Asosiy', 'kind_control' => 'Nazorat',
                'template' => 'Shablon (usul)', 'ws_date' => 'Varaq sanasi', 'samples' => 'Namunalar',
                'note_totals' => "«Varaqlar jami» — har varaq bo'yicha jami faqat sizning namunalaringiz qatorlaridan; «3 / 6» — varaqdagi 6 tadan 3 tasi sizniki.",
                'customer_code' => 'Shifr', 'lab_code' => 'Laboratoriya shifri', 'number' => 'Namuna raqami',
                'department' => "Bo'lim", 'registered' => 'Topshirilgan', 'state' => 'Bosqich',
                'prelim_col' => 'Dastlabki (tasdiqlanmagan)', 'element' => 'Belgi', 'element_name' => "Ko'rsatkich",
                'value' => 'Natija', 'unit' => 'Birlik', 'status' => 'Holati', 'entered' => 'Kiritilgan', 'worksheet' => 'Ishchi varaq',
                'cell_waiting' => '', 'cell_hidden' => '', 'cell_final' => '', 'cell_preliminary' => '',
                'st_final' => 'Tasdiqlangan', 'st_preliminary' => 'Dastlabki', 'st_waiting' => 'Kutilmoqda', 'st_hidden' => "Ko'rsatilmaydi", 'st_locked' => 'Tasdiqlash kutilmoqda', 'cell_locked' => '',
                'step_registered' => 'Qabul qilindi', 'step_assigned' => 'Tahlilga uzatildi', 'step_analysis' => 'Tahlil jarayonida',
                'step_review' => 'Natijalar tekshirilmoqda', 'step_done' => 'Natijalar tayyor', 'step_archived' => 'Arxivda',
                'tab_active' => 'Jarayonda', 'tab_done' => 'Tayyor', 'tab_all' => 'Hammasi',
                'all_departments' => "Barcha bo'limlar", 'period' => 'Davr', 'filter' => 'Holat filtri', 'search' => 'Qidiruv',
                'count' => 'Namunalar soni', 'created' => 'Yuklab olindi',
                'note' => 'Izoh', 'note_prelim' => "«Dastlabki» — ishchi varaq hali tasdiqlanmagan; qiymat o'zgarishi mumkin.",
                'note_final' => 'Faqat tasdiqlangan natijalar.',
                'too_many' => "Juda ko'p namuna (:n dan ortiq) — davr yoki filtrni toraytiring",
            ],
            'ru' => [
                'sheet_results' => 'Результаты', 'sheet_detail' => 'Подробно', 'info' => 'Сведения',
                'sheet_totals' => 'Итоги по листам', 'kind' => 'Вид', 'kind_main' => 'Основной', 'kind_control' => 'Контроль',
                'template' => 'Шаблон (методика)', 'ws_date' => 'Дата листа', 'samples' => 'Пробы',
                'note_totals' => '«Итоги по листам» — итог по каждому листу только по строкам ваших проб; «3 / 6» — ваши 3 из 6 проб листа.',
                'customer_code' => 'Шифр', 'lab_code' => 'Шифр лаборатории', 'number' => 'Номер пробы',
                'department' => 'Подразделение', 'registered' => 'Сдана', 'state' => 'Этап',
                'prelim_col' => 'Предварительные (не утверждены)', 'element' => 'Символ', 'element_name' => 'Показатель',
                'value' => 'Результат', 'unit' => 'Ед.', 'status' => 'Статус', 'entered' => 'Внесён', 'worksheet' => 'Рабочий лист',
                'cell_waiting' => '', 'cell_hidden' => '', 'cell_final' => '', 'cell_preliminary' => '',
                'st_final' => 'Утверждён', 'st_preliminary' => 'Предварительный', 'st_waiting' => 'Ожидается', 'st_hidden' => 'Не показывается', 'st_locked' => 'Ожидает выпуска', 'cell_locked' => '',
                'step_registered' => 'Принята', 'step_assigned' => 'Передана на анализ', 'step_analysis' => 'Идёт анализ',
                'step_review' => 'Результаты проверяются', 'step_done' => 'Результаты готовы', 'step_archived' => 'В архиве',
                'tab_active' => 'В работе', 'tab_done' => 'Готовые', 'tab_all' => 'Все',
                'all_departments' => 'Все подразделения', 'period' => 'Период', 'filter' => 'Фильтр статуса', 'search' => 'Поиск',
                'count' => 'Количество проб', 'created' => 'Выгружено',
                'note' => 'Примечание', 'note_prelim' => '«Предварительный» — рабочий лист ещё не утверждён; значение может измениться.',
                'note_final' => 'Только утверждённые результаты.',
                'too_many' => 'Слишком много проб (более :n) — сузьте период или фильтр',
            ],
            'en' => [
                'sheet_results' => 'Results', 'sheet_detail' => 'Details', 'info' => 'Info',
                'sheet_totals' => 'Worksheet totals', 'kind' => 'Kind', 'kind_main' => 'Main', 'kind_control' => 'Control',
                'template' => 'Template (method)', 'ws_date' => 'Worksheet date', 'samples' => 'Samples',
                'note_totals' => '"Worksheet totals" — each worksheet total uses only the rows of your samples; "3 / 6" — 3 of the 6 samples are yours.',
                'customer_code' => 'Code', 'lab_code' => 'Lab code', 'number' => 'Sample no.',
                'department' => 'Department', 'registered' => 'Submitted', 'state' => 'Stage',
                'prelim_col' => 'Preliminary (not approved)', 'element' => 'Symbol', 'element_name' => 'Analyte',
                'value' => 'Result', 'unit' => 'Unit', 'status' => 'Status', 'entered' => 'Entered', 'worksheet' => 'Worksheet',
                'cell_waiting' => '', 'cell_hidden' => '', 'cell_final' => '', 'cell_preliminary' => '',
                'st_final' => 'Approved', 'st_preliminary' => 'Preliminary', 'st_waiting' => 'Pending', 'st_hidden' => 'Not shown', 'st_locked' => 'Awaiting release', 'cell_locked' => '',
                'step_registered' => 'Received', 'step_assigned' => 'Sent for analysis', 'step_analysis' => 'Analysis in progress',
                'step_review' => 'Results under review', 'step_done' => 'Results ready', 'step_archived' => 'Archived',
                'tab_active' => 'In progress', 'tab_done' => 'Ready', 'tab_all' => 'All',
                'all_departments' => 'All departments', 'period' => 'Period', 'filter' => 'Status filter', 'search' => 'Search',
                'count' => 'Samples', 'created' => 'Exported',
                'note' => 'Note', 'note_prelim' => '"Preliminary" — the worksheet is not approved yet; the value may change.',
                'note_final' => 'Approved results only.',
                'too_many' => 'Too many samples (over :n) — narrow the period or filter',
            ],
        ];

        return $all[$locale] ?? $all['uz'];
    }
}
