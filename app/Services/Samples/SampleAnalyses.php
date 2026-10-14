<?php

namespace App\Services\Samples;

use App\Models\Sample;
use App\Models\Worksheet;
use App\Models\WorksheetRow;
use App\Models\WorksheetSignoff;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proba bo'yicha o'tkazilgan tahlillar:
 *   - bosqichlar (proba qayerda turibdi) — ro'yxat va kartochka uchun;
 *   - ishchi varaqlar: faqat SHU probaning parallellari va natijasi (partiyadagi boshqa probalar ko'rinmaydi);
 *   - nazorat tahlili natijasini asosiy natija bilan solishtirish.
 */
class SampleAnalyses
{
    public const STEPS = ['registered', 'assigned', 'analysis', 'review', 'done'];

    private function hasWorksheets(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('worksheet_samples');
    }

    private function hasKind(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('worksheets', 'kind');
    }

    // ------------------------------------------------------------------ bosqichlar

    /**
     * Ko'p proba uchun varaqlar haqida qisqa ma'lumot (bitta so'rov).
     * @return array<int, array> sample_id => [{worksheet_id, kind, status, created_at, completed_at, approved_at, member_status}]
     */
    public function worksheetMeta(array $sampleIds): array
    {
        if (! $sampleIds || ! $this->hasWorksheets()) {
            return [];
        }
        $cols = ['worksheet_samples.sample_id', 'worksheet_samples.status as member_status', 'worksheets.id', 'worksheets.status',
            'worksheets.created_at', 'worksheets.completed_at', 'worksheets.approved_at'];
        if ($this->hasKind()) {
            $cols[] = 'worksheets.kind';
        }

        $out = [];
        foreach (DB::table('worksheet_samples')
            ->join('worksheets', 'worksheets.id', '=', 'worksheet_samples.worksheet_id')
            ->whereNull('worksheets.deleted_at')
            ->whereIn('worksheet_samples.sample_id', $sampleIds)
            ->get($cols) as $r) {
            $out[(int) $r->sample_id][] = [
                'worksheet_id'  => (int) $r->id,
                'kind'          => ($r->kind ?? null) ?: 'main',
                'status'        => $r->status,
                'member_status' => $r->member_status,
                'created_at'    => $r->created_at,
                'completed_at'  => $r->completed_at,
                'approved_at'   => $r->approved_at,
            ];
        }

        return $out;
    }

    /**
     * Proba harakati bosqichlari: qabul → uzatildi → tahlil → tekshiruv → natija tayyor.
     * @param  array  $meta  worksheetMeta() dagi shu probaning yozuvlari
     */
    public function steps(Sample $sample, array $meta): array
    {
        $main = array_values(array_filter($meta, fn ($m) => $m['kind'] !== 'control'));
        $status = $sample->status;
        $final = in_array($status, ['completed', 'archived'], true);

        $minDate = function (array $dates) {
            $dates = array_values(array_filter($dates));
            if (! $dates) {
                return null;
            }
            sort($dates);

            return (string) $dates[0];
        };
        $ts = fn ($v) => $v ? (is_string($v) ? $v : $v->toDateTimeString()) : null;

        $elementsDone = $sample->relationLoaded('sampleElements')
            ? $sample->sampleElements->where('status', 'done')
            : collect();

        $analysisAt = $minDate(array_merge([$ts($sample->started_at)], array_column($main, 'created_at')));
        $reviewAt = $minDate(array_merge(
            array_column(array_filter($main, fn ($m) => in_array($m['status'], ['completed', 'approved'], true)), 'completed_at'),
            $elementsDone->map(fn ($e) => $ts($e->result_entered_at))->all()
        ));

        $done = [
            'registered' => true,
            'assigned'   => (bool) $sample->assigned_at || in_array($status, ['assigned', 'in_progress', 'completed', 'archived'], true) || $analysisAt,
            'analysis'   => (bool) $analysisAt || in_array($status, ['in_progress', 'completed', 'archived'], true),
            'review'     => (bool) $reviewAt || $final,
            'done'       => $final,
        ];
        $at = [
            'registered' => $ts($sample->registered_at) ?? $ts($sample->created_at),
            'assigned'   => $ts($sample->assigned_at),
            'analysis'   => $analysisAt,
            'review'     => $reviewAt,
            'done'       => $ts($sample->completed_at),
        ];

        $steps = [];
        $current = 'registered';
        foreach (self::STEPS as $key) {
            if ($done[$key]) {
                $current = $key;
            }
            $steps[] = ['key' => $key, 'done' => (bool) $done[$key], 'at' => $at[$key]];
        }

        return [
            'current' => $status === 'archived' ? 'archived' : $current,
            'steps'   => $steps,
            'worksheets' => [
                'main'    => count($main),
                'control' => count($meta) - count($main),
                'open'    => count(array_filter($meta, fn ($m) => $m['status'] !== 'approved')),
            ],
        ];
    }

    // --------------------------------------------------------- ishchi varaqlar (faqat shu proba)

    /** Proba ishtirok etgan varaqlar — har birida faqat shu probaning qatorlari va natijasi */
    public function blocks(Sample $sample, bool $includeNotes = true): array
    {
        if (! $this->hasWorksheets()) {
            return [];
        }
        $sid = (int) $sample->id;

        $worksheets = Worksheet::query()
            ->whereIn('id', fn ($q) => $q->select('worksheet_id')->from('worksheet_samples')->where('sample_id', $sid))
            ->with(['members' => fn ($q) => $q->where('sample_id', $sid)])
            ->orderBy('worksheet_date')->orderBy('id')
            ->get();
        if ($worksheets->isEmpty()) {
            return [];
        }

        $rows = WorksheetRow::query()->whereIn('worksheet_id', $worksheets->pluck('id')->all())
            ->where('sample_id', $sid)->orderBy('row_no')->orderBy('id')->get()->groupBy('worksheet_id');
        $signoffs = WorksheetSignoff::query()->whereIn('worksheet_id', $worksheets->pluck('id')->all())
            ->get(['worksheet_id', 'stage', 'slot', 'performed_at', 'performer_id', 'performer_name'])->groupBy('worksheet_id');

        $out = [];
        foreach ($worksheets as $w) {
            $def = $w->definition();
            $member = $w->members->first();
            $rowCols = [];
            $sumCols = [];
            foreach ($def['columns'] ?? [] as $c) {
                $scope = $c['scope'] ?? 'row';
                $col = [
                    'key'            => $c['key'],
                    'type'           => $c['type'] ?? 'input',
                    'stage'          => $c['stage'] ?? null,
                    'label_uz'       => $c['label_uz'] ?? $c['key'],
                    'label_ru'       => $c['label_ru'] ?? null,
                    'unit'           => $c['unit'] ?? null,
                    'decimals'       => $c['decimals'] ?? null,
                    'element_symbol' => $c['element_symbol'] ?? null,
                ];
                if ($scope === 'row') {
                    $rowCols[] = $col;
                } elseif ($scope === 'summary') {
                    $sumCols[] = $col;
                }
                // 'total' — partiya bo'yicha jami (boshqa probalar ham kiradi) — ko'rsatilmaydi
            }

            $group = $w->results['groups'][(string) $sid] ?? null;
            if (! $group && count($w->results['groups'] ?? []) === 0) {
                $group = ['summary' => $w->results['summary'] ?? [], 'tolerance' => $w->results['tolerance'] ?? []];
            }

            $stageDone = [];
            foreach ($signoffs->get($w->id, collect()) as $s) {
                if ($s->performed_at || $s->performer_id || $s->performer_name) {
                    $date = $s->performed_at ? (is_string($s->performed_at) ? $s->performed_at : $s->performed_at->toDateString()) : null;
                    $stageDone[$s->stage] = max($stageDone[$s->stage] ?? '', (string) $date) ?: true;
                }
            }

            $out[] = [
                'id'             => (int) $w->id,
                'number'         => $w->number,
                'kind'           => ($this->hasKind() ? $w->kind : null) ?: 'main',
                'status'         => $w->status,
                'worksheet_date' => $w->worksheet_date?->toDateString(),
                'created_at'     => $w->created_at?->toDateTimeString(),
                'printed_at'     => $w->printed_at?->toDateTimeString(),
                'completed_at'   => $w->completed_at?->toDateTimeString(),
                'approved_at'    => $w->approved_at?->toDateTimeString(),
                'preliminary'    => $w->status !== 'approved',
                'member_status'  => $member?->status ?? 'active',
                'template'       => [
                    'code'    => $def['code'] ?? null,
                    'name_uz' => $def['name_uz'] ?? null,
                    'name_ru' => $def['name_ru'] ?? null,
                ],
                'stages'         => array_map(fn ($s) => [
                    'key'      => $s['key'],
                    'label_uz' => $s['label_uz'] ?? $s['key'],
                    'label_ru' => $s['label_ru'] ?? null,
                    'done'     => isset($stageDone[$s['key']]),
                    'date'     => isset($stageDone[$s['key']]) && is_string($stageDone[$s['key']]) ? $stageDone[$s['key']] : null,
                ], $def['stages'] ?? []),
                'params'         => array_map(fn ($p) => [
                    'key'      => $p['key'],
                    'label_uz' => $p['label_uz'] ?? $p['key'],
                    'label_ru' => $p['label_ru'] ?? null,
                    'unit'     => $p['unit'] ?? null,
                    'decimals' => $p['decimals'] ?? null,
                    'value'    => $w->header_values[$p['key']] ?? null,
                ], $def['params'] ?? []),
                'columns'        => $rowCols,
                'summary_columns' => $sumCols,
                'rows'           => $rows->get($w->id, collect())->map(function (WorksheetRow $r) use ($rowCols, $includeNotes) {
                    $values = [];
                    foreach ($rowCols as $c) {
                        $values[$c['key']] = $c['type'] === 'computed'
                            ? ($r->computed[$c['key']] ?? null)
                            : ($r->inputs[$c['key']] ?? null);
                    }

                    return [
                        'parallel_no' => (int) ($r->parallel_no ?: $r->row_no),
                        'values'      => $values,
                        'excluded'    => (bool) $r->excluded,
                        'is_extra'    => (bool) $r->is_extra,
                        'note'        => $includeNotes ? $r->note : null,
                    ];
                })->values()->all(),
                'summary'        => (object) ($group['summary'] ?? []),
                'tolerance'      => (object) ($group['tolerance'] ?? []),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------- nazorat ↔ asosiy solishtirish

    /**
     * Nazorat varaqlari natijasi asosiy natija bilan:
     *   asosiy = probaga yozilgan natija (sample_elements); yo'q bo'lsa — oxirgi asosiy varaqdagi (dastlabki).
     */
    public function comparison(Sample $sample, ?array $blocks = null): array
    {
        $blocks ??= $this->blocks($sample, false);
        $controls = array_values(array_filter($blocks, fn ($b) => $b['kind'] === 'control'));
        if (! $controls) {
            return [];
        }

        $sample->loadMissing('sampleElements.element:id,symbol');
        $finals = [];
        foreach ($sample->sampleElements as $se) {
            if ($se->status === 'done' && $se->result_value !== null && $se->element) {
                $finals[$se->element->symbol] = ['value' => (float) $se->result_value, 'unit' => $se->result_unit];
            }
        }
        $prelim = [];
        foreach ($blocks as $b) {
            if ($b['kind'] === 'control' || $b['member_status'] === 'retest') {
                continue;
            }
            foreach ($b['summary_columns'] as $c) {
                $v = ((array) $b['summary'])[$c['key']] ?? null;
                if ($c['element_symbol'] && $v !== null) {
                    $prelim[$c['element_symbol']] = ['value' => (float) $v, 'unit' => $c['unit'], 'worksheet' => $b['number']];
                }
            }
        }

        $out = [];
        foreach ($controls as $b) {
            $items = [];
            foreach ($b['summary_columns'] as $c) {
                $control = ((array) $b['summary'])[$c['key']] ?? null;
                $symbol = $c['element_symbol'];
                $main = $symbol ? ($finals[$symbol] ?? $prelim[$symbol] ?? null) : null;
                $mainValue = $main['value'] ?? null;
                $diff = $control !== null && $mainValue !== null ? (float) $control - $mainValue : null;
                $items[] = [
                    'key'            => $c['key'],
                    'label_uz'       => $c['label_uz'],
                    'label_ru'       => $c['label_ru'],
                    'element_symbol' => $symbol,
                    'unit'           => $c['unit'],
                    'decimals'       => $c['decimals'],
                    'control'        => $control !== null ? (float) $control : null,
                    'main'           => $mainValue,
                    'main_source'    => $main ? (isset($finals[$symbol]) ? 'result' : 'worksheet') : null,
                    'main_worksheet' => $main['worksheet'] ?? null,
                    'diff'           => $diff !== null ? round($diff, 6) : null,
                    'rel_pct'        => $diff !== null && abs((float) $mainValue) > 1e-12 ? round($diff / $mainValue * 100, 2) : null,
                ];
            }
            $out[] = [
                'worksheet_id'   => $b['id'],
                'number'         => $b['number'],
                'status'         => $b['status'],
                'worksheet_date' => $b['worksheet_date'],
                'template'       => $b['template'],
                'items'          => $items,
            ];
        }

        return $out;
    }
}
