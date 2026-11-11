<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\PortalRequestTemplate;
use App\Services\Samples\CustomerPortal;
use App\Services\Samples\QuantityUnit;
use App\Services\Samples\SampleRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * [Paket 33] «Shablonlarim» — buyurtmachi bo'linmasining doimiy buyurtma shablonlari.
 *
 *   GET    /api/portal/request-templates?department_id=   ro'yxat (faqat o'z bo'linmalari)
 *   POST   /api/portal/request-templates                  yangi (portal.request)
 *   PUT    /api/portal/request-templates/{id}             tahrirlash
 *   DELETE /api/portal/request-templates/{id}             o'chirish
 *   POST   /api/portal/request-templates/order            tartib (ids[])
 *
 * Shablon bo'linmaga tegishli: bo'linmaning barcha vakillari ko'radi va ishlatadi, boshqa bo'linma ko'rmaydi.
 * Laboratoriya xodimi (ko'rib chiqish rejimi) faqat ko'radi. Sana saqlanmaydi — har buyurtmada yangidan.
 */
class PortalTemplateController extends Controller
{
    private const MAX_ITEMS = 200;

    public function __construct(private CustomerPortal $portal)
    {
    }

    /** @return array{write: bool, ids: int[]|null} */
    private function ctx(Request $request): array
    {
        abort_unless(Schema::hasTable('portal_request_templates'), 503, "Shablonlar jadvali yo'q — php artisan migrate");
        $user = $request->user();
        if ($this->portal->isPortalUser($user)) {
            return [
                'write' => $user->hasPermission(SampleRequests::PORTAL_PERMISSION),
                'ids'   => $this->portal->linkedDepartments($user)->pluck('id')->map(fn ($v) => (int) $v)->all(),
            ];
        }
        abort_unless($user && $this->portal->canPreview($user), 403, "Buyurtmachi portalini ko'rish uchun ruxsat yo'q");

        return ['write' => false, 'ids' => null];
    }

    private function find(int $id, array $ctx): PortalRequestTemplate
    {
        $tpl = PortalRequestTemplate::query()
            ->when($ctx['ids'] !== null, fn ($q) => $q->whereIn('customer_department_id', $ctx['ids'] ?: [0]))
            ->find($id);
        abort_unless($tpl, 404, 'Shablon topilmadi');

        return $tpl;
    }

    private function present(PortalRequestTemplate $t): array
    {
        $p = is_array($t->payload) ? $t->payload : [];

        return [
            'id'            => (int) $t->id,
            'department_id' => (int) $t->customer_department_id,
            'name'          => $t->name,
            'sort_order'    => (int) $t->sort_order,
            'payload'       => $p,
            'items_count'   => count($p['items'] ?? []),
            'updated_at'    => $t->updated_at?->toDateTimeString(),
        ];
    }

    public function index(Request $request)
    {
        $ctx = $this->ctx($request);
        $q = PortalRequestTemplate::query()
            ->when($ctx['ids'] !== null, fn ($w) => $w->whereIn('customer_department_id', $ctx['ids'] ?: [0]));
        if ($request->filled('department_id')) {
            $q->where('customer_department_id', (int) $request->input('department_id'));
        }

        return response()->json([
            'can_write' => $ctx['write'],
            'data'      => $q->orderBy('customer_department_id')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($t) => $this->present($t))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Shablon yaratish uchun ruxsat yo'q");
        [$deptId, $name, $payload] = $this->validated($request, $ctx, null);

        $tpl = PortalRequestTemplate::create([
            'customer_department_id' => $deptId,
            'name'                   => $name,
            'sort_order'             => (int) PortalRequestTemplate::where('customer_department_id', $deptId)->max('sort_order') + 1,
            'payload'                => $payload,
            'created_by'             => $request->user()->id,
            'updated_by'             => $request->user()->id,
        ]);
        $this->log($request, 'created', $tpl, "Buyurtma shabloni yaratildi: {$tpl->name}");

        return response()->json($this->present($tpl), 201);
    }

    public function update(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Shablonni o'zgartirish uchun ruxsat yo'q");
        $tpl = $this->find($id, $ctx);
        [$deptId, $name, $payload] = $this->validated($request, $ctx, $tpl);

        $tpl->update(['customer_department_id' => $deptId, 'name' => $name, 'payload' => $payload, 'updated_by' => $request->user()->id]);
        $this->log($request, 'updated', $tpl, "Buyurtma shabloni o'zgartirildi: {$tpl->name}");

        return response()->json($this->present($tpl->fresh()));
    }

    public function destroy(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Shablonni o'chirish uchun ruxsat yo'q");
        $tpl = $this->find($id, $ctx);
        $name = $tpl->name;
        $tpl->delete();
        $this->log($request, 'deleted', $tpl, "Buyurtma shabloni o'chirildi: {$name}");

        return response()->json(['ok' => true]);
    }

    /** Tugmalar tartibi: ids — bitta bo'linma shablonlari, kerakli tartibda */
    public function order(Request $request)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Ruxsat yo'q");
        $ids = array_values(array_unique(array_map('intval', (array) $request->input('ids', []))));
        $tpls = PortalRequestTemplate::whereIn('id', $ids ?: [0])
            ->whereIn('customer_department_id', $ctx['ids'] ?: [0])->get()->keyBy('id');
        DB::transaction(function () use ($ids, $tpls) {
            foreach ($ids as $i => $id) {
                if ($tpls->has($id)) {
                    $tpls[$id]->forceFill(['sort_order' => $i + 1])->save();
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0:int, 1:string, 2:array} */
    private function validated(Request $request, array $ctx, ?PortalRequestTemplate $tpl): array
    {
        $data = $request->validate([
            'department_id'               => ['nullable', 'integer'],
            'name'                        => ['required', 'string', 'max:120'],
            'payload'                     => ['required', 'array'],
            'payload.laboratory_group_id' => ['nullable', 'integer'],
            'payload.delivery_shift_id'   => ['nullable', 'integer'],
            'payload.contact_name'        => ['nullable', 'string', 'max:191'],
            'payload.contact_phone'       => ['nullable', 'string', 'max:64'],
            'payload.notes'               => ['nullable', 'string', 'max:2000'],
            'payload.items'               => ['nullable', 'array', 'max:' . self::MAX_ITEMS],
            'payload.items.*.customer_code'   => ['nullable', 'string', 'max:64'],
            'payload.items.*.state_of_matter' => ['nullable', Rule::in(['solid', 'liquid'])],
            'payload.items.*.test_profile_id' => ['nullable', 'integer'],
            'payload.items.*.element_ids'     => ['nullable', 'array'],
            'payload.items.*.element_ids.*'   => ['integer'],
            'payload.items.*.quantity'        => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'payload.items.*.quantity_unit'   => ['nullable', Rule::in(QuantityUnit::UNITS)],
            'payload.items.*.notes'           => ['nullable', 'string', 'max:500'],
            'payload.items.*.svodka_point_id' => ['nullable', 'integer'],
            'payload.items.*.group_code'      => ['nullable', 'string', 'max:64'],
        ]);

        $deptId = (int) ($data['department_id'] ?? 0) ?: (int) ($tpl?->customer_department_id ?? 0);
        if (! $deptId && count($ctx['ids'] ?? []) === 1) {
            $deptId = $ctx['ids'][0];
        }
        if (! $deptId || ! in_array($deptId, $ctx['ids'] ?? [], true)) {
            throw ValidationException::withMessages(['department_id' => ["Bo'limni tanlang"]]);
        }
        abort_unless(CustomerDepartment::where('is_active', true)->whereKey($deptId)->exists(), 422, "Bo'lim faol emas");

        $name = trim($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Shablon nomini kiriting']]);
        }
        $dup = PortalRequestTemplate::where('customer_department_id', $deptId)->where('name', $name)
            ->when($tpl, fn ($q) => $q->where('id', '!=', $tpl->id))->exists();
        if ($dup) {
            throw ValidationException::withMessages(['name' => ["«{$name}» nomli shablon bu bo'limda allaqachon bor"]]);
        }

        $p = $data['payload'];
        $str = fn ($v, $max) => ($v = trim((string) ($v ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $int = fn ($v) => (int) ($v ?? 0) ?: null;
        // [P29] nuqta — faqat shu bo'limning nuqtasi (boshqa bo'limniki saqlanmaydi)
        $pointIds = Schema::hasTable('svodka_points')
            ? DB::table('svodka_points')->where('customer_department_id', $deptId)->pluck('id')->map(fn ($v) => (int) $v)->flip()
            : collect();
        $items = [];
        foreach (array_values($p['items'] ?? []) as $it) {
            $point = $int($it['svodka_point_id'] ?? null);
            $qty = $it['quantity'] ?? null;
            $items[] = [
                'customer_code'   => $str($it['customer_code'] ?? null, 64),
                'state_of_matter' => in_array($it['state_of_matter'] ?? null, ['solid', 'liquid'], true) ? $it['state_of_matter'] : 'solid',
                'test_profile_id' => $int($it['test_profile_id'] ?? null),
                'element_ids'     => array_values(array_unique(array_map('intval', (array) ($it['element_ids'] ?? [])))),
                'quantity'        => $qty === null || $qty === '' ? null : (float) $qty,
                'quantity_unit'   => $qty === null || $qty === '' ? null : ($it['quantity_unit'] ?? 'g'),
                'notes'           => $str($it['notes'] ?? null, 500),
                'svodka_point_id' => $point && $pointIds->has($point) ? $point : null,
                'group_code'      => $str($it['group_code'] ?? null, 64),
            ];
        }
        if (! $items) {
            throw ValidationException::withMessages(['payload.items' => ["Shablonda kamida bitta namuna bo'lsin"]]);
        }

        return [$deptId, mb_substr($name, 0, 120), [
            'laboratory_group_id' => $int($p['laboratory_group_id'] ?? null),
            'delivery_shift_id'   => $int($p['delivery_shift_id'] ?? null),
            'contact_name'        => $str($p['contact_name'] ?? null, 191),
            'contact_phone'       => $str($p['contact_phone'] ?? null, 64),
            'notes'               => $str($p['notes'] ?? null, 2000),
            'items'               => $items,
        ]];
    }

    private function log(Request $request, string $action, PortalRequestTemplate $tpl, string $text): void
    {
        try {
            \App\Models\ActivityLog::create([
                'user_id' => $request->user()?->id, 'action' => $action, 'model_type' => 'PortalRequestTemplate',
                'model_id' => $tpl->id, 'description' => mb_substr($text, 0, 255), 'properties' => null,
                'ip_address' => $request->ip(), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
