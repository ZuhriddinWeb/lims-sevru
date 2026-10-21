<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\SampleRequest;
use App\Services\Samples\CustomerPortal;
use App\Services\Samples\SampleRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Buyurtmachi portali — «Buyurtmalarim»: qoralama tuzish, yuborish, qaytarib olish, bekor qilish.
 *
 *   portal foydalanuvchisi (portal.request) — o'z bo'limlari nomidan yozadi;
 *   portal foydalanuvchisi (faqat portal.view) — faqat ko'radi;
 *   laboratoriya xodimi (customer_departments.view) — «ko'rib chiqish» rejimida faqat ko'radi
 *   (xodim buyurtmachi nomidan so'rovni «Buyurtmalar» sahifasida yaratadi — SampleRequestController).
 *
 * /api/portal/* — CustomerPortalFence portal foydalanuvchisiga ochiq qoldiradigan yo'l.
 * Buyurtmachi laboratoriya xodimlari yaratgan QORALAMALARNI ko'rmaydi (ular hali tayyor emas).
 */
class PortalRequestController extends Controller
{
    public function __construct(private CustomerPortal $portal, private SampleRequests $requests)
    {
    }

    /** @return array{portal: bool, preview: bool, write: bool, ids: int[]|null} */
    private function ctx(Request $request): array
    {
        $user = $request->user();
        if ($this->portal->isPortalUser($user)) {
            return [
                'portal'  => true,
                'preview' => false,
                'write'   => $user->hasPermission(SampleRequests::PORTAL_PERMISSION),
                'ids'     => $this->portal->linkedDepartments($user)->pluck('id')->map(fn ($v) => (int) $v)->all(),
            ];
        }
        abort_unless($user && $this->portal->canPreview($user), 403, "Buyurtmachi portalini ko'rish uchun ruxsat yo'q");

        return ['portal' => false, 'preview' => true, 'write' => false, 'ids' => null];
    }

    private function visible(Builder $q, array $ctx): Builder
    {
        if ($ctx['ids'] !== null) {
            $q->whereIn('customer_department_id', $ctx['ids'] ?: [0]);
        }

        // xodimning qoralamasi buyurtmachiga ko'rinmaydi
        return $q->where(fn ($w) => $w->where('origin', 'portal')->orWhere('status', '!=', 'draft'));
    }

    private function find(Request $request, int $id, array $ctx): SampleRequest
    {
        $r = $this->visible(SampleRequest::query(), $ctx)->whereKey($id)->first();
        abort_unless($r, 404, "So'rov topilmadi");

        return $r;
    }

    private function can(SampleRequest $r, array $ctx, int $received): array
    {
        $own = $ctx['write'] && $r->origin === 'portal';

        return [
            'edit'     => $own && $r->status === 'draft',
            'delete'   => $own && $r->status === 'draft',
            'submit'   => $own && $r->status === 'draft',
            'withdraw' => $ctx['write'] && in_array($r->status, ['submitted', 'expired'], true) && $received === 0,
            'cancel'   => $ctx['write'] && in_array($r->status, ['draft', 'submitted', 'expired'], true) && $received === 0,
            'print'    => $r->status !== 'draft' && $r->number !== null,
        ];
    }

    private function payload(SampleRequest $r, array $ctx, bool $withItems = true): array
    {
        $data = $this->requests->present($r, $withItems);
        $data['can'] = $this->can($r, $ctx, $data['counts']['received']);

        return $data;
    }

    private function department(Request $request, array $ctx): CustomerDepartment
    {
        $id = (int) $request->input('department_id');
        if (! $id && count($ctx['ids'] ?? []) === 1) {
            $id = $ctx['ids'][0];
        }
        abort_unless($id && in_array($id, $ctx['ids'] ?? [], true), 422, "Bo'limni tanlang");
        $dept = CustomerDepartment::where('is_active', true)->find($id);
        abort_unless($dept, 422, "Bo'lim faol emas");

        return $dept;
    }

    /* --------------------------------------------------------------------------- */

    public function options(Request $request)
    {
        $ctx = $this->ctx($request);
        $departments = $ctx['ids'] !== null
            ? CustomerDepartment::whereIn('id', $ctx['ids'] ?: [0])->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
            : CustomerDepartment::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);

        return response()->json($this->requests->options() + [
            'departments' => $departments->map(fn ($d) => ['id' => (int) $d->id, 'code' => $d->code, 'name' => $d->name])->values(),
            'can_write'   => $ctx['write'],
            'preview'     => $ctx['preview'],
        ]);
    }

    /** ?tab=draft|sent|done|closed|all&department_id=&search=&page= */
    public function index(Request $request)
    {
        $ctx = $this->ctx($request);
        $q = $this->visible(SampleRequest::query(), $ctx)->with('items:id,sample_request_id,customer_code,status');

        if ($request->filled('department_id')) {
            $q->where('customer_department_id', (int) $request->input('department_id'));
        }
        $tabs = [
            'draft'  => ['draft'],
            'sent'   => ['submitted', 'partial', 'expired'],
            'done'   => ['received'],
            'closed' => ['rejected', 'cancelled'],
        ];
        $tab = (string) $request->input('tab', 'all');
        if (isset($tabs[$tab])) {
            $q->whereIn('status', $tabs[$tab]);
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('number', 'like', "%$search%")
                  ->orWhereIn('id', fn ($s) => $s->select('sample_request_id')->from('sample_request_items')->where('customer_code', 'like', "%$search%"));
            });
        }

        $page = $q->orderByDesc('updated_at')->orderByDesc('id')->paginate(min(50, max(5, (int) $request->input('per_page', 20))));
        $page->setCollection($page->getCollection()->map(fn (SampleRequest $r) => $this->payload($r, $ctx, false)));

        return response()->json($page);
    }

    public function show(Request $request, int $id)
    {
        $ctx = $this->ctx($request);

        return response()->json($this->payload($this->find($request, $id, $ctx), $ctx));
    }

    public function store(Request $request)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Buyurtma yuborish uchun ruxsat yo'q");
        $data = $request->validate($this->requests->rules() + ['department_id' => ['nullable', 'integer']]);
        $dept = $this->department($request, $ctx);

        $r = $this->requests->create($dept, $data, $request->user(), 'portal');
        if ($request->boolean('submit')) {
            $r = $this->submitOrKeep($r, $request);
        }

        return response()->json($this->payload($r, $ctx), 201);
    }

    public function update(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $r = $this->find($request, $id, $ctx);
        abort_unless($this->can($r, $ctx, 0)['edit'], 403, "Bu so'rovni tahrirlab bo'lmaydi");
        $data = $request->validate($this->requests->rules() + ['department_id' => ['nullable', 'integer']]);
        if ($request->filled('department_id') && (int) $request->input('department_id') !== (int) $r->customer_department_id) {
            $r->customer_department_id = $this->department($request, $ctx)->id;
        }

        $r = $this->requests->update($r, $data, $request->user());
        if ($request->boolean('submit')) {
            $r = $this->submitOrKeep($r, $request);
        }

        return response()->json($this->payload($r, $ctx));
    }

    /** Saqlab, darhol yuborish: yuborish xatosi bo'lsa — qoralama saqlangan holda 422 */
    private function submitOrKeep(SampleRequest $r, Request $request): SampleRequest
    {
        try {
            return $this->requests->submit($r, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $e->response = response()->json([
                'message'  => "Qoralama saqlandi, lekin yuborilmadi: " . collect($e->errors())->flatten()->first(),
                'errors'   => $e->errors(),
                'saved_id' => (int) $r->id,
            ], 422);
            throw $e;
        }
    }

    public function destroy(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $r = $this->find($request, $id, $ctx);
        abort_unless($this->can($r, $ctx, 0)['delete'], 403, "Bu so'rovni o'chirib bo'lmaydi");
        $this->requests->delete($r, $request->user());

        return response()->json(['ok' => true]);
    }

    public function submit(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $r = $this->find($request, $id, $ctx);
        abort_unless($this->can($r, $ctx, 0)['submit'], 403, "Bu so'rovni yuborib bo'lmaydi");

        return response()->json($this->payload($this->requests->submit($r, $request->user()), $ctx));
    }

    public function withdraw(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $r = $this->find($request, $id, $ctx);
        abort_unless($ctx['write'], 403, "Ruxsat yo'q");

        return response()->json($this->payload($this->requests->withdraw($r, $request->user()), $ctx));
    }

    public function cancel(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $r = $this->find($request, $id, $ctx);
        abort_unless($ctx['write'], 403, "Ruxsat yo'q");
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->payload($this->requests->cancel($r, $request->user(), $data['reason'] ?? null), $ctx));
    }
}
