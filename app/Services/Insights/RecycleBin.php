<?php

namespace App\Services\Insights;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Worksheets\WorksheetService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * [P19] Savatcha: o'chirilgan (soft delete) yozuvlar — ro'yxat, kim o'chirgani, tiklash.
 *
 * Turlar ro'yxati shu yerda. Model fayli yoki jadvali yo'q tur (modul o'rnatilmagan) — ko'rinmaydi.
 * Butunlay o'chirish YO'Q (ISO/IEC 17025 — yozuvlar saqlanadi).
 *
 * Har qator: title, meta [{k, v}] (interfeys tarjima qiladi), status, deleted_at, deleted_by (audit jurnalidan).
 * Tiklashdan oldin tekshiruv: blockers — tiklab bo'lmaydi (sababi bilan), warnings — tiklanadi, lekin e'tibor bering.
 */
class RecycleBin
{
    public const MODELS = [
        'samples'              => 'Sample',
        'worksheets'           => 'Worksheet',
        'test_objects'         => 'TestObject',
        'equipment'            => 'Equipment',
        'water_orders'         => 'WaterOrder',
        'users'                => 'User',
        'worksheet_templates'  => 'WorksheetTemplate',
        'customer_departments' => 'CustomerDepartment',
    ];

    /** Qidiruv ustunlari */
    private const SEARCH = [
        'samples'              => ['code', 'sample_number', 'source_document', 'customer_code'],
        'worksheets'           => ['number'],
        'test_objects'         => ['identifier', 'name', 'code', 'batch'],
        'equipment'            => ['name', 'serial_number', 'inventory_number', 'model'],
        'water_orders'         => ['order_number', 'source_document', 'division'],
        'users'                => ['name', 'username', 'email'],
        'worksheet_templates'  => ['code', 'name_uz', 'name_ru'],
        'customer_departments' => ['code', 'name'],
    ];

    /** Tiklashda takrorlanmasligi kerak bo'lgan qiymatlar (faol yozuvlar orasida) */
    private const UNIQUE = [
        'samples'              => [['code']],
        'test_objects'         => [['identifier']],
        'equipment'            => [['serial_number']],
        'water_orders'         => [['order_number']],
        'users'                => [['username'], ['email']],
        'worksheet_templates'  => [['code', 'version']],
        'customer_departments' => [['code']],
    ];

    private static array $available = [];

    public static function typeForModel(?string $model): ?string
    {
        $type = array_search($model, self::MODELS, true);

        return $type !== false && self::available($type) ? $type : null;
    }

    public static function available(string $type): bool
    {
        if (array_key_exists($type, self::$available)) {
            return self::$available[$type];
        }
        $class = AuditLog::modelClass(self::MODELS[$type] ?? null);
        $ok = false;
        if ($class && in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            try {
                $ok = Schema::hasColumn((new $class())->getTable(), 'deleted_at');
            } catch (Throwable) {
                $ok = false;
            }
        }

        return self::$available[$type] = $ok;
    }

    /** @return string[] */
    public function types(): array
    {
        return array_values(array_filter(array_keys(self::MODELS), fn ($t) => self::available($t)));
    }

    private function model(string $type): Model
    {
        abort_unless(self::available($type), 404, "Savatchada bunday bo'lim yo'q");
        $class = AuditLog::modelClass(self::MODELS[$type]);

        return new $class();
    }

    private function table(string $type): Builder
    {
        $m = $this->model($type);

        return DB::connection($m->getConnectionName())->table($m->getTable() . ' as t')->whereNotNull('t.deleted_at');
    }

    /** Har tur bo'yicha soni */
    public function counts(): array
    {
        $out = [];
        foreach ($this->types() as $type) {
            $out[] = ['type' => $type, 'count' => $this->table($type)->count()];
        }

        return $out;
    }

    /**
     * @param  array{q?:string, from?:string, to?:string, deleted_by?:int|string}  $f
     */
    public function page(string $type, array $f, int $page, int $perPage, string $locale): array
    {
        $q = $this->filtered($type, $f)->select('t.*')->orderByDesc('t.deleted_at')->orderByDesc('t.id');
        $p = $q->paginate($perPage, ['*'], 'page', $page);
        $rows = collect($p->items());

        return [
            'data'     => $this->present($type, $rows, $locale),
            'meta'     => [
                'current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(),
                'total' => $p->total(), 'from' => $p->firstItem(), 'to' => $p->lastItem(),
            ],
            'deleters' => $this->deleters($type),
        ];
    }

    private function filtered(string $type, array $f): Builder
    {
        $q = $this->table($type);
        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%' . AuditLog::escapeLike($term) . '%';
            $q->where(function ($w) use ($type, $like) {
                foreach (self::SEARCH[$type] as $col) {
                    if ($this->hasColumn($type, $col)) {
                        $w->orWhereRaw("t.{$col} LIKE ? ESCAPE '!'", [$like]);
                    }
                }
            });
        }
        if (! empty($f['from'])) {
            $q->where('t.deleted_at', '>=', Carbon::parse($f['from'])->startOfDay()->format('Y-m-d H:i:s'));
        }
        if (! empty($f['to'])) {
            $q->where('t.deleted_at', '<', Carbon::parse($f['to'])->addDay()->startOfDay()->format('Y-m-d H:i:s'));
        }
        if (! empty($f['deleted_by'])) {
            $model = self::MODELS[$type];
            $by = $f['deleted_by'];
            $q->whereIn('t.id', function ($sub) use ($model, $by) {
                $sub->select('model_id')->from('activity_logs')
                    ->where('action', 'deleted')->where('model_type', $model);
                $by === 'unknown' ? $sub->whereNull('user_id') : $sub->where('user_id', (int) $by);
            });
        }

        return $q;
    }

    private static function hasTable(string $table): bool
    {
        static $cache = [];

        return $cache[$table] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $type, string $col): bool
    {
        static $cache = [];
        $key = "$type.$col";

        return $cache[$key] ??= Schema::hasColumn($this->model($type)->getTable(), $col);
    }

    /** Shu turdagi yozuvlarni o'chirgan xodimlar (filtr uchun) */
    private function deleters(string $type): array
    {
        $m = $this->model($type);
        $rows = DB::table('activity_logs as l')
            ->where('l.action', 'deleted')->where('l.model_type', self::MODELS[$type])
            ->whereIn('l.model_id', fn ($sub) => $sub->select('id')->from($m->getTable())->whereNotNull('deleted_at'))
            ->select('l.user_id', DB::raw('COUNT(DISTINCT l.model_id) as c'))->groupBy('l.user_id')->get();
        $names = DB::table('users')->whereIn('id', $rows->pluck('user_id')->filter()->map(fn ($v) => (int) $v)->all())->pluck('name', 'id');

        return $rows->filter(fn ($r) => $r->user_id)
            ->map(fn ($r) => ['id' => (int) $r->user_id, 'name' => $names[(int) $r->user_id] ?? '#' . $r->user_id, 'count' => (int) $r->c])
            ->sortByDesc('count')->values()->all();
    }

    /** Kim va qachon o'chirgan (audit jurnalidagi oxirgi «deleted» yozuvi) */
    private function deletions(string $type, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = DB::table('activity_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
                ->where('l.action', 'deleted')->where('l.model_type', self::MODELS[$type])->whereIn('l.model_id', $chunk)
                ->orderByDesc('l.id')
                ->select(['l.model_id', 'l.user_id', 'u.name as user_name', 'l.description', 'l.created_at'])->get();
            foreach ($rows as $r) {
                $out[(int) $r->model_id] ??= [
                    'user' => $r->user_id ? ['id' => (int) $r->user_id, 'name' => $r->user_name] : null,
                    'note' => $r->description,
                ];
            }
        }

        return $out;
    }

    /** Qatorlarni interfeys uchun tayyorlash */
    public function present(string $type, $rows, string $locale): array
    {
        $rows = collect($rows);
        $ids = $rows->pluck('id')->map(fn ($v) => (int) $v)->all();
        $del = $this->deletions($type, $ids);
        $ctx = $this->context($type, $rows, $locale);
        $now = now();

        return $rows->map(function ($r) use ($type, $del, $ctx, $now) {
            $id = (int) $r->id;
            $at = $r->deleted_at ? Carbon::parse($r->deleted_at) : null;

            return [
                'id'         => $id,
                'title'      => $this->title($type, $r),
                'meta'       => array_values(array_filter($this->meta($type, $r, $ctx), fn ($m) => $m['v'] !== null && $m['v'] !== '')),
                'status'     => $this->status($type, $r),
                'deleted_at' => $at?->format('Y-m-d H:i:s'),
                'days'       => $at ? (int) floor(($now->getTimestamp() - $at->getTimestamp()) / 86400) : null,
                'deleted_by' => $del[$id]['user'] ?? null,
                'note'       => $del[$id]['note'] ?? null,
            ];
        })->values()->all();
    }

    private function title(string $type, object $r): string
    {
        return (string) match ($type) {
            'samples'              => $r->code,
            'worksheets'           => '№ ' . $r->number,
            'test_objects'         => $r->identifier,
            'equipment'            => $r->name,
            'water_orders'         => $r->order_number,
            'users'                => $r->name,
            'worksheet_templates'  => $r->code . (isset($r->version) && $r->version !== null ? ' v' . $r->version : ''),
            'customer_departments' => $r->code,
            default                => '#' . $r->id,
        };
    }

    private function status(string $type, object $r): ?string
    {
        return match ($type) {
            'users', 'worksheet_templates', 'customer_departments' => ! empty($r->is_active) ? 'active' : 'inactive',
            default => $r->status ?? null,
        };
    }

    /** Bog'liq nomlar — bitta sahifa uchun bir martada */
    private function context(string $type, $rows, string $locale): array
    {
        $ids = fn (string $col) => $rows->pluck($col)->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $name = fn ($r) => $r->{'name_' . $locale} ?? $r->name_uz ?? $r->name_ru ?? null;
        $ctx = [];
        try {
            switch ($type) {
                case 'samples':
                    $ctx['groups'] = DB::table('laboratory_groups')->whereIn('id', array_merge($ids('assigned_group_id'), $ids('customer_group_id')))
                        ->get()->mapWithKeys(fn ($g) => [(int) $g->id => $name($g)])->all();
                    if (self::hasTable('customer_departments') && $this->hasColumn($type, 'customer_department_id')) {
                        $ctx['departments'] = DB::table('customer_departments')->whereIn('id', $ids('customer_department_id'))->pluck('name', 'id')->all();
                    }
                    break;
                case 'worksheets':
                    $ctx['templates'] = DB::table('worksheet_templates')->whereIn('id', $ids('template_id'))
                        ->get()->mapWithKeys(fn ($t) => [(int) $t->id => $t->code])->all();
                    $ctx['members'] = DB::table('worksheet_samples')->whereIn('worksheet_id', $rows->pluck('id')->all())
                        ->select('worksheet_id', DB::raw('COUNT(*) as c'))->groupBy('worksheet_id')->pluck('c', 'worksheet_id')->all();
                    break;
                case 'water_orders':
                    $ctx['points'] = DB::table('monitoring_points')->whereIn('id', $ids('monitoring_point_id'))->pluck('name', 'id')->all();
                    break;
                case 'users':
                    $ctx['roles'] = DB::table('roles')->whereIn('id', $ids('role_id'))
                        ->get()->mapWithKeys(fn ($x) => [(int) $x->id => $x->{'display_name_' . $locale} ?? $x->display_name_ru ?? $x->name])->all();
                    break;
                case 'worksheet_templates':
                    $ctx['locale'] = $locale;
                    break;
            }
        } catch (Throwable) {
            // bog'liq jadval yo'q — nom ko'rsatilmaydi
        }

        return $ctx;
    }

    private function meta(string $type, object $r, array $ctx): array
    {
        $date = fn ($v) => $v ? Carbon::parse($v)->format('d.m.Y') : null;

        return match ($type) {
            'samples' => [
                ['k' => 'number', 'v' => $r->sample_number ?? null],
                ['k' => 'group', 'v' => $ctx['groups'][(int) ($r->assigned_group_id ?? 0)] ?? null],
                ['k' => 'customer', 'v' => ($ctx['departments'][(int) ($r->customer_department_id ?? 0)] ?? null)
                    ?? ($ctx['groups'][(int) ($r->customer_group_id ?? 0)] ?? null)],
                ['k' => 'registered', 'v' => $date($r->registered_at ?? null)],
            ],
            'worksheets' => [
                ['k' => 'template', 'v' => $ctx['templates'][(int) $r->template_id] ?? null],
                ['k' => 'samples', 'v' => (int) ($ctx['members'][$r->id] ?? 0)],
                ['k' => 'kind', 'v' => ($r->kind ?? 'main') === 'control' ? 'control' : null],
                ['k' => 'date', 'v' => $date($r->worksheet_date ?? null)],
            ],
            'test_objects' => [
                ['k' => 'name', 'v' => $r->name ?? null],
                ['k' => 'object_type', 'v' => $r->type ?? null],
                ['k' => 'batch', 'v' => $r->batch ?? null],
            ],
            'equipment' => [
                ['k' => 'serial', 'v' => $r->serial_number ?? null],
                ['k' => 'model', 'v' => $r->model ?? null],
                ['k' => 'inventory', 'v' => $r->inventory_number ?? null],
            ],
            'water_orders' => [
                ['k' => 'point', 'v' => $ctx['points'][(int) ($r->monitoring_point_id ?? 0)] ?? null],
                ['k' => 'division', 'v' => $r->division ?? null],
                ['k' => 'registered', 'v' => $date($r->registered_at ?? null)],
            ],
            'users' => [
                ['k' => 'username', 'v' => $r->username ?? null],
                ['k' => 'role', 'v' => $ctx['roles'][(int) ($r->role_id ?? 0)] ?? null],
                ['k' => 'position', 'v' => $r->position ?? null],
            ],
            'worksheet_templates' => [
                ['k' => 'name', 'v' => $r->{'name_' . ($ctx['locale'] ?? 'uz')} ?? $r->name_uz ?? $r->name_ru ?? null],
            ],
            'customer_departments' => [
                ['k' => 'name', 'v' => $r->name ?? null],
            ],
            default => [],
        };
    }

    // ------------------------------------------------------------------ tiklash

    private function find(string $type, int $id): Model
    {
        $m = $this->model($type);

        return $m->newQueryWithoutScopes()->whereKey($id)->whereNotNull($m->getDeletedAtColumn())->firstOr(
            fn () => abort(404, "Yozuv savatchada topilmadi (avval tiklangan bo'lishi mumkin)")
        );
    }

    /** Tiklashdan oldin: yozuv, ogohlantirishlar, to'siqlar, tarixdagi yozuvlar soni */
    public function preview(string $type, int $id, string $locale): array
    {
        $item = $this->find($type, $id);
        $row = $this->table($type)->where('t.id', $id)->first();

        return [
            'item'    => $this->present($type, [$row], $locale)[0],
            'type'    => $type,
            'model'   => self::MODELS[$type],
            'history' => DB::table('activity_logs')->where('model_type', self::MODELS[$type])->where('model_id', $id)->count(),
        ] + $this->check($type, $item);
    }

    /** @return array{blockers: string[], warnings: string[]} */
    public function check(string $type, Model $item): array
    {
        $blockers = [];
        $warnings = [];
        $table = $item->getTable();

        foreach (self::UNIQUE[$type] ?? [] as $cols) {
            $values = [];
            foreach ($cols as $c) {
                $values[$c] = $item->getAttribute($c);
            }
            if (in_array(null, $values, true) || in_array('', $values, true)) {
                continue;
            }
            $q = DB::table($table)->whereNull('deleted_at')->where('id', '!=', $item->getKey());
            foreach ($values as $c => $v) {
                $q->where($c, $v);
            }
            if ($q->exists()) {
                $blockers[] = "«" . implode(' ', $values) . "» — shunday faol yozuv allaqachon bor. Avval uni o'zgartiring.";
            }
        }

        switch ($type) {
            case 'worksheets':
                [$b, $w] = $this->checkWorksheet($item);
                $blockers = array_merge($blockers, $b);
                $warnings = array_merge($warnings, $w);
                break;
            case 'samples':
                if (! empty($item->customer_department_id) && self::hasTable('customer_departments')
                    && DB::table('customer_departments')->where('id', $item->customer_department_id)->whereNotNull('deleted_at')->exists()) {
                    $warnings[] = "Namunaning buyurtmachi bo'limi ham o'chirilgan — kerak bo'lsa uni ham tiklang.";
                }
                break;
            case 'users':
                if (! empty($item->role_id) && ! DB::table('roles')->where('id', $item->role_id)->exists()) {
                    $warnings[] = "Foydalanuvchining roli o'chirilgan — tiklagandan so'ng rol bering.";
                }
                if (! empty($item->is_active)) {
                    $warnings[] = 'Foydalanuvchi faol holatda tiklanadi va tizimga kira oladi.';
                }
                break;
            case 'customer_departments':
                $warnings[] = "O'chirilganda bo'limga biriktirilgan portal foydalanuvchilari ajratilgan — kerak bo'lsa qayta biriktiring.";
                break;
            case 'worksheet_templates':
                $other = DB::table($table)->whereNull('deleted_at')->where('code', $item->code)
                    ->where('id', '!=', $item->getKey())->where('is_active', true)->value('version');
                if ($other !== null) {
                    $warnings[] = "Shu kodli faol shablon bor (v{$other}) — yangi varaqlar uchun qaysi biri ishlatilishini tekshiring.";
                }
                break;
        }

        return ['blockers' => $blockers, 'warnings' => $warnings];
    }

    /** Varaqdagi namunalar boshqa ochiq varaqqa olinganmi (bir namuna bir vaqtda ikki varaqda bo'lmaydi) */
    private function checkWorksheet(Model $ws): array
    {
        $blockers = [];
        $warnings = [];
        $members = DB::table('worksheet_samples as m')->join('samples as s', 's.id', '=', 'm.sample_id')
            ->where('m.worksheet_id', $ws->getKey())->where('m.status', 'active')
            ->select(['m.sample_id', 's.code', 's.deleted_at'])->get();

        $gone = $members->filter(fn ($m) => $m->deleted_at)->pluck('code')->all();
        if ($gone) {
            $warnings[] = 'Varaqdagi namuna(lar) o\'chirilgan: ' . implode(', ', $gone) . '.';
        }

        $template = DB::table('worksheet_templates')->where('id', $ws->template_id)->first();
        if ($template && $template->deleted_at) {
            $warnings[] = "Varaq shabloni ({$template->code}) o'chirilgan — varaq o'z nusxasi bilan ochiladi.";
        }

        $ids = $members->pluck('sample_id')->map(fn ($v) => (int) $v)->all();
        if ($ids && class_exists(WorksheetService::class)) {
            try {
                $templateModel = \App\Models\WorksheetTemplate::withTrashed()->find($ws->template_id);
                $related = $templateModel ? app(WorksheetService::class)->relatedTemplateIds($templateModel) : [(int) $ws->template_id];
                $busy = DB::table('worksheet_samples as m')->join('worksheets as w', 'w.id', '=', 'm.worksheet_id')
                    ->join('samples as s', 's.id', '=', 'm.sample_id')
                    ->whereNull('w.deleted_at')->where('w.status', '!=', 'approved')->where('m.status', 'active')
                    ->whereIn('w.template_id', $related)->where('w.id', '!=', $ws->getKey())
                    ->whereIn('m.sample_id', $ids)
                    ->when(WorksheetService::hasKind(), fn ($q) => $q->where('w.kind', $ws->kind ?? 'main'))
                    ->select(['s.code', 'w.number'])->get();
                foreach ($busy as $b) {
                    $blockers[] = "{$b->code} namunasi hozir № {$b->number} ishchi varaqda — avval undan chiqaring yoki o'sha varaqni yakunlang.";
                }
            } catch (Throwable) {
                // ishchi varaqlar moduli eski — tekshiruvsiz
            }
        }

        return [$blockers, $warnings];
    }

    public function restore(string $type, int $id, User $by, string $locale): array
    {
        $item = $this->find($type, $id);
        $check = $this->check($type, $item);
        if ($check['blockers']) {
            throw ValidationException::withMessages(['restore' => $check['blockers']]);
        }

        $row = $this->table($type)->where('t.id', $id)->first();
        $title = $this->title($type, $row);
        $deletedAt = $row->deleted_at;

        DB::transaction(function () use ($item, $type, $title, $deletedAt) {
            $item->restore();
            ActivityLog::create([
                'user_id'     => auth()->id(),
                'action'      => 'restored',
                'model_type'  => self::MODELS[$type],
                'model_id'    => $item->getKey(),
                'description' => "Savatchadan tiklandi: {$title}",
                'properties'  => ['deleted_at' => $deletedAt ? Carbon::parse($deletedAt)->format('Y-m-d H:i:s') : null],
                'ip_address'  => request()?->ip(),
                'created_at'  => now(),
            ]);
        });

        return ['message' => "Tiklandi: {$title}", 'warnings' => $check['warnings'], 'id' => (int) $item->getKey(), 'title' => $title];
    }
}
