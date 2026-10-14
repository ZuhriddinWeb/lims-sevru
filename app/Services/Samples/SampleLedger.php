<?php

namespace App\Services\Samples;

use App\Models\Sample;
use App\Models\SampleQuantityMovement;
use App\Models\Worksheet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proba miqdori hisobi (qoldiq).
 *
 *   qoldiq = qabul qilingan + tuzatishlar − asosiy varaqlar − nazorat varaqlari − boshqa sarf/qaytarish/utilizatsiya
 *   foydalanish mumkin = qoldiq − arxiv uchun ajratilgan qism
 *
 * Ishchi varaq sarfi alohida yozilmaydi — har safar varaqdan hisoblanadi:
 * probaning har bir paralleli uchun «proba sarfi» ustunidagi qiymat (bo'sh bo'lsa — shablondagi standart naveska).
 * Shuning uchun naveska tuzatilsa, parallel qo'shilsa yoki varaq o'chirilsa — qoldiq o'zi to'g'rilanadi.
 *   - o'chirilgan varaqlar hisobga olinmaydi;
 *   - «hisobga olinmaydi» deb belgilangan parallel ham sarf hisoblanadi (proba baribir olingan);
 *   - qoralama / chop etilgan varaqdagi sarf — «rejada» (hali tortilmagan bo'lishi mumkin).
 */
class SampleLedger
{
    private const EPS = 1e-9;

    /** @var array<int, string[]> shablon → belgilangan sarf ustunlari */
    private array $flaggedKeys = [];

    // ---------------------------------------------------------------- sarf ustuni

    /**
     * Varaq ta'rifidagi proba sarfi ustuni.
     * @return array{key:string, unit:?string, default:?float, label:?string}|null
     */
    public function consumeColumn(array $definition, ?int $templateId = null): ?array
    {
        $columns = array_values(array_filter($definition['columns'] ?? [], fn ($c) =>
            ($c['scope'] ?? 'row') === 'row' && ($c['type'] ?? 'input') === 'input'));

        $pick = null;
        foreach ($columns as $c) {
            if (! empty($c['consumes_sample'])) {
                $pick = $c;
                break;
            }
        }

        // Eski nusxa (belgi paydo bo'lishidan oldin yaratilgan varaq) — shablondagi belgidan yoki «mass» kalitidan
        $hasFlagInfo = (bool) array_filter($columns, fn ($c) => array_key_exists('consumes_sample', $c));
        if (! $pick && ! $hasFlagInfo) {
            $keys = $templateId ? $this->templateFlaggedKeys($templateId) : [];
            foreach ($columns as $c) {
                if (in_array($c['key'], $keys, true)) {
                    $pick = $c;
                    break;
                }
            }
            if (! $pick) {
                foreach ($columns as $c) {
                    if (($c['key'] ?? null) === 'mass') {
                        $pick = $c;
                        break;
                    }
                }
            }
        }

        if (! $pick) {
            return null;
        }

        return [
            'key'     => $pick['key'],
            'unit'    => $pick['unit'] ?? null,
            'default' => QuantityUnit::number($pick['default_value'] ?? null),
            'label'   => $pick['label_uz'] ?? $pick['key'],
        ];
    }

    /** Jadval/ustun bormi (jarayon davomida keshlanadi) */
    private static function hasSchema(string $table, ?string $column = null): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;

        return $cache[$key] ??= $column ? Schema::hasColumn($table, $column) : Schema::hasTable($table);
    }

    private function templateFlaggedKeys(int $templateId): array
    {
        if (! array_key_exists($templateId, $this->flaggedKeys)) {
            $this->flaggedKeys[$templateId] = self::hasSchema('worksheet_template_columns', 'consumes_sample')
                ? DB::table('worksheet_template_columns')->where('template_id', $templateId)
                    ->where('consumes_sample', true)->pluck('key')->all()
                : [];
        }

        return $this->flaggedKeys[$templateId];
    }

    // ------------------------------------------------------------------ hisob

    /**
     * Ko'p proba uchun qisqa hisob (ro'yxatlar, nomzodlar, hisobot).
     * @param  iterable<Sample>  $samples
     * @return array<int, array> sample_id => summary
     */
    public function summaries(iterable $samples, bool $withDetails = false): array
    {
        $samples = collect($samples)->filter()->keyBy(fn ($s) => (int) $s->id);
        if ($samples->isEmpty()) {
            return [];
        }
        $ids = $samples->keys()->all();
        $usage = $this->worksheetUsage($ids);
        $movements = SampleQuantityMovement::query()->whereIn('sample_id', $ids)
            ->when($withDetails, fn ($q) => $q->with('creator:id,name'))
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('sample_id');

        $out = [];
        foreach ($samples as $id => $sample) {
            $out[$id] = $this->build($sample, $usage[$id] ?? [], $movements->get($id, collect()), $withDetails);
        }

        return $out;
    }

    public function summary(Sample $sample): array
    {
        return $this->summaries([$sample])[(int) $sample->id];
    }

    /** Bitta proba: hisob + varaqlar bo'yicha sarf + qo'lda harakatlar */
    public function detail(Sample $sample): array
    {
        return $this->summaries([$sample], true)[(int) $sample->id];
    }

    private function build(Sample $sample, array $entries, Collection $movements, bool $withDetails): array
    {
        $received = QuantityUnit::number($sample->received_quantity);
        $retained = QuantityUnit::number($sample->retained_quantity);
        $unit = QuantityUnit::normalize($sample->quantity_unit);

        if (! $unit) {
            // Birlik ko'rsatilmagan — birinchi varaq ustunining birligida ko'rsatamiz (qoldiq noma'lum)
            foreach ($entries as $e) {
                if ($u = QuantityUnit::normalize($e['column_unit'])) {
                    $unit = $u;
                    break;
                }
            }
        }

        $consumed = ['main' => 0.0, 'control' => 0.0, 'other' => 0.0];
        $planned = 0.0;
        $issues = [];
        foreach ($entries as &$e) {
            $e['quantity_converted'] = null;
            if ($e['quantity'] === null) {
                $e['issue'] = 'no_column';
                $issues[] = 'no_column';
                continue;
            }
            $from = QuantityUnit::normalize($e['column_unit']) ?: $unit;
            $conv = $unit && $from ? QuantityUnit::convert($e['quantity'], $from, $unit) : null;
            if ($conv === null) {
                $e['issue'] = 'unit_mismatch';
                $issues[] = 'unit_mismatch';
                continue;
            }
            $e['quantity_converted'] = QuantityUnit::round($conv);
            $consumed[$e['kind'] === 'control' ? 'control' : 'main'] += $conv;
            if ($e['planned']) {
                $planned += $conv;
            }
        }
        unset($e);

        $corrections = 0.0;
        $byKind = ['other' => 0.0, 'return' => 0.0, 'dispose' => 0.0];
        $moveRows = [];
        foreach ($movements as $m) {
            $conv = $unit ? QuantityUnit::convert((float) $m->delta, $m->unit, $unit) : null;
            if ($conv === null && ! $unit) {
                $conv = (float) $m->delta;
            }
            if ($conv === null) {
                $issues[] = 'unit_mismatch';
            } elseif ($m->kind === 'correction') {
                $corrections += $conv;
            } else {
                $byKind[$m->kind] = ($byKind[$m->kind] ?? 0.0) - $conv;
                $consumed['other'] -= $conv;
            }
            if ($withDetails) {
                $moveRows[] = [
                    'id'         => (int) $m->id,
                    'kind'       => $m->kind,
                    'delta'      => QuantityUnit::round((float) $m->delta),
                    'unit'       => $m->unit,
                    'note'       => $m->note,
                    'created_by' => $m->creator ? ['id' => (int) $m->creator->id, 'name' => $m->creator->name] : null,
                    'created_at' => $m->created_at?->toDateTimeString(),
                ];
            }
        }

        $balance = $received !== null ? $received + $corrections - $consumed['main'] - $consumed['control'] - $consumed['other'] : null;
        $available = $balance !== null ? $balance - ($retained ?? 0.0) : null;

        $state = 'unknown';
        if ($balance !== null) {
            if ($balance < -self::EPS) {
                $state = 'negative';
            } elseif ($available <= self::EPS) {
                $state = 'empty';
            } elseif ($received > 0 && $available < 0.1 * $received) {
                $state = 'low';
            } else {
                $state = 'ok';
            }
        }

        $summary = [
            'sample_id'   => (int) $sample->id,
            'unit'        => $unit,
            'received'    => QuantityUnit::round($received),
            'retained'    => QuantityUnit::round($retained),
            'consumed'    => array_map(fn ($v) => QuantityUnit::round($v), $consumed),
            'consumed_by_kind' => array_map(fn ($v) => QuantityUnit::round($v), $byKind),
            'corrections' => QuantityUnit::round($corrections),
            'planned'     => QuantityUnit::round($planned),
            'balance'     => QuantityUnit::round($balance),
            'available'   => QuantityUnit::round($available),
            'state'       => $state,
            'issues'      => array_values(array_unique($issues)),
            'worksheets_count' => count($entries),
        ];

        if ($withDetails) {
            $summary['worksheets'] = array_map(fn ($e) => [
                'id'                 => $e['worksheet_id'],
                'number'             => $e['number'],
                'kind'               => $e['kind'],
                'status'             => $e['status'],
                'worksheet_date'     => $e['worksheet_date'],
                'template_code'      => $e['template_code'],
                'template_name_uz'   => $e['template_name_uz'],
                'template_name_ru'   => $e['template_name_ru'],
                'parallels'          => $e['parallels'],
                'quantity'           => QuantityUnit::round($e['quantity']),
                'column_unit'        => $e['column_unit'],
                'column_label'       => $e['column_label'],
                'quantity_converted' => $e['quantity_converted'],
                'planned'            => $e['planned'],
                'issue'              => $e['issue'] ?? null,
            ], $entries);
            $summary['movements'] = $moveRows;
        }

        return $summary;
    }

    /**
     * Probalar bo'yicha varaqlardagi sarf.
     * @return array<int, array<int, array>> sample_id => [varaq yozuvi, ...]
     */
    private function worksheetUsage(array $sampleIds): array
    {
        if (! $sampleIds || ! self::hasSchema('worksheet_rows')) {
            return [];
        }

        $rows = DB::table('worksheet_rows')
            ->join('worksheets', 'worksheets.id', '=', 'worksheet_rows.worksheet_id')
            ->whereNull('worksheets.deleted_at')
            ->whereIn('worksheet_rows.sample_id', $sampleIds)
            ->get(['worksheet_rows.worksheet_id', 'worksheet_rows.sample_id', 'worksheet_rows.inputs']);
        if ($rows->isEmpty()) {
            return [];
        }

        $hasKind = self::hasSchema('worksheets', 'kind');
        $worksheets = Worksheet::query()
            ->whereIn('id', $rows->pluck('worksheet_id')->map(fn ($v) => (int) $v)->unique()->values()->all())
            ->get(array_merge(['id', 'template_id', 'number', 'status', 'worksheet_date', 'template_snapshot'], $hasKind ? ['kind'] : []))
            ->keyBy('id');

        $columns = [];
        $acc = [];
        foreach ($rows as $r) {
            $wid = (int) $r->worksheet_id;
            $sid = (int) $r->sample_id;
            $w = $worksheets->get($wid);
            if (! $w) {
                continue;
            }
            if (! array_key_exists($wid, $columns)) {
                $columns[$wid] = $this->consumeColumn($w->definition(), $w->template_id ? (int) $w->template_id : null);
            }
            $col = $columns[$wid];
            $def = $w->definition();

            if (! isset($acc[$sid][$wid])) {
                $acc[$sid][$wid] = [
                    'worksheet_id'     => $wid,
                    'number'           => $w->number,
                    'kind'             => ($hasKind ? $w->kind : null) ?: 'main',
                    'status'           => $w->status,
                    'worksheet_date'   => $w->worksheet_date?->toDateString(),
                    'template_code'    => $def['code'] ?? null,
                    'template_name_uz' => $def['name_uz'] ?? null,
                    'template_name_ru' => $def['name_ru'] ?? null,
                    'parallels'        => 0,
                    'quantity'         => $col ? 0.0 : null,
                    'column_unit'      => $col['unit'] ?? null,
                    'column_label'     => $col['label'] ?? null,
                    'planned'          => in_array($w->status, ['draft', 'printed'], true),
                ];
            }
            $acc[$sid][$wid]['parallels']++;

            if ($col) {
                $inputs = is_string($r->inputs) ? (json_decode($r->inputs, true) ?: []) : (array) $r->inputs;
                $v = QuantityUnit::number($inputs[$col['key']] ?? null) ?? $col['default'];
                if ($v !== null && $v > 0) {
                    $acc[$sid][$wid]['quantity'] += $v;
                }
            }
        }

        $out = [];
        foreach ($acc as $sid => $list) {
            $entries = array_values($list);
            usort($entries, fn ($a, $b) => [$a['worksheet_date'], $a['worksheet_id']] <=> [$b['worksheet_date'], $b['worksheet_id']]);
            $out[$sid] = $entries;
        }

        return $out;
    }

    // -------------------------------------------------- yangi varaq oldidan tekshiruv

    /**
     * Rejalangan sarf probadagi foydalanish mumkin bo'lgan qoldiqdan oshadimi.
     *
     * @param  array  $definition  shablon ta'rifi (yangi varaq) yoki varaq nusxasi (proba qo'shishda)
     * @param  array<int, array{sample: Sample, parallels: int}>  $items
     * @return array<int, array{sample_id:int, code:string, need:float, available:float, unit:string}>
     */
    public function shortages(array $definition, ?int $templateId, array $items): array
    {
        $col = $this->consumeColumn($definition, $templateId);
        if (! $col || ! $col['default'] || ! $items) {
            return [];
        }

        $samples = collect($items)->map(fn ($it) => $it['sample']);
        $summaries = $this->summaries($samples);

        $out = [];
        foreach ($items as $it) {
            $s = $summaries[(int) $it['sample']->id] ?? null;
            if (! $s || $s['available'] === null || ! $s['unit']) {
                continue;
            }
            $need = QuantityUnit::convert($col['default'] * max(1, (int) $it['parallels']), QuantityUnit::normalize($col['unit']) ?: $s['unit'], $s['unit']);
            if ($need === null) {
                continue;
            }
            if ($need > $s['available'] + self::EPS) {
                $out[] = [
                    'sample_id' => (int) $it['sample']->id,
                    'code'      => $it['sample']->code,
                    'need'      => QuantityUnit::round($need),
                    'available' => $s['available'],
                    'unit'      => $s['unit'],
                ];
            }
        }

        return $out;
    }

    /** Nomzodlar ro'yxati uchun: [sample_id => {unit, available, state, per_parallel}] */
    public function candidateInfo(Collection $samples, array $definition, ?int $templateId): array
    {
        $col = $this->consumeColumn($definition, $templateId);
        $withQty = $samples->filter(fn ($s) => $s->received_quantity !== null);
        $summaries = $this->summaries($withQty);

        $out = [];
        foreach ($samples as $s) {
            $sum = $summaries[(int) $s->id] ?? null;
            $per = null;
            if ($col && $col['default'] && $sum && $sum['unit']) {
                $per = QuantityUnit::convert($col['default'], QuantityUnit::normalize($col['unit']) ?: $sum['unit'], $sum['unit']);
            }
            $out[(int) $s->id] = $sum ? [
                'unit'         => $sum['unit'],
                'received'     => $sum['received'],
                'available'    => $sum['available'],
                'state'        => $sum['state'],
                'per_parallel' => QuantityUnit::round($per),
            ] : null;
        }

        return $out;
    }
}
