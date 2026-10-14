<?php

namespace App\Services\Insights;

use App\Models\LaboratoryGroup;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Xodimlar faoliyati: har xodim davr ichida nima qilgani (manba jadvallardagi «kim» va «qachon» ustunlari bo'yicha).
 *
 *   received   — probani ro'yxatga oldi        samples.registered_by / registered_at
 *   started    — probani ishga oldi            samples.started_by / started_at
 *   results    — element natijasini kiritdi    sample_elements.result_entered_by / result_entered_at
 *   worksheets — ishchi varaq ochdi            worksheets.created_by / created_at
 *   signoffs   — varaq bosqichini bajardi      worksheet_signoffs.performer_id / performed_at (+ performed_time)
 *   approved   — varaqni tasdiqladi            worksheets.approved_by / approved_at
 *   extra      — qo'shimcha parallel qo'shdi   worksheet_rows.added_by / created_at (is_extra)
 *   retests    — takroriy tahlilga qaytardi    worksheet_samples.retest_by / retest_at
 *   movements  — proba miqdori yozuvi          sample_quantity_movements.created_by / created_at
 *   water      — suv natijasini kiritdi        water_order_results.entered_by / created_at
 *   equipment  — uskunani ishlatdi             equipment_usages.user_id / used_at
 *
 * Reyting yo'q: xodimlar guruh va ism bo'yicha tartiblanadi.
 */
class StaffActivity
{
    public const METRICS = ['received', 'started', 'results', 'worksheets', 'signoffs', 'approved', 'extra', 'retests', 'movements', 'water', 'equipment'];

    private array $schema = [];
    private ?array $sources = null;

    public function __construct(private ShiftCalendar $calendar)
    {
    }

    // ================================================================== sxema

    private function hasTable(string $t): bool
    {
        return $this->schema["t:$t"] ??= Schema::hasTable($t);
    }

    private function hasColumn(string $t, string $c): bool
    {
        return $this->schema["c:$t.$c"] ??= $this->hasTable($t) && Schema::hasColumn($t, $c);
    }

    private function aliveSamples(Builder $q, string $col): Builder
    {
        return $q->whereIn($col, fn (Builder $s) => $s->select('samples.id')->from('samples')->whereNull('samples.deleted_at'));
    }

    /**
     * Mavjud manbalar (o'rnatilmagan modul jadvallari tashlab ketiladi).
     * @return array<string, array{user:string, at:string, time?:string, base:Closure}>
     */
    public function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }
        $s = [];
        $s['received'] = ['user' => 'samples.registered_by', 'at' => 'samples.registered_at',
            'base' => fn () => DB::table('samples')->whereNull('samples.deleted_at')];
        if ($this->hasColumn('samples', 'started_by')) {
            $s['started'] = ['user' => 'samples.started_by', 'at' => 'samples.started_at',
                'base' => fn () => DB::table('samples')->whereNull('samples.deleted_at')];
        }
        $s['results'] = ['user' => 'sample_elements.result_entered_by', 'at' => 'sample_elements.result_entered_at',
            'base' => fn () => $this->aliveSamples(DB::table('sample_elements')->where('sample_elements.status', 'done'), 'sample_elements.sample_id')];
        if ($this->hasTable('worksheets')) {
            $s['worksheets'] = ['user' => 'worksheets.created_by', 'at' => 'worksheets.created_at',
                'base' => fn () => DB::table('worksheets')->whereNull('worksheets.deleted_at')];
            if ($this->hasTable('worksheet_signoffs')) {
                $s['signoffs'] = ['user' => 'worksheet_signoffs.performer_id', 'at' => 'worksheet_signoffs.performed_at',
                    'time' => $this->hasColumn('worksheet_signoffs', 'performed_time') ? 'worksheet_signoffs.performed_time' : null,
                    'date_only' => true,
                    'base' => fn () => DB::table('worksheet_signoffs')
                        ->join('worksheets', 'worksheets.id', '=', 'worksheet_signoffs.worksheet_id')
                        ->whereNull('worksheets.deleted_at')];
            }
            $s['approved'] = ['user' => 'worksheets.approved_by', 'at' => 'worksheets.approved_at',
                'base' => fn () => DB::table('worksheets')->whereNull('worksheets.deleted_at')];
            if ($this->hasColumn('worksheet_rows', 'added_by')) {
                $s['extra'] = ['user' => 'worksheet_rows.added_by', 'at' => 'worksheet_rows.created_at',
                    'base' => fn () => DB::table('worksheet_rows')
                        ->join('worksheets', 'worksheets.id', '=', 'worksheet_rows.worksheet_id')
                        ->whereNull('worksheets.deleted_at')
                        ->where('worksheet_rows.is_extra', true)];
            }
            if ($this->hasColumn('worksheet_samples', 'retest_by')) {
                $s['retests'] = ['user' => 'worksheet_samples.retest_by', 'at' => 'worksheet_samples.retest_at',
                    'base' => fn () => DB::table('worksheet_samples')
                        ->join('worksheets', 'worksheets.id', '=', 'worksheet_samples.worksheet_id')
                        ->whereNull('worksheets.deleted_at')];
            }
        }
        if ($this->hasTable('sample_quantity_movements')) {
            $s['movements'] = ['user' => 'sample_quantity_movements.created_by', 'at' => 'sample_quantity_movements.created_at',
                'base' => fn () => DB::table('sample_quantity_movements')->whereNull('sample_quantity_movements.deleted_at')];
        }
        if ($this->hasColumn('water_order_results', 'entered_by')) {
            $s['water'] = ['user' => 'water_order_results.entered_by', 'at' => 'water_order_results.created_at',
                'base' => fn () => DB::table('water_order_results')->whereNotNull('water_order_results.value')];
        }
        if ($this->hasColumn('equipment_usages', 'user_id')) {
            $s['equipment'] = ['user' => 'equipment_usages.user_id', 'at' => 'equipment_usages.used_at',
                'base' => fn () => DB::table('equipment_usages')];
        }

        return $this->sources = $s;
    }

    // ================================================================== filtrlar

    /** Davr: vaqt ustuni bo'yicha; imzolar — sana (+ smena davrida soat) bo'yicha */
    private function window(Builder $q, array $src, InsightPeriod $p): Builder
    {
        if (empty($src['date_only'])) {
            return $q->where($src['at'], '>=', $p->from)->where($src['at'], '<=', $p->end);
        }
        $col = $src['at'];
        $day = fn (Carbon $c) => $c->toDateString();
        $next = fn (Carbon $c) => $c->copy()->addDay()->toDateString();
        $a = $p->from;
        $b = $p->end;
        $timeCol = $src['time'] ?? null;
        if ($p->shift === null || ! $timeCol) {
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

    /** Xodimlar cheklovi: bitta xodim yoki guruh/laboratoriya bo'yicha */
    private function restrictUsers(Builder $q, string $col, array $f): Builder
    {
        $q->whereNotNull($col);
        if (! empty($f['user_id'])) {
            return $q->where($col, (int) $f['user_id']);
        }
        if (! empty($f['group_id']) || ! empty($f['laboratory_id'])) {
            $q->whereIn($col, function (Builder $s) use ($f) {
                $s->select('users.id')->from('users');
                if (! empty($f['group_id'])) {
                    $s->where('users.laboratory_group_id', (int) $f['group_id']);
                }
                if (! empty($f['laboratory_id'])) {
                    $s->where('users.laboratory_id', (int) $f['laboratory_id']);
                }
            });
        }

        return $q;
    }

    // ================================================================== jadval

    /**
     * Davr bo'yicha har xodim: ko'rsatkichlar, smenalar, faol kunlar, birinchi/oxirgi amal.
     * @param  array  $f  laboratory_id, group_id, user_id
     * @param  bool   $withDays  har kun bo'yicha tafsilot (Excel va xodim sahifasi uchun)
     */
    public function summary(InsightPeriod $p, array $f = [], bool $includeIdle = false, bool $withDays = false): array
    {
        $grain = $p->bucket === 'month' ? 'month' : 'day';
        $rows = [];
        $blank = ['metrics' => [], 'shifts' => [], 'days' => [], 'day_shifts' => [], 'first_at' => null, 'last_at' => null];

        foreach ($this->sources() as $metric => $src) {
            // kun × smena × xodim
            $q = $this->restrictUsers($this->window(($src['base'])(), $src, $p), $src['user'], $f);
            $dayExpr = $grain === 'month' ? TimeSql::month($src['at']) : TimeSql::day($src['at']);
            if (! empty($src['date_only'])) {
                $case = ($src['time'] ?? null)
                    ? "CASE WHEN {$src['time']} IS NULL THEN NULL ELSE " . $this->calendar->caseSql($src['time']) . ' END'
                    : 'NULL';
            } else {
                $case = $this->calendar->caseSql(TimeSql::hm($src['at']));
            }
            $q->selectRaw("{$src['user']} as u, {$dayExpr} as d, {$case} as s");
            foreach (DB::query()->fromSub($q, 't')->selectRaw('u, d, s, COUNT(*) as c')->groupBy('u', 'd', 's')->get() as $r) {
                $u = (int) $r->u;
                $c = (int) $r->c;
                $d = trim((string) $r->d);
                if ($r->s === null) {
                    $sk = 'nt';
                } else {
                    $code = (int) $r->s;
                    if ($code < 0 && $grain === 'day') {
                        $d = Carbon::parse($d)->subDay()->toDateString();
                    }
                    $sk = $code === 0 ? 'out' : (string) abs($code);
                }
                $rows[$u] ??= $blank;
                $rows[$u]['metrics'][$metric] = ($rows[$u]['metrics'][$metric] ?? 0) + $c;
                $rows[$u]['shifts'][$sk] = ($rows[$u]['shifts'][$sk] ?? 0) + $c;
                $rows[$u]['days'][$d][$metric] = ($rows[$u]['days'][$d][$metric] ?? 0) + $c;
                $rows[$u]['day_shifts'][$d][$sk] = ($rows[$u]['day_shifts'][$d][$sk] ?? 0) + $c;
            }

            // birinchi va oxirgi amal (vaqti aniq manbalar)
            if (empty($src['date_only'])) {
                $q2 = $this->restrictUsers($this->window(($src['base'])(), $src, $p), $src['user'], $f)
                    ->selectRaw("{$src['user']} as u, MIN({$src['at']}) as a, MAX({$src['at']}) as b")
                    ->groupBy($src['user']);
                foreach ($q2->get() as $r) {
                    $u = (int) $r->u;
                    $rows[$u] ??= $blank;
                    $a = $r->a ? Carbon::parse($r->a)->format('Y-m-d H:i:s') : null;
                    $b = $r->b ? Carbon::parse($r->b)->format('Y-m-d H:i:s') : null;
                    if ($a && (! $rows[$u]['first_at'] || $a < $rows[$u]['first_at'])) {
                        $rows[$u]['first_at'] = $a;
                    }
                    if ($b && (! $rows[$u]['last_at'] || $b > $rows[$u]['last_at'])) {
                        $rows[$u]['last_at'] = $b;
                    }
                }
            }
        }

        // Faolligi yo'q xodimlar (so'ralsa): faol, buyurtmachi bo'lmagan, filtrga mos
        if ($includeIdle && empty($f['user_id'])) {
            foreach ($this->usersQuery($f)->pluck('users.id') as $id) {
                $rows[(int) $id] ??= $blank;
            }
        }

        $users = $this->userInfo(array_keys($rows));
        $out = [];
        foreach ($rows as $u => $r) {
            $info = $users[$u] ?? ['id' => $u, 'name' => '#' . $u, 'username' => null, 'position' => null, 'is_active' => false, 'group' => null, 'role' => null];
            ksort($r['days']);
            $item = [
                'user'        => $info,
                'metrics'     => (object) $r['metrics'],
                'shifts'      => (object) $r['shifts'],
                'active_days' => $grain === 'day' ? count($r['days']) : null,
                'first_at'    => $r['first_at'],
                'last_at'     => $r['last_at'],
            ];
            if ($withDays) {
                $item['days'] = (object) array_map(fn ($m) => (object) $m, $r['days']);
                ksort($r['day_shifts']);
                $item['day_shifts'] = (object) array_map(fn ($m) => (object) $m, $r['day_shifts']);
            }
            $out[] = $item;
        }

        // Tartib: guruh (tartib raqami, nomi), so'ng ism — reyting emas
        usort($out, function ($a, $b) {
            $ga = $a['user']['group'];
            $gb = $b['user']['group'];

            return [$ga === null, $ga['sort_order'] ?? 0, (string) ($ga['name_uz'] ?? ''), mb_strtolower((string) $a['user']['name'])]
                <=> [$gb === null, $gb['sort_order'] ?? 0, (string) ($gb['name_uz'] ?? ''), mb_strtolower((string) $b['user']['name'])];
        });

        $totals = ['metrics' => [], 'shifts' => []];
        foreach ($out as $r) {
            foreach ((array) $r['metrics'] as $k => $v) {
                $totals['metrics'][$k] = ($totals['metrics'][$k] ?? 0) + $v;
            }
            foreach ((array) $r['shifts'] as $k => $v) {
                $totals['shifts'][$k] = ($totals['shifts'][$k] ?? 0) + $v;
            }
        }

        return [
            'grain'   => $grain,
            'metrics' => array_keys($this->sources()),
            'rows'    => $out,
            'totals'  => ['metrics' => (object) $totals['metrics'], 'shifts' => (object) $totals['shifts']],
        ];
    }

    /** Faol laboratoriya xodimlari (buyurtmachi roli emas) + filtr */
    public function usersQuery(array $f = [])
    {
        $q = User::query()->where('users.is_active', true)
            ->where(fn ($w) => $w->whereNull('users.role_id')
                ->orWhereNotIn('users.role_id', fn (Builder $s) => $s->select('roles.id')->from('roles')->where('roles.name', 'customer')));
        if (! empty($f['group_id'])) {
            $q->where('users.laboratory_group_id', (int) $f['group_id']);
        }
        if (! empty($f['laboratory_id'])) {
            $q->where('users.laboratory_id', (int) $f['laboratory_id']);
        }

        return $q;
    }

    /** Xodim ma'lumoti: ism, lavozim, guruh, rol */
    public function userInfo(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            $users = User::query()->whereIn('id', $chunk)->with(['laboratoryGroup', 'role'])->get();
            foreach ($users as $u) {
                $g = $u->laboratoryGroup;
                $out[(int) $u->id] = [
                    'id'        => (int) $u->id,
                    'name'      => $u->name,
                    'username'  => $u->username,
                    'position'  => $u->position,
                    'is_active' => (bool) $u->is_active,
                    'group'     => $g ? ['id' => (int) $g->id, 'code' => $g->code, 'name_uz' => $g->name_uz, 'name_ru' => $g->name_ru, 'name_en' => $g->name_en, 'sort_order' => (int) ($g->sort_order ?? 0)] : null,
                    'role'      => $u->role ? ['name' => $u->role->name, 'display_name_uz' => $u->role->display_name_uz, 'display_name_ru' => $u->role->display_name_ru, 'display_name_en' => $u->role->display_name_en] : null,
                ];
            }
        }

        return $out;
    }

    // ================================================================== vaqt chizig'i

    /**
     * Bitta xodimning davrdagi amallari (yangisi birinchi), eng ko'pi $limit ta.
     * Har amal: turi, vaqti, smenasi, nimaga tegishli (proba/varaq/…) va havola uchun id.
     */
    public function timeline(int $userId, InsightPeriod $p, bool $withLog = false, int $limit = 500): array
    {
        $src = $this->sources();
        $per = $limit + 1;
        $events = [];
        $add = function (string $type, $at, array $data, bool $timeKnown = true) use (&$events) {
            if (! $at) {
                return;
            }
            $c = Carbon::parse($at);
            $events[] = ['type' => $type, 'at' => $c->format('Y-m-d H:i:s'), 'time_known' => $timeKnown] + $data;
        };
        $f = ['user_id' => $userId];
        $q = fn (string $m) => $this->restrictUsers($this->window(($src[$m]['base'])(), $src[$m], $p), $src[$m]['user'], $f);

        if (isset($src['received'])) {
            foreach ($q('received')->orderByDesc('samples.registered_at')->limit($per)
                ->get(['samples.id', 'samples.code', 'samples.sample_number', 'samples.registered_at']) as $r) {
                $add('received', $r->registered_at, ['sample' => ['id' => (int) $r->id, 'code' => $r->code, 'number' => $r->sample_number]]);
            }
        }
        if (isset($src['started'])) {
            foreach ($q('started')->orderByDesc('samples.started_at')->limit($per)
                ->get(['samples.id', 'samples.code', 'samples.sample_number', 'samples.started_at']) as $r) {
                $add('started', $r->started_at, ['sample' => ['id' => (int) $r->id, 'code' => $r->code, 'number' => $r->sample_number]]);
            }
        }
        if (isset($src['results'])) {
            // Bir probaga bir daqiqada kiritilgan natijalar — bitta amal
            $groups = [];
            foreach ($q('results')
                ->join('samples', 'samples.id', '=', 'sample_elements.sample_id')
                ->leftJoin('elements', 'elements.id', '=', 'sample_elements.element_id')
                ->orderByDesc('sample_elements.result_entered_at')->limit($per * 3)
                ->get(['sample_elements.sample_id', 'samples.code', 'elements.symbol', 'sample_elements.result_entered_at']) as $r) {
                $minute = Carbon::parse($r->result_entered_at)->format('Y-m-d H:i');
                $key = $r->sample_id . '|' . $minute;
                $groups[$key] ??= ['at' => $r->result_entered_at, 'sample' => ['id' => (int) $r->sample_id, 'code' => $r->code], 'elements' => []];
                if ($r->symbol && ! in_array($r->symbol, $groups[$key]['elements'], true)) {
                    $groups[$key]['elements'][] = $r->symbol;
                }
            }
            foreach ($groups as $g) {
                $add('results', $g['at'], ['sample' => $g['sample'], 'elements' => $g['elements']]);
            }
        }
        $wsCols = ['worksheets.id', 'worksheets.number'];
        if ($this->hasColumn('worksheets', 'kind')) {
            $wsCols[] = 'worksheets.kind';
        }
        if (isset($src['worksheets'])) {
            foreach ($q('worksheets')->leftJoin('worksheet_templates', 'worksheet_templates.id', '=', 'worksheets.template_id')
                ->orderByDesc('worksheets.created_at')->limit($per)
                ->get(array_merge($wsCols, ['worksheets.created_at', 'worksheet_templates.code as template_code'])) as $r) {
                $add('worksheets', $r->created_at, ['worksheet' => $this->wsRef($r) + ['template' => $r->template_code]]);
            }
        }
        if (isset($src['approved'])) {
            foreach ($q('approved')->orderByDesc('worksheets.approved_at')->limit($per)
                ->get(array_merge($wsCols, ['worksheets.approved_at'])) as $r) {
                $add('approved', $r->approved_at, ['worksheet' => $this->wsRef($r)]);
            }
        }
        if (isset($src['signoffs'])) {
            $cols = array_merge($wsCols, ['worksheets.template_snapshot', 'worksheet_signoffs.stage', 'worksheet_signoffs.slot', 'worksheet_signoffs.performed_at']);
            $hasTime = ! empty($src['signoffs']['time']);
            if ($hasTime) {
                $cols[] = 'worksheet_signoffs.performed_time';
            }
            $labels = [];
            foreach ($q('signoffs')->orderByDesc('worksheet_signoffs.performed_at')->limit($per)->get($cols) as $r) {
                if (! isset($labels[$r->id])) {
                    $snap = is_string($r->template_snapshot) ? json_decode($r->template_snapshot, true) : (array) $r->template_snapshot;
                    $labels[$r->id] = [];
                    foreach ($snap['stages'] ?? [] as $st) {
                        $labels[$r->id][$st['key'] ?? ''] = ['uz' => $st['label_uz'] ?? ($st['key'] ?? ''), 'ru' => $st['label_ru'] ?? null, 'uz_cyrl' => $st['label_uz_cyrl'] ?? null];
                    }
                }
                $date = Carbon::parse($r->performed_at)->toDateString();
                $time = $hasTime && $r->performed_time ? substr((string) $r->performed_time, 0, 5) : null;
                $add('signoffs', $date . ' ' . ($time ?? '00:00') . ':00', [
                    'worksheet' => $this->wsRef($r),
                    'stage'     => ['key' => $r->stage, 'slot' => (int) $r->slot, 'label' => $labels[$r->id][$r->stage] ?? ['uz' => $r->stage, 'ru' => null, 'uz_cyrl' => null]],
                ], $time !== null);
            }
        }
        if (isset($src['extra'])) {
            foreach ($q('extra')->leftJoin('samples', 'samples.id', '=', 'worksheet_rows.sample_id')
                ->orderByDesc('worksheet_rows.created_at')->limit($per)
                ->get(array_merge($wsCols, ['worksheet_rows.created_at', 'samples.id as sample_id', 'samples.code as sample_code'])) as $r) {
                $add('extra', $r->created_at, ['worksheet' => $this->wsRef($r), 'sample' => $r->sample_id ? ['id' => (int) $r->sample_id, 'code' => $r->sample_code] : null]);
            }
        }
        if (isset($src['retests'])) {
            foreach ($q('retests')->leftJoin('samples', 'samples.id', '=', 'worksheet_samples.sample_id')
                ->orderByDesc('worksheet_samples.retest_at')->limit($per)
                ->get(array_merge($wsCols, ['worksheet_samples.retest_at', 'worksheet_samples.retest_reason', 'samples.id as sample_id', 'samples.code as sample_code'])) as $r) {
                $add('retests', $r->retest_at, ['worksheet' => $this->wsRef($r), 'sample' => ['id' => (int) $r->sample_id, 'code' => $r->sample_code], 'note' => $r->retest_reason]);
            }
        }
        if (isset($src['movements'])) {
            foreach ($q('movements')->leftJoin('samples', 'samples.id', '=', 'sample_quantity_movements.sample_id')
                ->orderByDesc('sample_quantity_movements.created_at')->limit($per)
                ->get(['sample_quantity_movements.created_at', 'sample_quantity_movements.kind', 'sample_quantity_movements.delta', 'sample_quantity_movements.unit',
                    'sample_quantity_movements.note', 'samples.id as sample_id', 'samples.code as sample_code']) as $r) {
                $add('movements', $r->created_at, [
                    'sample' => ['id' => (int) $r->sample_id, 'code' => $r->sample_code],
                    'kind' => $r->kind, 'quantity' => round((float) $r->delta, 4), 'unit' => $r->unit, 'note' => $r->note,
                ]);
            }
        }
        if (isset($src['water'])) {
            foreach ($q('water')->leftJoin('water_orders', 'water_orders.id', '=', 'water_order_results.water_order_id')
                ->orderByDesc('water_order_results.created_at')->limit($per)
                ->get(['water_order_results.created_at', 'water_order_results.parameter', 'water_orders.id as order_id', 'water_orders.order_number']) as $r) {
                $add('water', $r->created_at, ['order' => ['id' => (int) $r->order_id, 'number' => $r->order_number], 'parameter' => $r->parameter]);
            }
        }
        if (isset($src['equipment'])) {
            foreach ($q('equipment')->leftJoin('equipment', 'equipment.id', '=', 'equipment_usages.equipment_id')
                ->orderByDesc('equipment_usages.used_at')->limit($per)
                ->get(['equipment_usages.used_at', 'equipment_usages.description', 'equipment.id as equipment_id', 'equipment.name']) as $r) {
                $add('equipment', $r->used_at, ['equipment' => ['id' => (int) $r->equipment_id, 'name' => $r->name], 'note' => $r->description]);
            }
        }
        if ($withLog && $this->hasTable('activity_logs')) {
            foreach (DB::table('activity_logs')->where('user_id', $userId)
                ->where('created_at', '>=', $p->from)->where('created_at', '<=', $p->end)
                ->orderByDesc('created_at')->limit($per)
                ->get(['created_at', 'action', 'model_type', 'model_id', 'description']) as $r) {
                $add('log', $r->created_at, ['action' => $r->action, 'model' => $r->model_type, 'model_id' => $r->model_id ? (int) $r->model_id : null, 'note' => $r->description]);
            }
        }

        // Yangisi birinchi; soatsiz imzolar — o'sha kunning oxirida
        usort($events, function ($a, $b) {
            $ka = $a['time_known'] ? $a['at'] : substr($a['at'], 0, 10) . ' 23:59:60';
            $kb = $b['time_known'] ? $b['at'] : substr($b['at'], 0, 10) . ' 23:59:60';

            return strcmp($kb, $ka);
        });
        $truncated = count($events) > $limit;
        $events = array_slice($events, 0, $limit);

        // Smena
        foreach ($events as &$e) {
            $e['shift_id'] = null;
            if ($e['time_known']) {
                $inst = $this->calendar->at(Carbon::parse($e['at']));
                $e['shift_id'] = $inst['id'] ?? 0;
            }
        }
        unset($e);

        return ['events' => $events, 'truncated' => $truncated, 'limit' => $limit];
    }

    private function wsRef(object $r): array
    {
        return ['id' => (int) $r->id, 'number' => $r->number, 'kind' => ($r->kind ?? null) ?: 'main'];
    }

    /** Guruhlar ro'yxati (filtr uchun) */
    public function groups(): array
    {
        return LaboratoryGroup::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'code', 'name_uz', 'name_ru', 'name_en'])->map(fn ($g) => $g->only(['id', 'code', 'name_uz', 'name_ru', 'name_en']))->all();
    }
}
