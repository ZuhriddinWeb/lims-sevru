<?php

namespace App\Services\Insights;

use App\Models\Equipment;
use App\Models\LaboratoryGroup;
use App\Models\Sample;
use App\Models\TestObject;
use App\Models\User;
use App\Models\WaterOrder;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ish stoli — bosh sahifa, foydalanuvchining ruxsatlariga qarab.
 *
 * Kartochkalar (har biri — faqat kerakli ruxsat bo'lsa):
 *   intake        samples.create            bugungi qabul (o'zim / jami), guruhga uzatilmaganlar
 *   queue         samples.result | worksheets.update  (+ xodimning guruhi)  guruh navbati
 *   my_worksheets worksheets.view + create|update   ochiq ishchi varaqlarim (o'zim ochgan yoki guruhimniki)
 *   retests       worksheets.update         takroriy tahlilga qaytarilganlar
 *   approvals     worksheets.approve        tasdiq kutayotgan varaqlar
 *   live          executive.view            hozirgi smena (yoki bugun) — butun laboratoriya
 *   overdue       executive.view            7 kundan ortiq yakunlanmagan probalar
 *   calibration   equipment.view            kalibrovka muddati o'tgan / 30 kun ichida
 *   storage       test_objects.view         saqlash muddati 14 kun ichida tugaydigan obyektlar
 *   water         water_orders.result|create  ochiq suv buyurtmalari
 *   balances      sample_quantity.manage    qoldig'i kam / tugagan probalar
 * Har kartochka mustaqil: bittasida xato bo'lsa, qolganlari chiqaveradi.
 */
class WorkDesk
{
    public const OVERDUE_DAYS = 7;
    public const LIMIT = 6;

    private array $schema = [];
    private User $user;
    private ?int $group = null;

    public function __construct(private ShiftCalendar $calendar, private StaffActivity $staff)
    {
    }

    private function hasTable(string $t): bool
    {
        return $this->schema["t:$t"] ??= Schema::hasTable($t);
    }

    private function hasColumn(string $t, string $c): bool
    {
        return $this->schema["c:$t.$c"] ??= $this->hasTable($t) && Schema::hasColumn($t, $c);
    }

    private function can(string $p): bool
    {
        return $this->user->hasPermission($p);
    }

    public function build(User $user): array
    {
        $this->user = $user;
        $this->group = $user->laboratory_group_id ? (int) $user->laboratory_group_id : null;

        $current = $this->calendar->current();
        $period = InsightPeriod::make($current ? 'shift' : 'today', $this->calendar);

        $out = [
            'user'   => [
                'id'    => (int) $user->id,
                'name'  => $user->name,
                'group' => $this->groupInfo($this->group),
            ],
            'now'    => now()->format('Y-m-d H:i:s'),
            'shift'  => ShiftCalendar::present($current),
            'period' => $period->toArray(),
            'my'     => $this->safely(fn () => $this->myWork($period)),
            'cards'  => [],
        ];

        $cards = [
            'intake'        => $this->can('samples.create'),
            'queue'         => $this->group !== null && ($this->can('samples.result') || $this->can('worksheets.update')),
            'my_worksheets' => $this->hasTable('worksheets') && $this->can('worksheets.view') && ($this->can('worksheets.create') || $this->can('worksheets.update')),
            'retests'       => $this->hasColumn('worksheet_samples', 'retest_at') && $this->can('worksheets.update'),
            'approvals'     => $this->hasTable('worksheets') && $this->can('worksheets.approve'),
            'live'          => $this->can('executive.view'),
            'overdue'       => $this->can('executive.view'),
            'calibration'   => $this->can('equipment.view'),
            'storage'       => $this->hasColumn('test_objects', 'storage_expiry') && $this->can('test_objects.view'),
            'water'         => $this->hasTable('water_orders') && ($this->can('water_orders.result') || $this->can('water_orders.create')),
            'balances'      => $this->hasColumn('samples', 'received_quantity') && $this->can('sample_quantity.manage'),
        ];
        foreach ($cards as $key => $show) {
            if ($show) {
                $out['cards'][$key] = $this->safely(fn () => $this->{'card' . str_replace('_', '', ucwords($key, '_'))}($period));
            }
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

    private function groupInfo(?int $id): ?array
    {
        if (! $id) {
            return null;
        }
        $g = LaboratoryGroup::query()->find($id, ['id', 'code', 'name_uz', 'name_ru', 'name_en']);

        return $g ? $g->only(['id', 'code', 'name_uz', 'name_ru', 'name_en']) : null;
    }

    private static function days($at): ?int
    {
        if (! $at) {
            return null;
        }

        return (int) floor(max(0, now()->getTimestamp() - Carbon::parse($at)->getTimestamp()) / 86400);
    }

    private static function ts($at): ?string
    {
        return $at ? Carbon::parse($at)->format('Y-m-d H:i:s') : null;
    }

    private function samples(): Builder
    {
        return DB::table('samples')->whereNull('samples.deleted_at');
    }

    // ================================================================== mening ishim

    private function myWork(InsightPeriod $p): array
    {
        $s = $this->staff->summary($p, ['user_id' => (int) $this->user->id]);
        $row = $s['rows'][0] ?? null;

        return [
            'metrics'  => $row['metrics'] ?? (object) [],
            'first_at' => $row['first_at'] ?? null,
            'last_at'  => $row['last_at'] ?? null,
            'order'    => $s['metrics'],
        ];
    }

    // ================================================================== kartochkalar

    private function cardIntake(InsightPeriod $p): array
    {
        $today = [now()->startOfDay(), now()];
        $q = fn () => $this->samples()->where('samples.registered_at', '>=', $today[0])->where('samples.registered_at', '<=', $today[1]);
        $unassigned = $this->samples()->where('samples.status', 'registered');

        return [
            'today_total' => $q()->count(),
            'today_mine'  => $q()->where('samples.registered_by', $this->user->id)->count(),
            'count'       => (clone $unassigned)->count(),
            'items'       => (clone $unassigned)->orderBy('samples.registered_at')->orderBy('samples.id')->limit(self::LIMIT)
                ->get(['samples.id', 'samples.code', 'samples.sample_number', 'samples.registered_at'])
                ->map(fn ($r) => [
                    'id' => (int) $r->id, 'code' => $r->code, 'number' => $r->sample_number,
                    'at' => self::ts($r->registered_at), 'days' => self::days($r->registered_at),
                ])->all(),
            'recent'      => $this->samples()->where('samples.registered_by', $this->user->id)
                ->orderByDesc('samples.registered_at')->orderByDesc('samples.id')->limit(5)
                ->get(['samples.id', 'samples.code', 'samples.status', 'samples.registered_at'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->code, 'status' => $r->status, 'at' => self::ts($r->registered_at)])->all(),
        ];
    }

    private function cardQueue(InsightPeriod $p): array
    {
        $base = fn () => $this->samples()->where('samples.assigned_group_id', $this->group)
            ->whereIn('samples.status', ['assigned', 'in_progress']);
        $limit = now()->subDays(self::OVERDUE_DAYS);
        $items = $base()->orderBy('samples.registered_at')->orderBy('samples.id')->limit(self::LIMIT)
            ->get(['samples.id', 'samples.code', 'samples.sample_number', 'samples.status', 'samples.registered_at', 'samples.assigned_at']);
        $ids = $items->pluck('id')->map(fn ($v) => (int) $v)->all();

        $pending = [];
        $inSheet = [];
        if ($ids) {
            foreach (DB::table('sample_elements')->whereIn('sample_id', $ids)->where('status', 'pending')
                ->selectRaw('sample_id, COUNT(*) as c')->groupBy('sample_id')->get() as $r) {
                $pending[(int) $r->sample_id] = (int) $r->c;
            }
            if ($this->hasTable('worksheet_samples')) {
                $q = DB::table('worksheet_samples')->join('worksheets', 'worksheets.id', '=', 'worksheet_samples.worksheet_id')
                    ->whereNull('worksheets.deleted_at')->whereIn('worksheet_samples.sample_id', $ids);
                if ($this->hasColumn('worksheets', 'kind')) {
                    $q->where('worksheets.kind', '!=', 'control');
                }
                foreach ($q->get(['worksheet_samples.sample_id', 'worksheets.id', 'worksheets.number']) as $r) {
                    $inSheet[(int) $r->sample_id] = ['id' => (int) $r->id, 'number' => $r->number];
                }
            }
        }

        return [
            'group'       => $this->groupInfo($this->group),
            'count'       => $base()->count(),
            'assigned'    => $base()->where('samples.status', 'assigned')->count(),
            'in_progress' => $base()->where('samples.status', 'in_progress')->count(),
            'overdue'     => $base()->where('samples.registered_at', '<=', $limit)->count(),
            'items'       => $items->map(fn ($r) => [
                'id'        => (int) $r->id,
                'code'      => $r->code,
                'number'    => $r->sample_number,
                'status'    => $r->status,
                'days'      => self::days($r->registered_at),
                'pending'   => $pending[(int) $r->id] ?? 0,
                'worksheet' => $inSheet[(int) $r->id] ?? null,
            ])->all(),
        ];
    }

    /** Guruhim yoki o'zim ochgan varaqlar */
    private function scopeWorksheets(Builder $q): Builder
    {
        $me = (int) $this->user->id;
        $group = $this->group;
        if (! $group && ($this->user->isAdmin() || $this->can('worksheets.approve'))) {
            return $q; // guruhsiz boshliq/admin — butun laboratoriya
        }

        return $q->where(function (Builder $w) use ($me, $group) {
            $w->where('worksheets.created_by', $me);
            if ($group && $this->hasTable('worksheet_samples')) {
                $w->orWhereIn('worksheets.id', fn (Builder $s) => $s->select('worksheet_samples.worksheet_id')->from('worksheet_samples')
                    ->join('samples', 'samples.id', '=', 'worksheet_samples.sample_id')
                    ->where('samples.assigned_group_id', $group));
            }
        });
    }

    private function cardMyWorksheets(InsightPeriod $p): array
    {
        $base = fn () => $this->scopeWorksheets(DB::table('worksheets')->whereNull('worksheets.deleted_at')
            ->whereIn('worksheets.status', ['draft', 'printed', 'in_progress']));
        $cols = ['worksheets.id', 'worksheets.number', 'worksheets.status', 'worksheets.worksheet_date', 'worksheets.updated_at', 'worksheet_templates.code as template'];
        if ($this->hasColumn('worksheets', 'kind')) {
            $cols[] = 'worksheets.kind';
        }
        $items = $base()->leftJoin('worksheet_templates', 'worksheet_templates.id', '=', 'worksheets.template_id')
            ->orderByDesc('worksheets.updated_at')->orderByDesc('worksheets.id')->limit(self::LIMIT)->get($cols);
        $counts = [];
        if ($items->count() && $this->hasTable('worksheet_samples')) {
            foreach (DB::table('worksheet_samples')->whereIn('worksheet_id', $items->pluck('id')->all())
                ->selectRaw('worksheet_id, COUNT(*) as c')->groupBy('worksheet_id')->get() as $r) {
                $counts[(int) $r->worksheet_id] = (int) $r->c;
            }
        }
        $byStatus = [];
        foreach ($base()->selectRaw('worksheets.status as s, COUNT(*) as c')->groupBy('worksheets.status')->get() as $r) {
            $byStatus[$r->s] = (int) $r->c;
        }

        return [
            'count'     => array_sum($byStatus),
            'by_status' => (object) $byStatus,
            'items'     => $items->map(fn ($w) => [
                'id'       => (int) $w->id,
                'number'   => $w->number,
                'status'   => $w->status,
                'kind'     => ($w->kind ?? null) ?: 'main',
                'template' => $w->template,
                'date'     => $w->worksheet_date ? Carbon::parse($w->worksheet_date)->toDateString() : null,
                'samples'  => $counts[(int) $w->id] ?? null,
                'days'     => self::days($w->updated_at),
            ])->all(),
        ];
    }

    private function cardRetests(InsightPeriod $p): array
    {
        $base = fn () => $this->scopeWorksheets(DB::table('worksheet_samples')
            ->join('worksheets', 'worksheets.id', '=', 'worksheet_samples.worksheet_id')
            ->join('samples', 'samples.id', '=', 'worksheet_samples.sample_id')
            ->whereNull('worksheets.deleted_at')->whereNull('samples.deleted_at')
            ->where('worksheet_samples.status', 'retest')
            ->where('worksheets.status', '!=', 'approved'));

        return [
            'count' => $base()->count(),
            'items' => $base()->orderByDesc('worksheet_samples.retest_at')->limit(self::LIMIT)
                ->get(['samples.id as sample_id', 'samples.code', 'worksheets.id', 'worksheets.number', 'worksheet_samples.retest_reason', 'worksheet_samples.retest_at'])
                ->map(fn ($r) => [
                    'sample' => ['id' => (int) $r->sample_id, 'code' => $r->code],
                    'worksheet' => ['id' => (int) $r->id, 'number' => $r->number],
                    'reason' => $r->retest_reason, 'at' => self::ts($r->retest_at), 'days' => self::days($r->retest_at),
                ])->all(),
        ];
    }

    private function cardApprovals(InsightPeriod $p): array
    {
        $base = fn () => DB::table('worksheets')->whereNull('worksheets.deleted_at')->where('worksheets.status', 'completed');
        $limit = now()->subDays(2);
        $cols = ['worksheets.id', 'worksheets.number', 'worksheets.completed_at', 'worksheets.updated_at', 'worksheet_templates.code as template'];
        if ($this->hasColumn('worksheets', 'kind')) {
            $cols[] = 'worksheets.kind';
        }

        return [
            'count' => $base()->count(),
            'old'   => $base()->where(fn (Builder $w) => $w->where('worksheets.completed_at', '<=', $limit)
                ->orWhere(fn (Builder $x) => $x->whereNull('worksheets.completed_at')->where('worksheets.updated_at', '<=', $limit)))->count(),
            'items' => $base()->leftJoin('worksheet_templates', 'worksheet_templates.id', '=', 'worksheets.template_id')
                ->orderBy('worksheets.completed_at')->orderBy('worksheets.id')->limit(self::LIMIT)->get($cols)
                ->map(fn ($w) => [
                    'id' => (int) $w->id, 'number' => $w->number, 'kind' => ($w->kind ?? null) ?: 'main', 'template' => $w->template,
                    'at' => self::ts($w->completed_at ?: $w->updated_at), 'days' => self::days($w->completed_at ?: $w->updated_at),
                ])->all(),
        ];
    }

    private function cardLive(InsightPeriod $p): array
    {
        $a = $p->from;
        $b = $p->end;
        $analyses = DB::table('sample_elements')->where('sample_elements.status', 'done')
            ->where('sample_elements.result_entered_at', '>=', $a)->where('sample_elements.result_entered_at', '<=', $b)
            ->whereIn('sample_elements.sample_id', fn (Builder $s) => $s->select('samples.id')->from('samples')->whereNull('samples.deleted_at'));

        return [
            'received'   => $this->samples()->where('samples.registered_at', '>=', $a)->where('samples.registered_at', '<=', $b)->count(),
            'completed'  => $this->samples()->where('samples.completed_at', '>=', $a)->where('samples.completed_at', '<=', $b)->count(),
            'analyses'   => $analyses->count(),
            'worksheets' => $this->hasTable('worksheets')
                ? DB::table('worksheets')->whereNull('deleted_at')->where('created_at', '>=', $a)->where('created_at', '<=', $b)->count() : null,
            'in_work'    => $this->samples()->whereIn('samples.status', ['registered', 'assigned', 'in_progress'])->count(),
        ];
    }

    private function cardOverdue(InsightPeriod $p): array
    {
        $base = fn () => $this->samples()->whereIn('samples.status', ['registered', 'assigned', 'in_progress'])
            ->where('samples.registered_at', '<=', now()->subDays(self::OVERDUE_DAYS));
        $items = $base()->orderBy('samples.registered_at')->orderBy('samples.id')->limit(self::LIMIT)
            ->get(['samples.id', 'samples.code', 'samples.status', 'samples.registered_at', 'samples.assigned_group_id']);
        $groups = LaboratoryGroup::query()->whereIn('id', $items->pluck('assigned_group_id')->filter()->unique()->values()->all())
            ->get(['id', 'code', 'name_uz', 'name_ru', 'name_en'])->keyBy('id');

        return [
            'days'  => self::OVERDUE_DAYS,
            'count' => $base()->count(),
            'items' => $items->map(fn ($r) => [
                'id' => (int) $r->id, 'code' => $r->code, 'status' => $r->status, 'days' => self::days($r->registered_at),
                'group' => ($g = $groups->get($r->assigned_group_id)) ? $g->only(['id', 'code', 'name_uz', 'name_ru', 'name_en']) : null,
            ])->all(),
        ];
    }

    private function cardCalibration(InsightPeriod $p): array
    {
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();
        $base = fn () => Equipment::query()->whereNotNull('next_calibration_at')->whereDate('next_calibration_at', '<=', $soon);

        return [
            'count'   => $base()->count(),
            'overdue' => Equipment::query()->whereNotNull('next_calibration_at')->whereDate('next_calibration_at', '<', $today)->count(),
            'blocked' => Equipment::query()->where('is_blocked', true)->count(),
            'items'   => $base()->orderBy('next_calibration_at')->limit(self::LIMIT)
                ->get(['id', 'name', 'serial_number', 'next_calibration_at', 'is_blocked'])
                ->map(fn ($e) => [
                    'id' => (int) $e->id, 'name' => $e->name, 'serial' => $e->serial_number, 'blocked' => (bool) $e->is_blocked,
                    'date' => Carbon::parse($e->next_calibration_at)->toDateString(),
                    'in_days' => (int) round((Carbon::parse($e->next_calibration_at)->startOfDay()->getTimestamp() - now()->startOfDay()->getTimestamp()) / 86400),
                ])->all(),
        ];
    }

    private function cardStorage(InsightPeriod $p): array
    {
        $base = fn () => TestObject::query()->whereNotNull('storage_expiry')
            ->whereDate('storage_expiry', '<=', now()->addDays(14)->toDateString())
            ->where('status', '!=', 'disposed');

        return [
            'count' => $base()->count(),
            'items' => $base()->orderBy('storage_expiry')->limit(self::LIMIT)->get(['id', 'identifier', 'name', 'storage_expiry', 'status'])
                ->map(fn ($o) => [
                    'id' => (int) $o->id, 'identifier' => $o->identifier, 'name' => $o->name, 'status' => $o->status,
                    'date' => Carbon::parse($o->storage_expiry)->toDateString(),
                    'in_days' => (int) round((Carbon::parse($o->storage_expiry)->startOfDay()->getTimestamp() - now()->startOfDay()->getTimestamp()) / 86400),
                ])->all(),
        ];
    }

    private function cardWater(InsightPeriod $p): array
    {
        $base = fn () => WaterOrder::query()->whereIn('status', ['registered', 'in_progress']);

        return [
            'count' => $base()->count(),
            'items' => $base()->with('monitoringPoint:id,name')->orderBy('registered_at')->orderBy('id')->limit(self::LIMIT)
                ->get(['id', 'order_number', 'status', 'registered_at', 'monitoring_point_id'])
                ->map(fn ($o) => [
                    'id' => (int) $o->id, 'number' => $o->order_number, 'status' => $o->status,
                    'point' => $o->monitoringPoint?->name, 'days' => self::days($o->registered_at),
                ])->all(),
        ];
    }

    private function cardBalances(InsightPeriod $p): array
    {
        $ledgerClass = 'App\\Services\\Samples\\SampleLedger';
        if (! class_exists($ledgerClass)) {
            return ['count' => 0, 'items' => []];
        }
        $samples = Sample::query()->whereIn('status', ['registered', 'assigned', 'in_progress'])->whereNotNull('received_quantity')
            ->orderByDesc('registered_at')->orderByDesc('id')->limit(500)->get();
        $items = [];
        foreach ($samples->chunk(250) as $chunk) {
            foreach (app($ledgerClass)->summaries($chunk) as $id => $sum) {
                if (in_array($sum['state'] ?? '', ['low', 'empty', 'negative'], true)) {
                    $items[] = ['id' => (int) $id, 'code' => $chunk->firstWhere('id', $id)?->code, 'state' => $sum['state'], 'available' => $sum['available'], 'unit' => $sum['unit']];
                }
            }
        }
        $rank = ['negative' => 0, 'empty' => 1, 'low' => 2];
        usort($items, fn ($a, $b) => [$rank[$a['state']], $a['available'] ?? 0] <=> [$rank[$b['state']], $b['available'] ?? 0]);

        return ['count' => count($items), 'items' => array_slice($items, 0, self::LIMIT)];
    }
}
