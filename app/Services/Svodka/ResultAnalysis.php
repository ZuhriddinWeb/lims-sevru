<?php

namespace App\Services\Svodka;

use App\Models\Sample;
use App\Models\SvodkaAssignment;
use App\Models\SvodkaPoint;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * [Paket 32] Proba natijalari dinamikasi — bo'lim → svodka nuqtasi → ko'rsatkich (element).
 *
 * Me'yor (ruxsat etilgan chegara) yo'q, shuning uchun baho nuqtaning O'Z TARIXIGA nisbatan:
 *   - davr (sana + navbatchilik) qiymati — shu davrdagi natijalar o'rtachasi;
 *   - chetlanish — robust z = (qiymat − mediana) / tarqoqlik (MAD / IQR, kamida medianing 2% i):
 *     |z| > 3,5 — keskin, 2,5–3,5 — e'tibor;
 *     oxirgi qiymat undan oldingi davrlar bilan solishtiriladi;
 *   - trend — vaqt bo'yicha chiziqli regressiya: butun davr bo'yicha o'zgarish (%), R²;
 *   - I va II navbatchilik o'rtachalari farqi.
 * Hisob mahalliy (tashqi xizmat yo'q). Natija: status «done» va qiymati bor elementlar (chiqarilmaganlari ham).
 */
class ResultAnalysis
{
    public const MAX_DAYS = 366;
    private const Z_STRONG = 3.5;
    private const Z_WARN = 2.5;
    private const MIN_PERIODS = 5;
    private const REL_FLOOR = 0.02;

    public function __construct(private SvodkaService $svc)
    {
    }

    /**
     * @param array{department_id:int, from:string, to:string, point_id?:int|null, section_id?:int|null, symbol?:string|null, shift?:int|null} $f
     */
    public function analyze(array $f): array
    {
        $deptId = (int) $f['department_id'];
        $from = Carbon::parse($f['from'])->toDateString();
        $to = Carbon::parse($f['to'])->toDateString();

        // namunalar: qo'lda bog'langan + buyurtmada davri ko'rsatilgan + shu oraliqda ro'yxatga olingan
        // (I-navbatchilik oldingi kun kechqurun boshlanadi — oraliq kengroq); aniq davr — svodka bog'lanishi bo'yicha
        $manualIds = SvodkaAssignment::where('customer_department_id', $deptId)
            ->whereDate('production_date', '>=', $from)->whereDate('production_date', '<=', $to)
            ->pluck('sample_id')->all();
        $requestIds = DB::table('sample_request_items')
            ->join('sample_requests', 'sample_requests.id', '=', 'sample_request_items.sample_request_id')
            ->where('sample_requests.customer_department_id', $deptId)
            ->whereDate('sample_requests.svodka_date', '>=', $from)->whereDate('sample_requests.svodka_date', '<=', $to)
            ->whereNotNull('sample_request_items.sample_id')
            ->pluck('sample_request_items.sample_id')->all();
        $windowIds = Sample::where('customer_department_id', $deptId)
            ->where('registered_at', '>=', Carbon::parse($from)->subDays(2))
            ->where('registered_at', '<', Carbon::parse($to)->addDays(2))
            ->pluck('id')->all();
        $ids = array_values(array_unique(array_map('intval', array_merge($manualIds, $requestIds, $windowIds))));
        $samples = collect();
        foreach (array_chunk($ids, 1000) as $chunk) {   // SQL Server: parametrlar soni chegarasi
            $samples = $samples->concat(Sample::query()->where('customer_department_id', $deptId)->whereIn('id', $chunk)
                ->with(['sampleElements' => fn ($q) => $q->where('status', 'done')->whereNotNull('result_value')
                ->select('id', 'sample_id', 'element_id', 'result_value', 'result_unit', 'status'),
                'sampleElements.element:id,symbol,default_unit'])
                ->get(['id', 'code', 'customer_code', 'sample_number', 'customer_department_id', 'registered_at', 'created_at']));
        }
        $map = [];
        foreach ($samples->chunk(1000) as $part) {
            $map += $this->svc->mappings($part->values());
        }

        $points = SvodkaPoint::with('section:id,title,sort_order')->where('customer_department_id', $deptId)->get()->keyBy('id');
        $wantSymbol = ($s = trim((string) ($f['symbol'] ?? ''))) === '' ? null : mb_strtolower($s);
        $wantShift = in_array((int) ($f['shift'] ?? 0), [1, 2], true) ? (int) $f['shift'] : null;
        $wantPoint = (int) ($f['point_id'] ?? 0) ?: null;
        $wantSection = (int) ($f['section_id'] ?? 0) ?: null;

        $groups = [];
        $unmapped = 0;
        $used = 0;
        foreach ($samples as $s) {
            $m = $map[(int) $s->id] ?? null;
            if (! $m || $m['date'] < $from || $m['date'] > $to || ($wantShift && $m['shift'] !== $wantShift)) {
                continue;
            }
            $pid = $m['point_id'] && $points->has($m['point_id']) ? (int) $m['point_id'] : null;
            if ($wantPoint && $pid !== $wantPoint) {
                continue;
            }
            if ($wantSection && (! $pid || (int) $points[$pid]->section_id !== $wantSection)) {
                continue;
            }
            $hasValue = false;
            foreach ($s->sampleElements as $se) {
                $sym = trim((string) ($se->element?->symbol ?? ''));
                if ($sym === '' || ($wantSymbol && mb_strtolower($sym) !== $wantSymbol)) {
                    continue;
                }
                $unit = trim((string) ($se->result_unit ?: $se->element?->default_unit ?: ''));
                // katalogda bir xil belgili elementlar (Au ikki xil) — belgi + birlik bo'yicha birlashtiriladi
                $key = ($pid ?? 0) . '|' . mb_strtolower($sym) . '|' . mb_strtolower($unit);
                $groups[$key] ??= ['point_id' => $pid, 'symbol' => $sym, 'unit' => $unit, 'rows' => []];
                $groups[$key]['rows'][] = [
                    'sample_id' => (int) $s->id, 'code' => $s->customer_code ?: $s->sample_number,
                    'date' => $m['date'], 'shift' => (int) $m['shift'], 'value' => (float) $se->result_value,
                ];
                $hasValue = true;
            }
            if ($hasValue) {
                $used++;
                if ($pid === null) {
                    $unmapped++;
                }
            }
        }

        $items = [];
        foreach ($groups as $g) {
            $p = $g['point_id'] ? $points[$g['point_id']] : null;
            $items[] = [
                'point_id'      => $g['point_id'],
                'point'         => $p?->name,
                'section'       => $p?->section?->title,
                'section_sort'  => (int) ($p?->section?->sort_order ?? 9999),
                'point_sort'    => (int) ($p?->sort_order ?? 9999),
                'symbol'        => $g['symbol'],
                'unit'          => $g['unit'],
            ] + $this->stats($g['rows']);
        }
        $rank = ['deviation' => 0, 'attention' => 1, 'normal' => 2, 'few' => 3];
        usort($items, fn ($a, $b) => [$rank[$a['verdict']], $a['section_sort'], $a['point_sort'], (string) $a['point'], $a['symbol']]
            <=> [$rank[$b['verdict']], $b['section_sort'], $b['point_sort'], (string) $b['point'], $b['symbol']]);

        $counts = array_fill_keys(array_keys($rank), 0);
        foreach ($items as $it) {
            $counts[$it['verdict']]++;
        }

        return [
            'department_id' => $deptId,
            'from'          => $from,
            'to'            => $to,
            'samples'       => $used,
            'unmapped'      => $unmapped,
            'counts'        => $counts,
            'items'         => $items,
        ];
    }

    /** @param array<int, array{sample_id:int, code:?string, date:string, shift:int, value:float}> $rows */
    private function stats(array $rows): array
    {
        // davrlar: sana + navbatchilik (I — kun boshida, II — keyin)
        $periods = [];
        foreach ($rows as $r) {
            $k = $r['date'] . '|' . $r['shift'];
            $periods[$k] ??= ['date' => $r['date'], 'shift' => $r['shift'], 'values' => [], 'codes' => []];
            $periods[$k]['values'][] = $r['value'];
            if ($r['code'] !== null && $r['code'] !== '' && ! in_array($r['code'], $periods[$k]['codes'], true)) {
                $periods[$k]['codes'][] = $r['code'];
            }
        }
        ksort($periods);
        $periods = array_values($periods);
        $base = Carbon::parse($periods[0]['date']);
        $series = [];
        foreach ($periods as $p) {
            $series[] = [
                'date'  => $p['date'],
                'shift' => $p['shift'],
                't'     => $base->diffInDays(Carbon::parse($p['date'])) + ($p['shift'] === 2 ? 0.5 : 0.0),
                'value' => round(array_sum($p['values']) / count($p['values']), 6),
                'n'     => count($p['values']),
                'codes' => $p['codes'],
            ];
        }
        $v = array_column($series, 'value');
        $n = count($v);
        $mean = array_sum($v) / $n;
        $sd = $n > 1 ? sqrt(array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $v)) / ($n - 1)) : 0.0;
        $median = self::median($v);
        $scale = self::scale($v, $median, $sd);

        // har nuqta — butun davr medianasiga nisbatan; oxirgisi — undan oldingilarga nisbatan
        foreach ($series as $i => $s) {
            $z = $scale > 0 ? ($s['value'] - $median) / $scale : 0.0;
            $series[$i]['z'] = round($z, 2);
            $series[$i]['flag'] = abs($z) > self::Z_STRONG ? 'strong' : (abs($z) > self::Z_WARN ? 'warn' : null);
        }
        $lastZ = null;
        if ($n >= self::MIN_PERIODS) {
            $prev = array_slice($v, 0, -1);
            $pm = self::median($prev);
            $psd = count($prev) > 1 ? sqrt(array_sum(array_map(fn ($x) => ($x - array_sum($prev) / count($prev)) ** 2, $prev)) / (count($prev) - 1)) : 0.0;
            $ps = self::scale($prev, $pm, $psd);
            $lastZ = $ps > 0 ? round(($v[$n - 1] - $pm) / $ps, 2) : 0.0;
            $series[$n - 1]['z'] = $lastZ;
            $series[$n - 1]['flag'] = abs($lastZ) > self::Z_STRONG ? 'strong' : (abs($lastZ) > self::Z_WARN ? 'warn' : null);
        }

        // trend: chiziqli regressiya
        $slope = 0.0;
        $r2 = 0.0;
        $t = array_column($series, 't');
        $span = $n > 1 ? max($t) - min($t) : 0;
        if ($n >= 3 && $span > 0) {
            $tm = array_sum($t) / $n;
            $sxx = 0.0;
            $sxy = 0.0;
            foreach ($t as $i => $ti) {
                $sxx += ($ti - $tm) ** 2;
                $sxy += ($ti - $tm) * ($v[$i] - $mean);
            }
            $slope = $sxx > 0 ? $sxy / $sxx : 0.0;
            $sst = array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $v));
            $sse = 0.0;
            foreach ($t as $i => $ti) {
                $sse += ($v[$i] - ($mean + $slope * ($ti - $tm))) ** 2;
            }
            $r2 = $sst > 0 ? max(0.0, 1 - $sse / $sst) : 0.0;
        }
        $changePct = $mean != 0.0 ? round($slope * $span / abs($mean) * 100, 1) : 0.0;
        $trend = $n < 4 ? 'unknown'
            : ($r2 >= 0.3 && abs($changePct) >= 5 ? ($changePct > 0 ? 'increasing' : 'decreasing') : 'stable');

        // navbatchiliklar
        $byShift = [1 => [], 2 => []];
        foreach ($series as $s) {
            $byShift[$s['shift']][] = $s['value'];
        }
        $shiftAvg = [];
        foreach ([1, 2] as $sh) {
            $shiftAvg[$sh] = $byShift[$sh] ? round(array_sum($byShift[$sh]) / count($byShift[$sh]), 6) : null;
        }
        $shiftDiff = null;
        if (count($byShift[1]) >= 3 && count($byShift[2]) >= 3 && $mean != 0.0) {
            $shiftDiff = round(($shiftAvg[2] - $shiftAvg[1]) / abs($mean) * 100, 1);
        }

        // baho va sabablar
        $strong = count(array_filter($series, fn ($s) => $s['flag'] === 'strong'));
        $warn = count(array_filter($series, fn ($s) => $s['flag'] === 'warn'));
        $recentStrong = count(array_filter(array_slice($series, -5), fn ($s) => $s['flag'] === 'strong'));
        $reasons = [];
        if ($lastZ !== null && abs($lastZ) > self::Z_WARN) {
            $reasons[] = ['type' => 'last', 'z' => $lastZ, 'value' => $v[$n - 1], 'typical' => round(self::median(array_slice($v, 0, -1)), 6)];
        }
        if ($strong) {
            $reasons[] = ['type' => 'outliers', 'count' => $strong];
        }
        if (in_array($trend, ['increasing', 'decreasing'], true) && abs($changePct) >= 15 && $r2 >= 0.5) {
            $reasons[] = ['type' => 'trend', 'pct' => $changePct];
        }
        if ($shiftDiff !== null && abs($shiftDiff) >= 15) {
            $reasons[] = ['type' => 'shift', 'pct' => $shiftDiff];
        }

        if ($n < self::MIN_PERIODS) {
            $verdict = 'few';
        } elseif (($lastZ !== null && abs($lastZ) > self::Z_STRONG) || $recentStrong >= 2) {
            $verdict = 'deviation';
        } elseif ($reasons) {
            $verdict = 'attention';
        } else {
            $verdict = 'normal';
        }

        $last = $series[$n - 1];

        return [
            'periods'     => $n,
            'measurements'=> count($rows),
            'last'        => ['date' => $last['date'], 'shift' => $last['shift'], 'value' => $last['value']],
            'mean'        => round($mean, 6),
            'median'      => round($median, 6),
            'sd'          => round($sd, 6),
            'min'         => min($v),
            'max'         => max($v),
            'band'        => $scale > 0 ? [round($median - self::Z_WARN * $scale, 6), round($median + self::Z_WARN * $scale, 6)] : null,
            'trend'       => $trend,
            'change_pct'  => $changePct,
            'r2'          => round($r2, 2),
            'shift_avg'   => $shiftAvg,
            'shift_diff'  => $shiftDiff,
            'outliers'    => ['strong' => $strong, 'warn' => $warn],
            'verdict'     => $verdict,
            'reasons'     => $reasons,
            'series'      => array_map(fn ($s) => array_diff_key($s, ['t' => 1]), $series),
        ];
    }

    private static function median(array $v): float
    {
        if (! $v) {
            return 0.0;
        }
        sort($v);
        $c = count($v);

        return $c % 2 ? (float) $v[intdiv($c, 2)] : ($v[$c / 2 - 1] + $v[$c / 2]) / 2;
    }

    /**
     * Robust tarqoqlik: max(1,4826 × MAD, IQR / 1,349) — navbatchiliklar orasida doimiy farq bo'lsa (qiymatlar
     * ikki guruhda) MAD juda kichik chiqadi, IQR esa ikkalasini qamraydi. Quyi chegara — medianing 2% i
     * (analitik xatolik: 1,80 → 1,81 «keskin» bo'lmasin). Hammasi bir xil bo'lsa — standart og'ish.
     */
    private static function scale(array $v, float $median, float $sd): float
    {
        $mad = 1.4826 * self::median(array_map(fn ($x) => abs($x - $median), $v));
        $iqr = (self::quantile($v, 0.75) - self::quantile($v, 0.25)) / 1.349;
        $s = max($mad, $iqr);
        if ($s <= 0) {
            $s = $sd;
        }

        return max($s, abs($median) * self::REL_FLOOR);
    }

    private static function quantile(array $v, float $q): float
    {
        if (! $v) {
            return 0.0;
        }
        sort($v);
        $pos = ($q * (count($v) - 1));
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);

        return $v[$lo] + ($v[$hi] - $v[$lo]) * ($pos - $lo);
    }
}
