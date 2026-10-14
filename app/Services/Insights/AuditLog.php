<?php

namespace App\Services\Insights;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * [P19] Audit jurnali: filtrlar, davr bo'yicha sonlar, yozuvning hozirgi holati, Excel.
 *
 * activity_logs jadvaliga hech narsa yozmaydi (faqat o'qiydi). Sana filtri — oraliq bilan
 * (created_at >= kun 00:00 va < keyingi kun 00:00), shunda created_at indeksi ishlaydi.
 *
 * Filtrlar: from, to (Y-m-d), user_id, model_type, model_id, action, q
 *   action: created | updated | deleted | restored | login | logout | interlab (interlab.* hammasi)
 *   q: tavsif yoki IP manzil ichida; son bo'lsa — yozuv raqami (model_id) ham
 */
class AuditLog
{
    public const BUCKETS = ['created', 'updated', 'deleted', 'restored', 'login', 'logout', 'interlab'];

    public const EXPORT_MAX = 20000;

    /** Bog'liq yozuvlar: hujjat/natija/skan — ota yozuv sahifasi ochiladi */
    public const PARENTS = [
        'SampleElement'          => ['sample_id', 'Sample'],
        'SampleQuantityMovement' => ['sample_id', 'Sample'],
        'EquipmentDocument'      => ['equipment_id', 'Equipment'],
        'EquipmentCalibration'   => ['equipment_id', 'Equipment'],
        'EquipmentMaintenance'   => ['equipment_id', 'Equipment'],
        'EquipmentUsage'         => ['equipment_id', 'Equipment'],
        'TestObjectDocument'     => ['test_object_id', 'TestObject'],
        'WorksheetScan'          => ['worksheet_id', 'Worksheet'],
        'WorksheetSample'        => ['worksheet_id', 'Worksheet'],
        'InterlabResult'         => ['referral_id', 'InterlabReferral'],
    ];

    public static function bucket(?string $action): string
    {
        $action = (string) $action;

        return str_starts_with($action, 'interlab.') ? 'interlab' : $action;
    }

    /**
     * Filtrlangan so'rov.
     * @param  string|null  $except  shu o'lchovni hisobga olmaslik (yuzlar uchun): action | model | user
     */
    public function base(array $f, ?string $except = null): Builder
    {
        $q = DB::table('activity_logs as l');

        if (! empty($f['from'])) {
            $q->where('l.created_at', '>=', Carbon::parse($f['from'])->startOfDay()->format('Y-m-d H:i:s'));
        }
        if (! empty($f['to'])) {
            $q->where('l.created_at', '<', Carbon::parse($f['to'])->addDay()->startOfDay()->format('Y-m-d H:i:s'));
        }
        if ($except !== 'user' && ! empty($f['user_id'])) {
            $f['user_id'] === 'system' ? $q->whereNull('l.user_id') : $q->where('l.user_id', (int) $f['user_id']);
        }
        if ($except !== 'model' && ! empty($f['model_type'])) {
            $q->where('l.model_type', $f['model_type']);
            if (! empty($f['model_id'])) {
                $q->where('l.model_id', (int) $f['model_id']);
            }
        }
        if ($except !== 'action' && ! empty($f['action'])) {
            $f['action'] === 'interlab'
                ? $q->where('l.action', 'like', 'interlab.%')
                : $q->where('l.action', $f['action']);
        }
        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%' . self::escapeLike($term) . '%';
            $q->where(function ($w) use ($like, $term) {
                $w->whereRaw("l.description LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("l.ip_address LIKE ? ESCAPE '!'", [$like]);
                if (ctype_digit($term) && strlen($term) < 18) {
                    $w->orWhere('l.model_id', (int) $term);
                }
            });
        }

        return $q;
    }

    public static function escapeLike(string $s): string
    {
        return str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], mb_substr($s, 0, 100));
    }

    /** Sahifa: yangisi birinchi */
    public function page(array $f, int $page, int $perPage): array
    {
        $q = $this->base($f)
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->select(['l.id', 'l.created_at', 'l.action', 'l.model_type', 'l.model_id', 'l.description', 'l.properties', 'l.ip_address', 'l.user_id', 'u.name as user_name'])
            ->orderByDesc('l.created_at')->orderByDesc('l.id');

        $p = $q->paginate($perPage, ['*'], 'page', $page);
        $rows = collect($p->items());
        $subjects = $this->subjects($rows);

        return [
            'data' => $rows->map(fn ($r) => $this->row($r, $subjects))->values()->all(),
            'meta' => [
                'current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(),
                'total' => $p->total(), 'from' => $p->firstItem(), 'to' => $p->lastItem(),
            ],
        ];
    }

    private function row(object $r, array $subjects): array
    {
        $props = $r->properties;
        if (is_string($props)) {
            $decoded = json_decode($props, true);
            $props = json_last_error() === JSON_ERROR_NONE ? $decoded : $props;
        }

        return [
            'id'          => (int) $r->id,
            'at'          => $r->created_at ? Carbon::parse($r->created_at)->format('Y-m-d H:i:s') : null,
            'action'      => $r->action,
            'bucket'      => self::bucket($r->action),
            'model_type'  => $r->model_type,
            'model_id'    => $r->model_id !== null ? (int) $r->model_id : null,
            'description' => $r->description,
            'properties'  => $props ?: null,
            'ip'          => $r->ip_address,
            'user'        => $r->user_id ? ['id' => (int) $r->user_id, 'name' => $r->user_name] : null,
            'subject'     => $r->model_type && $r->model_id ? ($subjects["{$r->model_type}#{$r->model_id}"] ?? null) : null,
        ];
    }

    /** Davr bo'yicha sonlar: jami, amallar, bo'limlar, xodimlar (har biri o'z filtrisiz) */
    public function summary(array $f): array
    {
        $buckets = array_fill_keys(self::BUCKETS, 0);
        foreach ($this->base($f, 'action')->select('l.action', DB::raw('COUNT(*) as c'))->groupBy('l.action')->get() as $r) {
            $b = self::bucket($r->action);
            $buckets[$b] = ($buckets[$b] ?? 0) + (int) $r->c;
        }

        $models = $this->base($f, 'model')->whereNotNull('l.model_type')
            ->select('l.model_type', DB::raw('COUNT(*) as c'))->groupBy('l.model_type')->get()
            ->map(fn ($r) => ['type' => $r->model_type, 'count' => (int) $r->c])
            ->sortByDesc('count')->values()->all();

        $users = $this->base($f, 'user')->select('l.user_id', DB::raw('COUNT(*) as c'))->groupBy('l.user_id')->get();
        $names = DB::table('users')->whereIn('id', $users->pluck('user_id')->filter()->map(fn ($v) => (int) $v)->all())->pluck('name', 'id');
        $users = $users->map(fn ($r) => [
            'id'    => $r->user_id ? (int) $r->user_id : 'system',
            'name'  => $r->user_id ? ($names[(int) $r->user_id] ?? ('#' . $r->user_id)) : null,
            'count' => (int) $r->c,
        ])->sortByDesc('count')->values()->all();

        return [
            'total'   => $this->base($f)->count(),
            'actions' => $buckets,
            'models'  => $models,
            'users'   => $users,
        ];
    }

    /**
     * Yozuvlarning hozirgi holati: bor | o'chirilgan (savatchada) | yo'q (butunlay o'chirilgan).
     * Hujjat, kalibrovka, skan va hokazo uchun — ota yozuv (uskuna, varaq ...) havolasi.
     * @return array<string, array{state:string, link:?array, trash:?array}>  kalit: "Type#id"
     */
    public function subjects(Collection $rows): array
    {
        $found = [];   // "Type#id" => [state, parent]
        $parentIds = [];
        foreach ($rows->filter(fn ($r) => $r->model_type && $r->model_id)->groupBy('model_type') as $type => $items) {
            $ids = $items->pluck('model_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            $parent = self::PARENTS[$type] ?? null;
            foreach ($this->lookup($type, $ids, $parent[0] ?? null) as $id => $info) {
                $found["$type#$id"] = $info;
                if ($parent && $info['parent_id']) {
                    $parentIds[$parent[1]][] = $info['parent_id'];
                }
            }
        }
        $parents = [];
        foreach ($parentIds as $type => $ids) {
            foreach ($this->lookup($type, array_values(array_unique($ids))) as $id => $info) {
                $parents["$type#$id"] = $info['state'];
            }
        }

        $out = [];
        foreach ($found as $key => $info) {
            [$type, $id] = explode('#', $key);
            $id = (int) $id;
            $link = null;
            $trash = null;
            if ($info['state'] === 'exists') {
                $link = ['type' => $type, 'id' => $id];
            }
            if ($info['state'] === 'deleted' && ($bin = RecycleBin::typeForModel($type))) {
                $trash = ['type' => $bin, 'id' => $id];
            }
            if (isset(self::PARENTS[$type]) && $info['parent_id']) {
                $pType = self::PARENTS[$type][1];
                $pState = $parents["$pType#{$info['parent_id']}"] ?? 'gone';
                $link = $pState === 'exists' ? ['type' => $pType, 'id' => $info['parent_id'], 'parent' => true] : null;
                if ($pState === 'deleted' && ($bin = RecycleBin::typeForModel($pType))) {
                    $trash = ['type' => $bin, 'id' => $info['parent_id'], 'parent' => true];
                }
            }
            $out[$key] = ['state' => $info['state'], 'link' => $link, 'trash' => $trash];
        }

        return $out;
    }

    /** @return array<int, array{state:string, parent_id:?int}> */
    private function lookup(string $type, array $ids, ?string $parentColumn = null): array
    {
        $class = self::modelClass($type);
        if (! $class || ! $ids) {
            return [];
        }
        /** @var Model $model */
        $model = new $class();
        $soft = in_array(SoftDeletes::class, class_uses_recursive($class), true);
        $cols = [$model->getKeyName()];
        if ($soft) {
            $cols[] = $model->getDeletedAtColumn();
        }
        if ($parentColumn) {
            $cols[] = $parentColumn;
        }

        $out = [];
        try {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $rows = DB::connection($model->getConnectionName())->table($model->getTable())
                    ->whereIn($model->getKeyName(), $chunk)->select($cols)->get();
                foreach ($rows as $r) {
                    $out[(int) $r->{$model->getKeyName()}] = [
                        'state'     => $soft && $r->{$model->getDeletedAtColumn()} ? 'deleted' : 'exists',
                        'parent_id' => $parentColumn && $r->{$parentColumn} ? (int) $r->{$parentColumn} : null,
                    ];
                }
            }
        } catch (Throwable) {
            return []; // jadval yo'q (modul o'rnatilmagan) — holat noma'lum
        }
        foreach ($ids as $id) {
            $out[$id] ??= ['state' => 'gone', 'parent_id' => null];
        }

        return $out;
    }

    /** "Sample" → App\Models\Sample (faqat App\Models ichidagi Eloquent modellar) */
    public static function modelClass(?string $type): ?string
    {
        if (! $type || ! preg_match('/^[A-Z][A-Za-z0-9]{0,63}$/', $type)) {
            return null;
        }
        $class = 'App\\Models\\' . $type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    /** Excel uchun qatorlar (yangisi birinchi, eng ko'pi EXPORT_MAX) */
    public function exportRows(array $f): array
    {
        $rows = $this->base($f)
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->select(['l.id', 'l.created_at', 'l.action', 'l.model_type', 'l.model_id', 'l.description', 'l.properties', 'l.ip_address', 'u.name as user_name'])
            ->orderByDesc('l.created_at')->orderByDesc('l.id')
            ->limit(self::EXPORT_MAX)->get();

        return $rows->map(function ($r) {
            $props = is_string($r->properties) ? $r->properties : json_encode($r->properties, JSON_UNESCAPED_UNICODE);

            return [
                'at'          => $r->created_at ? Carbon::parse($r->created_at) : null,
                'action'      => $r->action,
                'model_type'  => $r->model_type,
                'model_id'    => $r->model_id !== null ? (int) $r->model_id : null,
                'description' => $r->description,
                'user'        => $r->user_name,
                'ip'          => $r->ip_address,
                'properties'  => $props && $props !== 'null' ? mb_substr($props, 0, 2000) : null,
            ];
        })->all();
    }
}
