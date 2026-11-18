<?php

namespace App\Services\Samples;

use App\Models\CustomerDepartment;
use App\Models\Element;
use App\Models\RequestSchedule;
use App\Models\Sample;
use App\Models\SampleRequestItem;
use App\Models\ScheduleRun;
use App\Models\ScheduleSheet;
use App\Models\ScheduleSheetValue;
use App\Models\User;
use App\Services\Svodka\SvodkaGate;
use App\Services\Worksheets\Formula;
use App\Services\Worksheets\FormulaException;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * [Paket 37] Grafik jadvallari — Excel ko'rinishidagi kunlik jadval.
 *
 *   Qatorlar — grafik partiyalari (schedule_runs) vaqt bo'yicha; ustunlarni admin belgilaydi:
 *     input   — laboratoriya qo'lda kiritadi (oq)
 *     result  — grafik qatoridagi N-namunaning ko'rsatkich natijasi (tasdiqlangan); natija bo'lmasa — qo'lda (oq)
 *     formula — shu qatordagi boshqa ustunlardan (sariq)  — ishchi varaq formulalari bilan bir xil sintaksis
 *     average — tanlangan ustunning o'rtachasi (sariq): mode=day (kun bo'yicha) | running (shu qatorgacha)
 *   Formula va o'rtacha saqlanmaydi — har safar hisoblanadi.
 */
class ScheduleSheets
{
    public const KINDS = ['input', 'result', 'formula', 'average'];
    public const MAX_COLUMNS = 60;

    public static function available(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('schedule_sheets') && Schema::hasTable('schedule_sheet_values') && RequestSchedules::available();
    }

    /* =============================================================== ustunlar (admin) */

    /** @return array{0: array, 1: int[]} ustunlar va grafik qatorlari (tozalangan) */
    public function cleanDefinition(int $deptId, array $columns, array $scheduleIds): array
    {
        $errors = [];
        $out = [];
        $keys = [];
        $elements = Element::pluck('id')->map(fn ($v) => (int) $v)->flip();
        foreach (array_values($columns) as $i => $c) {
            $key = strtolower(trim((string) ($c['key'] ?? '')));
            $kind = in_array($c['kind'] ?? null, self::KINDS, true) ? $c['kind'] : null;
            $title = trim((string) ($c['title'] ?? ''));
            $p = "columns.$i";
            if (! preg_match('/^[a-z_][a-z0-9_]{0,39}$/', $key)) {
                $errors["$p.key"][] = ($i + 1) . "-ustun: kalit lotin harf/raqam/_ bilan, harfdan boshlansin (masalan zichlik, p015)";
            } elseif (isset($keys[$key])) {
                $errors["$p.key"][] = ($i + 1) . "-ustun: «{$key}» kaliti takrorlangan";
            } elseif (in_array($key, Formula::FUNCTIONS, true)) {
                $errors["$p.key"][] = ($i + 1) . "-ustun: «{$key}» — funksiya nomi, boshqa kalit tanlang";
            }
            if (! $kind) {
                $errors["$p.kind"][] = ($i + 1) . '-ustun: turini tanlang';
            }
            if ($title === '') {
                $errors["$p.title"][] = ($i + 1) . '-ustun: sarlavhani kiriting';
            }
            $col = [
                'key'      => $key,
                'kind'     => $kind,
                'title'    => mb_substr($title, 0, 200),
                'unit'     => mb_substr(trim((string) ($c['unit'] ?? '')), 0, 30) ?: null,
                'decimals' => is_numeric($c['decimals'] ?? null) ? max(0, min(6, (int) $c['decimals'])) : 2,
            ];
            if ($kind === 'result') {
                $col['item'] = max(1, (int) ($c['item'] ?? 0));
                $col['element_id'] = (int) ($c['element_id'] ?? 0);
                if (! (int) ($c['item'] ?? 0)) {
                    $errors["$p.item"][] = ($i + 1) . "-ustun: grafik qatoridagi namuna tartib raqamini kiriting";
                }
                if (! $elements->has($col['element_id'])) {
                    $errors["$p.element_id"][] = ($i + 1) . "-ustun: ko'rsatkichni tanlang";
                }
            } elseif ($kind === 'formula') {
                $col['formula'] = trim((string) ($c['formula'] ?? ''));
            } elseif ($kind === 'average') {
                $col['source'] = strtolower(trim((string) ($c['source'] ?? '')));
                $col['mode'] = ($c['mode'] ?? 'day') === 'running' ? 'running' : 'day';
            }
            $keys[$key] = $kind;
            $out[] = $col;
        }
        if (! $out) {
            $errors['columns'][] = "Kamida bitta ustun qo'shing";
        }
        if (count($out) > self::MAX_COLUMNS) {
            $errors['columns'][] = 'Ustunlar ' . self::MAX_COLUMNS . ' tadan oshmasin';
        }

        // formulalar: sintaksis, faqat shu qatordagi (o'rtachadan boshqa) ustunlar, aylana bog'lanish yo'q
        $rowKeys = array_keys(array_filter($keys, fn ($k) => $k !== 'average'));
        $asts = [];
        foreach ($out as $i => $c) {
            if ($c['kind'] === 'formula') {
                try {
                    $asts[$c['key']] = Formula::parse($c['formula']);
                    foreach (Formula::identifiers($asts[$c['key']]) as $id) {
                        if ($id === $c['key'] || ! in_array($id, $rowKeys, true)) {
                            $errors["columns.$i.formula"][] = ($i + 1) . "-ustun: «{$id}» — bunday ustun yo'q (o'rtacha ustunlarni formulada ishlatib bo'lmaydi)";
                        }
                    }
                } catch (FormulaException $e) {
                    $errors["columns.$i.formula"][] = ($i + 1) . '-ustun: ' . $e->getMessage();
                }
            } elseif ($c['kind'] === 'average' && ! in_array($c['source'], $rowKeys, true)) {
                $errors["columns.$i.source"][] = ($i + 1) . "-ustun: qaysi ustunning o'rtachasi — tanlang";
            }
        }
        if (! $errors && $asts && $this->order($out, $asts)[1]) {
            $errors['columns'][] = "Formulalar bir-biriga aylana bog'langan — tekshiring";
        }

        $valid = RequestSchedule::where('customer_department_id', $deptId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $sched = array_values(array_intersect(array_map('intval', $scheduleIds), $valid));

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [$out, $sched];
    }

    /** Formula ustunlari hisoblash tartibi: [tartib, aylanadagilar] */
    private function order(array $columns, array $asts): array
    {
        $deps = [];
        foreach ($columns as $c) {
            if ($c['kind'] === 'formula') {
                $deps[$c['key']] = array_values(array_filter(Formula::identifiers($asts[$c['key']]), fn ($id) => isset($asts[$id])));
            }
        }
        $order = [];
        $done = [];
        do {
            $progress = false;
            foreach ($deps as $k => $d) {
                if (! isset($done[$k]) && ! array_diff($d, array_keys($done))) {
                    $done[$k] = true;
                    $order[] = $k;
                    $progress = true;
                }
            }
        } while ($progress);

        return [$order, array_values(array_diff(array_keys($deps), $order))];
    }

    /* =============================================================== jadval (kun) */

    /** @return Collection<ScheduleRun> */
    public function runs(ScheduleSheet $sheet, string $date): Collection
    {
        app(RequestSchedules::class)->ensureRuns($date, [(int) $sheet->customer_department_id]);
        $ids = array_map('intval', $sheet->schedule_ids ?? []);

        return ScheduleRun::where('customer_department_id', $sheet->customer_department_id)
            ->whereDate('run_date', $date)
            ->when($ids, fn ($q) => $q->whereIn('schedule_id', $ids))
            ->orderBy('planned_time')->orderBy('id')->get();
    }

    /**
     * @param bool $portal buyurtmachi uchun: jamlanma hisobot rejimida chiqarilmagan natija ko'rinmaydi
     */
    public function grid(ScheduleSheet $sheet, string $date, bool $portal = false, bool $canEdit = false): array
    {
        $date = Carbon::parse($date)->toDateString();
        $cols = array_values($sheet->columns ?? []);
        $runs = $this->runs($sheet, $date);
        $stored = ScheduleSheetValue::where('sheet_id', $sheet->id)->whereIn('run_id', $runs->pluck('id')->all() ?: [0])->get()
            ->groupBy('run_id')->map(fn ($g) => $g->mapWithKeys(fn ($v) => [$v->column_key => $v->value === null ? null : (float) $v->value]));

        // natija ustunlari: partiya → N-namuna → element natijasi
        $results = $this->results($runs, $cols, $portal);

        $asts = [];
        foreach ($cols as $c) {
            if ($c['kind'] === 'formula') {
                try {
                    $asts[$c['key']] = Formula::parse($c['formula']);
                } catch (Throwable $e) {
                    $asts[$c['key']] = null;
                }
            }
        }
        [$order] = $this->order($cols, array_filter($asts));

        $rows = [];
        $schedules = RequestSchedule::withTrashed()->whereIn('id', $runs->pluck('schedule_id')->all() ?: [0])->pluck('name', 'id');
        $rs = app(RequestSchedules::class);
        foreach ($runs as $n => $run) {
            $vals = [];
            $meta = [];
            foreach ($cols as $c) {
                $k = $c['key'];
                if ($c['kind'] === 'input') {
                    $vals[$k] = $stored[$run->id][$k] ?? null;
                    $meta[$k] = ['editable' => $canEdit];
                } elseif ($c['kind'] === 'result') {
                    $r = $results[$run->id][$k] ?? null;
                    if ($r && $r['locked']) {
                        $vals[$k] = null;
                        $meta[$k] = ['locked' => true];
                    } elseif ($r && $r['value'] !== null) {
                        $vals[$k] = $r['value'];
                        $meta[$k] = ['from' => 'sample', 'sample' => $r['code']];
                    } else {
                        $vals[$k] = $stored[$run->id][$k] ?? null;
                        $meta[$k] = ['editable' => $canEdit, 'pending' => (bool) ($r['pending'] ?? false)];
                    }
                }
            }
            foreach ($order as $k) {
                try {
                    $vals[$k] = $asts[$k] ? Formula::evaluate($asts[$k], $vals) : null;
                } catch (Throwable $e) {
                    $vals[$k] = null;
                    $meta[$k] = ['error' => $e->getMessage()];
                }
            }
            $rows[] = [
                'run_id' => (int) $run->id,
                'n'      => $n + 1,
                'time'   => $run->planned_time,
                'name'   => $schedules[$run->schedule_id] ?? null,
                'status' => $rs->displayStatus($run),
                'values' => $vals,
                'meta'   => $meta,
            ];
        }

        // o'rtachalar: kun bo'yicha yoki shu qatorgacha
        foreach ($cols as $c) {
            if ($c['kind'] !== 'average') {
                continue;
            }
            $sum = 0.0;
            $cnt = 0;
            $all = array_values(array_filter(array_map(fn ($r) => $r['values'][$c['source']] ?? null, $rows), fn ($v) => $v !== null));
            $dayAvg = $all ? array_sum($all) / count($all) : null;
            foreach ($rows as $i => $r) {
                $v = $r['values'][$c['source']] ?? null;
                if ($v !== null) {
                    $sum += $v;
                    $cnt++;
                }
                $rows[$i]['values'][$c['key']] = $c['mode'] === 'running' ? ($cnt ? $sum / $cnt : null) : $dayAvg;
            }
        }

        // yaxlitlash (ko'rsatish uchun)
        foreach ($rows as $i => $r) {
            foreach ($cols as $c) {
                $v = $r['values'][$c['key']] ?? null;
                $rows[$i]['values'][$c['key']] = $v === null ? null : round($v, $c['decimals'] ?? 2);
            }
        }

        return [
            'sheet'   => $this->presentSheet($sheet, false),
            'date'    => $date,
            'columns' => array_map(fn ($c) => array_intersect_key($c, array_flip(['key', 'kind', 'title', 'unit', 'decimals', 'mode'])), $cols),
            'rows'    => $rows,
            'can_edit'=> $canEdit,
        ];
    }

    /** [run_id][col_key] => ['value', 'locked', 'pending', 'code'] */
    private function results(Collection $runs, array $cols, bool $portal): array
    {
        $resCols = array_values(array_filter($cols, fn ($c) => $c['kind'] === 'result'));
        $reqIds = $runs->pluck('sample_request_id')->filter()->all();
        if (! $resCols || ! $reqIds) {
            return [];
        }
        $items = SampleRequestItem::whereIn('sample_request_id', $reqIds)->whereNotNull('sample_id')->get(['sample_request_id', 'position', 'sample_id']);
        $samples = Sample::with('sampleElements:id,sample_id,element_id,result_value,result_unit,status')
            ->whereIn('id', $items->pluck('sample_id')->all() ?: [0])->get()->keyBy('id');
        $gates = $portal ? SvodkaGate::for($samples->values()) : [];
        $byReq = $items->groupBy('sample_request_id')->map(fn ($g) => $g->keyBy('position'));

        $out = [];
        foreach ($runs as $run) {
            if (! $run->sample_request_id) {
                continue;
            }
            foreach ($resCols as $c) {
                $item = $byReq[$run->sample_request_id][$c['item']] ?? null;
                $s = $item ? $samples->get($item->sample_id) : null;
                $se = $s?->sampleElements->firstWhere('element_id', $c['element_id']);
                if (! $se) {
                    continue;
                }
                $final = $se->status === 'done' && $se->result_value !== null;
                $g = $gates[(int) $s->id] ?? null;
                $locked = $final && $g && $g['enabled'] && empty($g['visible'][(int) $c['element_id']]);
                $out[$run->id][$c['key']] = [
                    'value'   => $final && ! $locked ? (float) $se->result_value : null,
                    'locked'  => $locked,
                    'pending' => ! $final,
                    'code'    => $s->code,
                ];
            }
        }

        return $out;
    }

    /** Qo'lda kiritish (input yoki natijasi hali yo'q result ustuni) */
    public function setValue(ScheduleSheet $sheet, ScheduleRun $run, string $key, $value, User $user): void
    {
        $col = collect($sheet->columns ?? [])->firstWhere('key', $key);
        if (! $col || ! in_array($col['kind'], ['input', 'result'], true)) {
            throw ValidationException::withMessages(['key' => ["Bu ustunga qiymat kiritilmaydi (formula yoki o'rtacha)"]]);
        }
        if ((int) $run->customer_department_id !== (int) $sheet->customer_department_id) {
            abort(404);
        }
        if ($col['kind'] === 'result') {
            $r = $this->results(collect([$run]), [$col], false)[$run->id][$key] ?? null;
            if ($r && $r['value'] !== null) {
                throw ValidationException::withMessages(['value' => ["Natija namunadan olingan ({$r['code']}) — o'zgartirish proba kartasida"]]);
            }
        }
        $raw = trim(str_replace([' ', ','], ['', '.'], (string) ($value ?? '')));
        if ($raw === '') {
            ScheduleSheetValue::where(['sheet_id' => $sheet->id, 'run_id' => $run->id, 'column_key' => $key])->delete();

            return;
        }
        if (! is_numeric($raw) || abs((float) $raw) >= 1e13) {
            throw ValidationException::withMessages(['value' => ['Son kiriting']]);
        }
        ScheduleSheetValue::updateOrCreate(
            ['sheet_id' => $sheet->id, 'run_id' => $run->id, 'column_key' => $key],
            ['value' => (float) $raw, 'entered_by' => $user->id, 'entered_at' => now()],
        );
    }

    /* =============================================================== Excel */

    public function xlsx(ScheduleSheet $sheet, array $grid): array
    {
        $head = ['№', 'Boshlanish soati'];
        $yellow = [];
        foreach ($grid['columns'] as $i => $c) {
            $head[] = $c['title'] . ($c['unit'] ? ", {$c['unit']}" : '');
            if (in_array($c['kind'], ['formula', 'average'], true)) {
                $yellow[] = $i + 2;
            }
        }
        $rows = [$head];
        foreach ($grid['rows'] as $r) {
            $row = [$r['n'], $r['time']];
            foreach ($grid['columns'] as $c) {
                $row[] = $r['values'][$c['key']] ?? null;
            }
            $rows[] = $row;
        }
        $widths = array_merge([5, 11], array_fill(0, count($grid['columns']), 18));

        return [$rows, $widths, $yellow];
    }

    /* =============================================================== ko'rinish */

    public function presentSheet(ScheduleSheet $s, bool $full = true): array
    {
        $out = [
            'id'            => (int) $s->id,
            'name'          => $s->name,
            'department_id' => (int) $s->customer_department_id,
            'department'    => CustomerDepartment::whereKey($s->customer_department_id)->value('name'),
            'is_active'     => (bool) $s->is_active,
        ];
        if ($full) {
            $out += [
                'schedule_ids' => array_map('intval', $s->schedule_ids ?? []),
                'columns'      => array_values($s->columns ?? []),
                'sort_order'   => (int) $s->sort_order,
                'updated_at'   => $s->updated_at?->toDateTimeString(),
            ];
        }

        return $out;
    }
}
