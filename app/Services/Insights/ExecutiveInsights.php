<?php

namespace App\Services\Insights;

use App\Models\Equipment;
use App\Models\LaboratoryGroup;
use App\Models\Sample;
use App\Models\WaterOrder;
use App\Models\WaterOrderResult;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rahbariyat paneli 2.0 — laboratoriya ishi bir sahifada.
 *
 * Bloklar (har biri mustaqil: bittasida xato bo'lsa qolganlari baribir chiqadi):
 *   kpi        — qabul, yakunlangan, tahlillar, ishchi varaqlar, bajarilish vaqti (mediana / o'rtacha / 90%),
 *                hozir ishda, kechikkan; oldingi teng davr bilan solishtirish
 *   flow       — ish oqimi HOZIR: qabul qilingan → uzatilgan → tahlilda → tekshiruvda, har bosqichda yoshi
 *   trend      — dinamika: qabul / yakunlangan / tahlillar (soat, kun yoki oy bo'yicha)
 *   by_shift   — smenalar: har smenada qabul, yakun, tahlil, varaq, imzolangan bosqich; joriy smena; kun × smena jadvali
 *   groups     — bajaruvchi guruhlar: ishda, kechikkan, qabul, yakun, tahlillar, mediana vaqt
 *   customers  — buyurtmachi bo'limlar: topshirgan, yakunlangan, ishda, kechikkan, mediana vaqt
 *   worksheets — ishchi varaqlar: asosiy / nazorat, holatlar, tasdiq kutayotganlar, ogohlantirish, qo'shimcha parallel, takroriy
 *   balances   — proba qoldig'i: kam / tugagan / manfiy
 *   equipment  — uskunalar tayyorligi va kalibrovka
 *   ecology    — suv laboratoriyasi o'lchovlari
 *
 * Barcha so'rovlar MySQL va SQL Server'da bir xil ishlaydi (sana ifodalari — TimeSql).
 */
class ExecutiveInsights
{
    public const OVERDUE_DAYS = 7;
    public const OPEN = ['registered', 'assigned', 'in_progress'];
    public const STAGES = ['registered', 'assigned', 'analysis', 'review'];
    public const BLOCKS = ['kpi', 'flow', 'trend', 'by_shift', 'groups', 'customers', 'worksheets', 'balances', 'equipment', 'ecology'];

    private InsightPeriod $p;
    private array $f = ['laboratory_id' => null, 'group_id' => null, 'customer_department_id' => null];
    private array $schema = [];

    public function __construct(private ShiftCalendar $calendar)
    {
    }

    public function build(InsightPeriod $period, array $filters = [], ?array $only = null): array
    {
        $this->p = $period;
        $this->f = [
            'laboratory_id'          => ! empty($filters['laboratory_id']) ? (int) $filters['laboratory_id'] : null,
            'group_id'               => ! empty($filters['group_id']) ? (int) $filters['group_id'] : null,
            'customer_department_id' => ! empty($filters['customer_department_id']) && $this->hasColumn('samples', 'customer_department_id')
                ? (int) $filters['customer_department_id'] : null,
        ];

        $out = [
            'period'       => $period->toArray(),
            'filters'      => $this->f,
            'shifts'       => $this->calendar->list(),
            'overdue_days' => self::OVERDUE_DAYS,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
        foreach (self::BLOCKS as $block) {
            if ($only !== null && ! in_array($block, $only, true)) {
                continue;
            }
            $out[$block] = $this->safely(fn () => $this->{'block' . Str::studly($block)}());
        }

        return $out;
    }

    private function safely(Closure $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            return ['error' => true, 'message' => config('app.debug') ? $e->getMessage() : null];
        }
    }

    // ================================================================== yordamchilar

    private function hasTable(string $table): bool
    {
        return $this->schema["t:$table"] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->schema["c:$table.$column"] ??= $this->hasTable($table) && Schema::hasColumn($table, $column);
    }

    private function hasFilters(): bool
    {
        return (bool) array_filter($this->f);
    }

    /** Probalar (o'chirilmagan) + panel filtrlari */
    private function samples(): Builder
    {
        $q = DB::table('samples')->whereNull('samples.deleted_at');
        $this->applySampleFilters($q);

        return $q;
    }

    private function applySampleFilters(Builder $q, string $t = 'samples'): void
    {
        if ($lab = $this->f['laboratory_id']) {
            $q->where(function (Builder $w) use ($t, $lab) {
                $w->where("$t.registering_lab_id", $lab)
                  ->orWhereIn("$t.assigned_group_id", fn (Builder $s) => $s->select('id')->from('laboratory_groups')->where('laboratory_id', $lab));
            });
        }
        if ($g = $this->f['group_id']) {
            $q->where("$t.assigned_group_id", $g);
        }
        if ($d = $this->f['customer_department_id']) {
            $q->where("$t.customer_department_id", $d);
        }
    }

    /** $col IN (o'chirilmagan va filtrga mos probalar) */
    private function ofSamples(Builder $q, string $col): Builder
    {
        return $q->whereIn($col, function (Builder $s) {
            $s->select('samples.id')->from('samples')->whereNull('samples.deleted_at');
            $this->applySampleFilters($s);
        });
    }

    /** Bajarilgan tahlillar (element natijalari) */
    private function analyses(): Builder
    {
        return $this->ofSamples(
            DB::table('sample_elements')->where('sample_elements.status', 'done')->whereNotNull('sample_elements.result_entered_at'),
            'sample_elements.sample_id'
        );
    }

    /** Ishchi varaqlar (o'chirilmagan); filtr bo'lsa — filtrga mos proba bor varaqlar */
    private function worksheets(): Builder
    {
        $q = DB::table('worksheets')->whereNull('worksheets.deleted_at');
        if ($this->hasFilters() && $this->hasTable('worksheet_samples')) {
            $q->whereIn('worksheets.id', function (Builder $s) {
                $s->select('worksheet_samples.worksheet_id')->from('worksheet_samples')
                  ->join('samples', 'samples.id', '=', 'worksheet_samples.sample_id')
                  ->whereNull('samples.deleted_at');
                $this->applySampleFilters($s);
            });
        }

        return $q;
    }

    private function hasKind(): bool
    {
        return $this->hasColumn('worksheets', 'kind');
    }

    private function between(Builder $q, string $col, Carbon $a, Carbon $b): Builder
    {
        return $q->where($col, '>=', $a)->where($col, '<=', $b);
    }

    /** Foizdagi o'zgarish (haqiqiy yo'nalish: kamaysa — manfiy). Yaxshi/yomonligini interfeys belgilaydi. */
    private static function change($current, $previous): ?float
    {
        if ($current === null || $previous === null || (float) $previous == 0.0) {
            return null;
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    private function delta(int|float|null $value, int|float|null $prev): array
    {
        return ['value' => $value, 'prev' => $prev, 'change' => self::change($value, $prev)];
    }

    /** Daqiqalar ro'yxati → soatlarda mediana / o'rtacha / 90-persentil */
    private static function durationStats(array $minutes): array
    {
        $minutes = array_values(array_filter(array_map('intval', $minutes), fn ($m) => $m >= 0));
        $n = count($minutes);
        if (! $n) {
            return ['count' => 0, 'median' => null, 'avg' => null, 'p90' => null];
        }
        sort($minutes);
        $q = function (float $p) use ($minutes, $n) {
            $pos = ($n - 1) * $p;
            $lo = (int) floor($pos);
            $hi = (int) ceil($pos);

            return $minutes[$lo] + ($minutes[$hi] - $minutes[$lo]) * ($pos - $lo);
        };

        return [
            'count'  => $n,
            'median' => round($q(0.5) / 60, 1),
            'avg'    => round(array_sum($minutes) / $n / 60, 1),
            'p90'    => round($q(0.9) / 60, 1),
        ];
    }

    /** Yakunlangan probalar: qabuldan yakungacha daqiqa (ixtiyoriy guruhlash ustuni bilan) */
    private function cycleMinutes(Carbon $a, Carbon $b, ?string $groupCol = null): \Illuminate\Support\Collection
    {
        $q = $this->between($this->samples(), 'samples.completed_at', $a, $b)
            ->whereNotNull('samples.registered_at');
        $q->selectRaw(TimeSql::minutes('samples.registered_at', 'samples.completed_at') . ' as m');
        if ($groupCol) {
            $q->addSelect(DB::raw("$groupCol as g"));
        }

        return $q->get();
    }

    private function overdueLimit(): Carbon
    {
        return now()->subDays(self::OVERDUE_DAYS);
    }

    /** COUNT(*) ni ustun bo'yicha guruhlash: [kalit => son] (null kalit — '') */
    private static function countBy(Builder $q, string $col): array
    {
        $out = [];
        foreach ($q->selectRaw("$col as k, COUNT(*) as c")->groupBy($col)->get() as $r) {
            $out[$r->k === null ? '' : (string) $r->k] = (int) $r->c;
        }

        return $out;
    }

    /** Vaqt bo'laklari bo'yicha sanash: [bo'lak => son] */
    private function bucketCounts(Builder $q, string $col): array
    {
        $q->whereNotNull($col)->selectRaw(TimeSql::bucket($col, $this->p->bucket) . ' as b');
        $out = [];
        foreach (DB::query()->fromSub($q, 't')->selectRaw('b, COUNT(*) as c')->groupBy('b')->get() as $r) {
            $out[trim((string) $r->b)] = (int) $r->c;
        }

        return $out;
    }

    /**
     * Kun × smena bo'yicha sanash.
     * @return array<int, array{date:string, shift:int, count:int}>  shift = 0 — smenadan tashqari
     */
    private function shiftGrid(Builder $q, string $col, ?string $timeCol = null): array
    {
        $hm = $timeCol ?? TimeSql::hm($col);
        $q->whereNotNull($col)->selectRaw(TimeSql::day($col) . ' as d, ' . $this->calendar->caseSql($hm) . ' as s');
        $out = [];
        foreach (DB::query()->fromSub($q, 't')->selectRaw('d, s, COUNT(*) as c')->groupBy('d', 's')->get() as $r) {
            $code = (int) $r->s;
            $date = trim((string) $r->d);
            if ($code < 0) {
                // yarim tundan o'tgan smenaning ertalabki qismi — smena boshlangan kunga
                $date = Carbon::parse($date)->subDay()->toDateString();
                $code = -$code;
            }
            $out[] = ['date' => $date, 'shift' => $code, 'count' => (int) $r->c];
        }

        return $out;
    }

    // ================================================================== 1. KPI

    private function blockKpi(): array
    {
        $p = $this->p;
        $count = fn (Closure $make, string $col, Carbon $a, Carbon $b) => $this->between($make(), $col, $a, $b)->count();

        $received = $count(fn () => $this->samples(), 'samples.registered_at', $p->from, $p->end);
        $receivedPrev = $count(fn () => $this->samples(), 'samples.registered_at', $p->prevFrom, $p->prevTo);
        $completed = $count(fn () => $this->samples(), 'samples.completed_at', $p->from, $p->end);
        $completedPrev = $count(fn () => $this->samples(), 'samples.completed_at', $p->prevFrom, $p->prevTo);
        $analyses = $count(fn () => $this->analyses(), 'sample_elements.result_entered_at', $p->from, $p->end);
        $analysesPrev = $count(fn () => $this->analyses(), 'sample_elements.result_entered_at', $p->prevFrom, $p->prevTo);

        $cycle = self::durationStats($this->cycleMinutes($p->from, $p->end)->pluck('m')->all());
        $cyclePrev = self::durationStats($this->cycleMinutes($p->prevFrom, $p->prevTo)->pluck('m')->all());

        $inWork = $this->samples()->whereIn('samples.status', self::OPEN)->count();
        $overdue = $this->samples()->whereIn('samples.status', self::OPEN)
            ->where('samples.registered_at', '<=', $this->overdueLimit())->count();

        $out = [
            'received'   => $this->delta($received, $receivedPrev),
            'completed'  => $this->delta($completed, $completedPrev),
            'analyses'   => $this->delta($analyses, $analysesPrev),
            'turnaround' => $cycle + ['prev_median' => $cyclePrev['median'], 'change' => self::change($cycle['median'], $cyclePrev['median'])],
            'in_work'    => ['value' => $inWork],
            'overdue'    => ['value' => $overdue, 'days' => self::OVERDUE_DAYS],
            'worksheets' => null,
        ];

        if ($this->hasTable('worksheets')) {
            $ws = $count(fn () => $this->worksheets(), 'worksheets.created_at', $p->from, $p->end);
            $wsPrev = $count(fn () => $this->worksheets(), 'worksheets.created_at', $p->prevFrom, $p->prevTo);
            $control = $this->hasKind()
                ? $this->between($this->worksheets(), 'worksheets.created_at', $p->from, $p->end)->where('worksheets.kind', 'control')->count()
                : 0;
            $out['worksheets'] = $this->delta($ws, $wsPrev) + ['control' => $control];
        }

        return $out;
    }

    // ================================================================== 2. ISH OQIMI (hozir)

    private function blockFlow(): array
    {
        $cols = ['samples.id', 'samples.code', 'samples.sample_number', 'samples.status', 'samples.registered_at', 'samples.assigned_at', 'samples.assigned_group_id'];
        if ($this->hasColumn('samples', 'started_at')) {
            $cols[] = 'samples.started_at';
        }
        $open = $this->samples()->whereIn('samples.status', self::OPEN)->get($cols);
        $ids = $open->pluck('id')->map(fn ($v) => (int) $v)->all();

        // Varaqlar va bajarilgan elementlar — bo'laklab (SQL Server: 2100 parametr chegarasi)
        $sheets = [];
        $done = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            if ($this->hasTable('worksheet_samples')) {
                $wsCols = ['worksheet_samples.sample_id', 'worksheets.status'];
                if ($this->hasKind()) {
                    $wsCols[] = 'worksheets.kind';
                }
                foreach (DB::table('worksheet_samples')
                    ->join('worksheets', 'worksheets.id', '=', 'worksheet_samples.worksheet_id')
                    ->whereNull('worksheets.deleted_at')
                    ->whereIn('worksheet_samples.sample_id', $chunk)
                    ->get($wsCols) as $r) {
                    $sheets[(int) $r->sample_id][] = ['status' => $r->status, 'kind' => ($r->kind ?? null) ?: 'main'];
                }
            }
            foreach (DB::table('sample_elements')->where('status', 'done')->whereIn('sample_id', $chunk)
                ->selectRaw('sample_id, COUNT(*) as c')->groupBy('sample_id')->get() as $r) {
                $done[(int) $r->sample_id] = (int) $r->c;
            }
        }

        $now = now();
        $stages = [];
        foreach (self::STAGES as $s) {
            $stages[$s] = ['key' => $s, 'count' => 0, 'aging' => ['d1' => 0, 'd3' => 0, 'd7' => 0, 'over' => 0]];
        }
        $overdue = [];
        $limit = $this->overdueLimit();
        foreach ($open as $s) {
            $id = (int) $s->id;
            $main = array_filter($sheets[$id] ?? [], fn ($w) => $w['kind'] !== 'control');
            $review = ($done[$id] ?? 0) > 0
                || (bool) array_filter($main, fn ($w) => in_array($w['status'], ['completed', 'approved'], true));
            $analysis = ! empty($s->started_at) || $main || $s->status === 'in_progress';
            $assigned = ! empty($s->assigned_at) || in_array($s->status, ['assigned', 'in_progress'], true);
            $stage = $review ? 'review' : ($analysis ? 'analysis' : ($assigned ? 'assigned' : 'registered'));

            $registered = $s->registered_at ? Carbon::parse($s->registered_at) : null;
            $hours = $registered ? max(0, ($now->getTimestamp() - $registered->getTimestamp()) / 3600) : 0;
            $bucket = $hours < 24 ? 'd1' : ($hours < 72 ? 'd3' : ($hours < 168 ? 'd7' : 'over'));
            $stages[$stage]['count']++;
            $stages[$stage]['aging'][$bucket]++;

            if ($registered && $registered->lessThanOrEqualTo($limit)) {
                $overdue[] = [
                    'id'            => $id,
                    'code'          => $s->code,
                    'sample_number' => $s->sample_number,
                    'status'        => $s->status,
                    'stage'         => $stage,
                    'group_id'      => $s->assigned_group_id ? (int) $s->assigned_group_id : null,
                    'registered_at' => $registered->format('Y-m-d H:i:s'),
                    'days'          => (int) floor($hours / 24),
                ];
            }
        }
        usort($overdue, fn ($a, $b) => strcmp($a['registered_at'], $b['registered_at']));

        $groupNames = $this->groupNames(array_filter(array_column($overdue, 'group_id')));
        $overdueList = array_map(fn ($o) => $o + ['group' => $groupNames[$o['group_id']] ?? null], array_slice($overdue, 0, 8));

        return [
            'stages'        => array_values($stages),
            'open'          => $open->count(),
            'overdue_count' => count($overdue),
            'overdue'       => $overdueList,
            'completed'     => $this->between($this->samples(), 'samples.completed_at', $this->p->from, $this->p->end)->count(),
        ];
    }

    private function groupNames(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            return [];
        }

        return LaboratoryGroup::query()->whereIn('id', $ids)->get(['id', 'code', 'name_ru', 'name_uz', 'name_en'])
            ->mapWithKeys(fn ($g) => [(int) $g->id => $g->only(['id', 'code', 'name_ru', 'name_uz', 'name_en'])])
            ->all();
    }

    // ================================================================== 3. DINAMIKA

    private function blockTrend(): array
    {
        $p = $this->p;
        $received = $this->bucketCounts($this->between($this->samples(), 'samples.registered_at', $p->from, $p->end), 'samples.registered_at');
        $completed = $this->bucketCounts($this->between($this->samples(), 'samples.completed_at', $p->from, $p->end), 'samples.completed_at');
        $analyses = $this->bucketCounts($this->between($this->analyses(), 'sample_elements.result_entered_at', $p->from, $p->end), 'sample_elements.result_entered_at');

        $points = [];
        foreach ($p->bucketKeys() as $key) {
            $future = $p->bucketIsFuture($key);
            $points[] = [
                'key'       => $key,
                'received'  => $future ? null : ($received[$key] ?? 0),
                'completed' => $future ? null : ($completed[$key] ?? 0),
                'analyses'  => $future ? null : ($analyses[$key] ?? 0),
            ];
        }

        return ['bucket' => $p->bucket, 'points' => $points];
    }

    // ================================================================== 4. SMENALAR

    private function blockByShift(): array
    {
        $shifts = $this->calendar->shifts();
        if ($shifts->isEmpty()) {
            return ['configured' => false];
        }
        $p = $this->p;

        $metrics = [
            'received'  => fn () => $this->shiftGrid($this->between($this->samples(), 'samples.registered_at', $p->from, $p->end), 'samples.registered_at'),
            'completed' => fn () => $this->shiftGrid($this->between($this->samples(), 'samples.completed_at', $p->from, $p->end), 'samples.completed_at'),
            'analyses'  => fn () => $this->shiftGrid($this->between($this->analyses(), 'sample_elements.result_entered_at', $p->from, $p->end), 'sample_elements.result_entered_at'),
        ];
        if ($this->hasTable('worksheets')) {
            $metrics['worksheets'] = fn () => $this->shiftGrid($this->between($this->worksheets(), 'worksheets.created_at', $p->from, $p->end), 'worksheets.created_at');
        }
        $hasTime = $this->hasColumn('worksheet_signoffs', 'performed_time');
        if ($hasTime) {
            $metrics['signoffs'] = fn () => $this->shiftGrid(
                $this->signoffs($p->from, $p->end)->whereNotNull('worksheet_signoffs.performed_time'),
                'worksheet_signoffs.performed_at', 'worksheet_signoffs.performed_time'
            );
        }

        $rows = [];
        foreach ($shifts as $s) {
            $rows[(int) $s->id] = ['id' => (int) $s->id, 'name' => $s->name, 'start_time' => $s->start_time, 'end_time' => $s->end_time];
        }
        $outside = [];
        $grids = [];
        foreach ($metrics as $key => $fn) {
            $grids[$key] = $fn();
            foreach ($rows as &$r) {
                $r[$key] = 0;
            }
            unset($r);
            $outside[$key] = 0;
            foreach ($grids[$key] as $cell) {
                if (isset($rows[$cell['shift']])) {
                    $rows[$cell['shift']][$key] += $cell['count'];
                } else {
                    $outside[$key] += $cell['count'];
                }
            }
        }

        // Kun × smena (tahlillar) — kunlik davrlar uchun
        $heat = null;
        if ($p->bucket === 'day') {
            $days = $p->bucketKeys();
            $cells = [];
            foreach ($grids['analyses'] as $cell) {
                if (isset($rows[$cell['shift']]) && in_array($cell['date'], $days, true)) {
                    $cells[$cell['shift']][$cell['date']] = ($cells[$cell['shift']][$cell['date']] ?? 0) + $cell['count'];
                }
            }
            $heat = [
                'metric' => 'analyses',
                'days'   => array_map(fn ($d) => ['date' => $d, 'future' => $p->bucketIsFuture($d)], $days),
                'rows'   => array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'cells' => (object) ($cells[$r['id']] ?? [])], array_values($rows)),
            ];
        }

        return [
            'configured'      => true,
            'rows'            => array_values($rows),
            'outside'         => array_sum($outside) > 0 ? $outside : null,
            'signoffs_no_time' => $hasTime ? $this->signoffs($p->from, $p->end, true)->whereNull('worksheet_signoffs.performed_time')->count() : null,
            'staff_active'    => $this->activeStaff($p->from, $p->end),
            'live'            => $this->liveShift(),
            'heat'            => $heat,
        ];
    }

    /**
     * Imzolangan bosqichlar. Imzoda sana (performed_at) va ixtiyoriy soat (performed_time) bor.
     * Smena davrida — sana + soat bo'yicha; boshqa davrlarda — sana bo'yicha.
     */
    private function signoffs(Carbon $a, Carbon $b, bool $dateOnly = false): Builder
    {
        $q = DB::table('worksheet_signoffs')
            ->join('worksheets', 'worksheets.id', '=', 'worksheet_signoffs.worksheet_id')
            ->whereNull('worksheets.deleted_at')
            ->whereNotNull('worksheet_signoffs.performed_at');
        if ($this->hasFilters() && $this->hasTable('worksheet_samples')) {
            $q->whereIn('worksheets.id', function (Builder $s) {
                $s->select('worksheet_samples.worksheet_id')->from('worksheet_samples')
                  ->join('samples', 'samples.id', '=', 'worksheet_samples.sample_id')
                  ->whereNull('samples.deleted_at');
                $this->applySampleFilters($s);
            });
        }

        // Sana ustuni (date) «YYYY-MM-DD» satri bilan solishtiriladi: hamma bazada bir xil,
        // SQL Server «date» turida ISO ko'rinish til sozlamasiga bog'liq emas
        $day = fn (Carbon $c) => $c->toDateString();
        $next = fn (Carbon $c) => $c->copy()->addDay()->toDateString();
        $col = 'worksheet_signoffs.performed_at';
        $timeCol = 'worksheet_signoffs.performed_time';
        $exact = ! $dateOnly && $this->p->shift !== null && $this->hasColumn('worksheet_signoffs', 'performed_time');

        if (! $exact) {
            return $q->where($col, '>=', $day($a))->where($col, '<', $next($b));
        }

        $ta = $a->format('H:i');
        $tb = $b->format('H:i');
        if ($day($a) === $day($b)) {
            return $q->where($col, '>=', $day($a))->where($col, '<', $next($a))
                ->where($timeCol, '>=', $ta)->where($timeCol, '<=', $tb);
        }

        return $q->where(function (Builder $w) use ($a, $b, $ta, $tb, $col, $timeCol, $day, $next) {
            $w->where(fn (Builder $x) => $x->where($col, '>=', $day($a))->where($col, '<', $next($a))->where($timeCol, '>=', $ta))
              ->orWhere(fn (Builder $x) => $x->where($col, '>=', $day($b))->where($col, '<', $next($b))->where($timeCol, '<=', $tb))
              ->orWhere(fn (Builder $x) => $x->where($col, '>=', $next($a))->where($col, '<', $day($b)));
        });
    }

    /** Davrda biror ish qilgan xodimlar soni (qabul, natija, varaq, imzo) */
    private function activeStaff(Carbon $a, Carbon $b): int
    {
        $ids = [];
        $add = function (iterable $values) use (&$ids) {
            foreach ($values as $v) {
                if ($v) {
                    $ids[(int) $v] = true;
                }
            }
        };
        $add($this->between($this->samples(), 'samples.registered_at', $a, $b)->distinct()->pluck('samples.registered_by'));
        $add($this->between($this->analyses(), 'sample_elements.result_entered_at', $a, $b)->distinct()->pluck('sample_elements.result_entered_by'));
        if ($this->hasTable('worksheets')) {
            $add($this->between($this->worksheets(), 'worksheets.created_at', $a, $b)->distinct()->pluck('worksheets.created_by'));
            $add($this->signoffs($a, $b)->whereNotNull('worksheet_signoffs.performer_id')->distinct()->pluck('worksheet_signoffs.performer_id'));
        }

        return count($ids);
    }

    /** Hozirgi smena: boshlanganidan beri */
    private function liveShift(): ?array
    {
        $current = $this->calendar->current();
        if (! $current) {
            return null;
        }
        $a = $current['start'];
        $b = now();

        return ShiftCalendar::present($current) + [
            'received'  => $this->between($this->samples(), 'samples.registered_at', $a, $b)->count(),
            'completed' => $this->between($this->samples(), 'samples.completed_at', $a, $b)->count(),
            'analyses'  => $this->between($this->analyses(), 'sample_elements.result_entered_at', $a, $b)->count(),
            'worksheets' => $this->hasTable('worksheets') ? $this->between($this->worksheets(), 'worksheets.created_at', $a, $b)->count() : null,
        ];
    }

    // ================================================================== 5. GURUHLAR

    private function blockGroups(): array
    {
        $p = $this->p;
        $col = 'samples.assigned_group_id';
        $inWork = self::countBy($this->samples()->whereIn('samples.status', self::OPEN), $col);
        $overdue = self::countBy($this->samples()->whereIn('samples.status', self::OPEN)->where('samples.registered_at', '<=', $this->overdueLimit()), $col);
        $received = self::countBy($this->between($this->samples(), 'samples.registered_at', $p->from, $p->end), $col);
        $completed = self::countBy($this->between($this->samples(), 'samples.completed_at', $p->from, $p->end), $col);
        $analyses = self::countBy(
            $this->between($this->analyses(), 'sample_elements.result_entered_at', $p->from, $p->end)
                ->join('samples', 'samples.id', '=', 'sample_elements.sample_id'),
            $col
        );
        $cycles = [];
        foreach ($this->cycleMinutes($p->from, $p->end, $col) as $r) {
            $cycles[$r->g === null ? '' : (string) $r->g][] = (int) $r->m;
        }

        $keys = array_unique(array_merge(array_keys($inWork), array_keys($received), array_keys($completed), array_keys($analyses)));
        $groups = LaboratoryGroup::query()
            ->whereIn('id', array_values(array_filter(array_map('intval', $keys))))
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'code', 'name_ru', 'name_uz', 'name_en', 'laboratory_id', 'sort_order']);

        $row = function (string $k, ?array $info) use ($inWork, $overdue, $received, $completed, $analyses, $cycles) {
            return ($info ?? ['id' => null, 'code' => null, 'name_ru' => null, 'name_uz' => null, 'name_en' => null]) + [
                'in_work'      => $inWork[$k] ?? 0,
                'overdue'      => $overdue[$k] ?? 0,
                'received'     => $received[$k] ?? 0,
                'completed'    => $completed[$k] ?? 0,
                'analyses'     => $analyses[$k] ?? 0,
                'median_hours' => self::durationStats($cycles[$k] ?? [])['median'],
            ];
        };

        $rows = [];
        foreach ($groups as $g) {
            $rows[] = $row((string) $g->id, $g->only(['id', 'code', 'name_ru', 'name_uz', 'name_en']));
        }
        if (in_array('', $keys, true)) {
            $rows[] = $row('', null) + ['unassigned' => true];
        }

        return ['rows' => $rows];
    }

    // ================================================================== 6. BUYURTMACHILAR

    private function blockCustomers(): array
    {
        if (! $this->hasColumn('samples', 'customer_department_id') || ! $this->hasTable('customer_departments')) {
            return ['available' => false];
        }
        $p = $this->p;
        $col = 'samples.customer_department_id';
        $received = self::countBy($this->between($this->samples(), 'samples.registered_at', $p->from, $p->end), $col);
        $completed = self::countBy($this->between($this->samples(), 'samples.completed_at', $p->from, $p->end), $col);
        $inWork = self::countBy($this->samples()->whereIn('samples.status', self::OPEN), $col);
        $overdue = self::countBy($this->samples()->whereIn('samples.status', self::OPEN)->where('samples.registered_at', '<=', $this->overdueLimit()), $col);
        $cycles = [];
        foreach ($this->cycleMinutes($p->from, $p->end, $col) as $r) {
            $cycles[$r->g === null ? '' : (string) $r->g][] = (int) $r->m;
        }

        $keys = array_unique(array_merge(array_keys($received), array_keys($completed), array_keys($inWork)));
        $names = DB::table('customer_departments')
            ->whereIn('id', array_values(array_filter(array_map('intval', $keys))))
            ->get(['id', 'code', 'name'])->keyBy('id');

        $rows = [];
        foreach ($keys as $k) {
            $d = $k !== '' ? $names->get((int) $k) : null;
            $rows[] = [
                'id'           => $k !== '' ? (int) $k : null,
                'code'         => $d->code ?? null,
                'name'         => $d->name ?? null,
                'received'     => $received[$k] ?? 0,
                'completed'    => $completed[$k] ?? 0,
                'in_work'      => $inWork[$k] ?? 0,
                'overdue'      => $overdue[$k] ?? 0,
                'median_hours' => self::durationStats($cycles[$k] ?? [])['median'],
            ];
        }
        // Ko'p topshirganlar yuqorida; «bo'lim ko'rsatilmagan» — oxirida
        usort($rows, fn ($a, $b) => [$a['id'] === null, -$a['received'], -$a['in_work'], (string) $a['name']]
            <=> [$b['id'] === null, -$b['received'], -$b['in_work'], (string) $b['name']]);

        $top = array_slice($rows, 0, 12);
        $rest = array_slice($rows, 12);
        $other = null;
        if ($rest) {
            $other = ['count' => count($rest)];
            foreach (['received', 'completed', 'in_work', 'overdue'] as $f) {
                $other[$f] = array_sum(array_column($rest, $f));
            }
        }

        return ['available' => true, 'rows' => $top, 'other' => $other];
    }

    // ================================================================== 7. ISHCHI VARAQLAR

    private function blockWorksheets(): array
    {
        if (! $this->hasTable('worksheets')) {
            return ['available' => false];
        }
        $p = $this->p;
        $created = $this->between($this->worksheets(), 'worksheets.created_at', $p->from, $p->end);
        $byKind = $this->hasKind() ? self::countBy(clone $created, 'worksheets.kind') : ['main' => (clone $created)->count()];

        $open = self::countBy($this->worksheets()->whereIn('worksheets.status', ['draft', 'printed', 'in_progress', 'completed']), 'worksheets.status');

        $awaiting = $this->worksheets()->where('worksheets.status', 'completed');
        $awaitingOld = (clone $awaiting)->where(function (Builder $w) {
            $limit = now()->subDays(2);
            $w->where('worksheets.completed_at', '<=', $limit)
              ->orWhere(fn (Builder $x) => $x->whereNull('worksheets.completed_at')->where('worksheets.updated_at', '<=', $limit));
        })->count();
        $cols = ['worksheets.id', 'worksheets.number', 'worksheets.status', 'worksheets.completed_at', 'worksheets.updated_at', 'worksheets.worksheet_date'];
        if ($this->hasKind()) {
            $cols[] = 'worksheets.kind';
        }
        $awaitingList = (clone $awaiting)->orderBy('worksheets.completed_at')->orderBy('worksheets.id')->limit(8)->get($cols)
            ->map(function ($w) {
                $at = $w->completed_at ?: $w->updated_at;

                return [
                    'id'           => (int) $w->id,
                    'number'       => $w->number,
                    'kind'         => ($w->kind ?? null) ?: 'main',
                    'completed_at' => $at ? Carbon::parse($at)->format('Y-m-d H:i:s') : null,
                    'days'         => $at ? (int) floor(max(0, now()->getTimestamp() - Carbon::parse($at)->getTimestamp()) / 86400) : null,
                ];
            })->all();

        $out = [
            'available'     => true,
            'created'       => ['main' => $byKind['main'] ?? 0, 'control' => $byKind['control'] ?? 0],
            'open'          => [
                'draft'       => $open['draft'] ?? 0,
                'printed'     => $open['printed'] ?? 0,
                'in_progress' => $open['in_progress'] ?? 0,
                'completed'   => $open['completed'] ?? 0,
            ],
            'approved'      => $this->between($this->worksheets(), 'worksheets.approved_at', $p->from, $p->end)->count(),
            'with_warnings' => (clone $created)->where('worksheets.has_warnings', true)->count(),
            'awaiting'      => ['count' => $open['completed'] ?? 0, 'old' => $awaitingOld, 'items' => $awaitingList],
            'extra_parallels' => null,
            'retests'       => null,
        ];

        if ($this->hasColumn('worksheet_rows', 'is_extra')) {
            $out['extra_parallels'] = $this->between(
                DB::table('worksheet_rows')->where('worksheet_rows.is_extra', true)
                    ->whereIn('worksheet_rows.worksheet_id', $this->worksheets()->select('worksheets.id')),
                'worksheet_rows.created_at', $p->from, $p->end
            )->count();
        }
        if ($this->hasColumn('worksheet_samples', 'retest_at')) {
            $out['retests'] = $this->between(
                $this->ofSamples(DB::table('worksheet_samples')->where('worksheet_samples.status', 'retest'), 'worksheet_samples.sample_id'),
                'worksheet_samples.retest_at', $p->from, $p->end
            )->count();
        }

        return $out;
    }

    // ================================================================== 8. PROBA QOLDIQLARI

    private function blockBalances(): array
    {
        $ledgerClass = 'App\\Services\\Samples\\SampleLedger';
        if (! $this->hasColumn('samples', 'received_quantity') || ! class_exists($ledgerClass)) {
            return ['available' => false];
        }
        $ledger = app($ledgerClass);

        $q = Sample::query()->whereIn('samples.status', self::OPEN)->whereNotNull('samples.received_quantity');
        $this->applySampleFilters($q->getQuery());
        $samples = $q->orderByDesc('samples.registered_at')->orderByDesc('samples.id')->limit(2000)->get();

        $counts = ['ok' => 0, 'low' => 0, 'empty' => 0, 'negative' => 0, 'unknown' => 0];
        $items = [];
        foreach ($samples->chunk(500) as $chunk) {
            foreach ($ledger->summaries($chunk) as $id => $sum) {
                $state = $sum['state'] ?? 'unknown';
                $counts[$state] = ($counts[$state] ?? 0) + 1;
                if (in_array($state, ['low', 'empty', 'negative'], true)) {
                    $s = $chunk->firstWhere('id', $id);
                    $items[] = [
                        'id'        => (int) $id,
                        'code'      => $s?->code,
                        'state'     => $state,
                        'balance'   => $sum['balance'],
                        'available' => $sum['available'],
                        'received'  => $sum['received'],
                        'unit'      => $sum['unit'],
                    ];
                }
            }
        }
        $rank = ['negative' => 0, 'empty' => 1, 'low' => 2];
        usort($items, fn ($a, $b) => [$rank[$a['state']], $a['available'] ?? 0] <=> [$rank[$b['state']], $b['available'] ?? 0]);

        return [
            'available' => true,
            'checked'   => $samples->count(),
            'counts'    => $counts,
            'attention' => $counts['low'] + $counts['empty'] + $counts['negative'],
            'items'     => array_slice($items, 0, 8),
        ];
    }

    // ================================================================== 9. USKUNALAR

    private function blockEquipment(): array
    {
        $query = Equipment::query();
        if ($lab = $this->f['laboratory_id']) {
            $query->where('laboratory_id', $lab);
        }
        $total = (clone $query)->count();
        $working = (clone $query)->where('status', 'working')->where('is_blocked', false)->count();
        $overdueQ = (clone $query)->whereNotNull('next_calibration_at')->whereDate('next_calibration_at', '<', now()->toDateString());

        return [
            'total'     => $total,
            'working'   => $working,
            'blocked'   => (clone $query)->where('is_blocked', true)->count(),
            'waiting'   => (clone $query)->where('status', 'awaiting_calibration')->count(),
            'overdue'   => (clone $overdueQ)->count(),
            'due_soon'  => (clone $query)->whereNotNull('next_calibration_at')
                ->whereDate('next_calibration_at', '>=', now()->toDateString())
                ->whereDate('next_calibration_at', '<=', now()->addDays(14)->toDateString())->count(),
            'readiness' => $total > 0 ? (int) round($working / $total * 100) : null,
            'items'     => (clone $overdueQ)->orderBy('next_calibration_at')->limit(6)
                ->get(['id', 'name', 'serial_number', 'next_calibration_at', 'is_blocked'])
                ->map(fn ($e) => [
                    'id'                  => (int) $e->id,
                    'name'                => $e->name,
                    'serial_number'       => $e->serial_number,
                    'next_calibration_at' => $e->next_calibration_at ? Carbon::parse($e->next_calibration_at)->toDateString() : null,
                    'is_blocked'          => (bool) $e->is_blocked,
                ])->all(),
        ];
    }

    // ================================================================== 10. EKOLOGIYA

    private function blockEcology(): array
    {
        if (! $this->hasTable('water_order_results')) {
            return ['available' => false];
        }
        $p = $this->p;
        $base = WaterOrderResult::query()->whereNotNull('value')
            ->where('measured_at', '>=', $p->from)->where('measured_at', '<=', $p->end);
        $by = [];
        foreach ((clone $base)->selectRaw('assessment, COUNT(*) as c')->groupBy('assessment')->get() as $r) {
            $by[(string) $r->assessment] = (int) $r->c;
        }

        $points = (clone $base)->where('assessment', 'critical')->with('order.monitoringPoint')->limit(500)->get()
            ->groupBy(fn ($r) => $r->order?->monitoringPoint?->name ?? '—')
            ->map(fn ($items, $name) => [
                'name'       => $name,
                'count'      => $items->count(),
                'parameters' => $items->pluck('parameter')->unique()->values()->all(),
            ])->sortByDesc('count')->values()->take(6)->all();

        return [
            'available'      => true,
            'measurements'   => array_sum($by),
            'critical'       => $by['critical'] ?? 0,
            'warning'        => $by['warning'] ?? 0,
            'normal'         => $by['normal'] ?? 0,
            'orders'         => WaterOrder::query()->where('registered_at', '>=', $p->from)->where('registered_at', '<=', $p->end)->count(),
            'problem_points' => $points,
        ];
    }
}
