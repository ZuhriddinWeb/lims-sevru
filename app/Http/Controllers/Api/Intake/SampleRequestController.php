<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\SampleRequest;
use App\Models\User;
use App\Services\Samples\SampleRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Laboratoriya — «Buyurtmalar»: kelgan so'rovlar, namuna qabul qilish, rad etish,
 * buyurtmachi nomidan so'rov yaratish (telefon orqali kelgan buyurtma).
 *
 * Ruxsatlar (routes/sample_requests.php):
 *   sample_requests.view     — ro'yxat va kartochka
 *   sample_requests.receive  — qabul qilish (probalar yaratiladi) va rad etish
 *   sample_requests.create   — buyurtmachi nomidan so'rov yaratish
 *
 * Buyurtmachining QORALAMALARI laboratoriyaga ko'rinmaydi — faqat yuborilganlari.
 *
 * [P30] Guruhga biriktirilgan xodim (admin bo'lmasa) faqat o'z guruhiga yuborilgan buyurtmalarni
 * (va guruh tanlanmagan eski buyurtmalarni) ko'radi. «Guruh shifri» — faqat o'sha guruh xodimlariga.
 */
class SampleRequestController extends Controller
{
    public function __construct(private SampleRequests $requests)
    {
    }

    private function visible(Builder $q, ?User $user = null): Builder
    {
        $q->where(fn ($w) => $w->where('status', '!=', 'draft')->orWhere('origin', 'lab'));
        $g = $user ? $this->ownGroup($user) : null;
        if ($g) {
            $q->where(fn ($w) => $w->where('laboratory_group_id', $g)->orWhereNull('laboratory_group_id'));
        }

        return $q;
    }

    /** [P30] Xodim faqat o'z guruhini ko'radimi — guruh id (aks holda null) */
    private function ownGroup(User $user): ?int
    {
        if (! $this->requests->hasGroupColumns() || ! $user->laboratory_group_id || SampleRequests::seesAllGroups($user)) {
            return null;
        }

        return (int) $user->laboratory_group_id;
    }

    private function find(int $id, ?User $user = null): SampleRequest
    {
        $r = $this->visible(SampleRequest::query(), $user ?? request()->user())->whereKey($id)->first();
        abort_unless($r, 404, "So'rov topilmadi");

        return $r;
    }

    private function payload(SampleRequest $r, Request $request, bool $withItems = true): array
    {
        $user = $request->user();
        $data = $this->requests->present($r, $withItems,
            SampleRequests::canSeeGroupCode($user, $r->laboratory_group_id ? (int) $r->laboratory_group_id : null));
        $create = $user->hasPermission('sample_requests.create');
        $receive = $user->hasPermission('sample_requests.receive');
        $labDraft = $r->status === 'draft' && $r->origin === 'lab';
        $data['can'] = [
            'edit'    => $create && $labDraft,
            'delete'  => $create && $labDraft,
            'submit'  => $create && $labDraft,
            'receive' => $receive && in_array($r->status, SampleRequest::WAITING, true),
            'reject'  => $receive && in_array($r->status, SampleRequest::WAITING, true),
            'print'   => $r->status !== 'draft' && $r->number !== null,
        ];

        return $data;
    }

    /* --------------------------------------------------------------------------- */

    public function options(Request $request)
    {
        $user = $request->user();

        return response()->json($this->requests->options() + [
            'departments' => CustomerDepartment::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn ($d) => ['id' => (int) $d->id, 'code' => $d->code, 'name' => $d->name])->values(),
            'groups'      => DB::table('laboratory_groups')->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'code', 'name_uz', 'name_ru', 'name_en'])
                ->map(fn ($g) => ['id' => (int) $g->id, 'code' => $g->code, 'name_uz' => $g->name_uz, 'name_ru' => $g->name_ru, 'name_en' => $g->name_en])->values(),
            'can_create'  => $user->hasPermission('sample_requests.create'),
            'can_receive' => $user->hasPermission('sample_requests.receive'),
            // [P30] xodimning guruhi: faqat o'z guruhini ko'rsa — own_group; hammasini ko'rsa — guruh filtri
            'my_group'    => $user->laboratory_group_id ? $this->requests->groupInfo((int) $user->laboratory_group_id) : null,
            'own_group'   => $this->ownGroup($user),
        ]);
    }

    /** ?tab=waiting|expired|received|closed|drafts|all&department_id=&search=&page= */
    public function index(Request $request)
    {
        $tabs = [
            'waiting'  => ['submitted', 'partial'],
            'expired'  => ['expired'],
            'received' => ['received'],
            'closed'   => ['rejected', 'cancelled'],
            'drafts'   => ['draft'],
        ];

        $base = $this->visible(SampleRequest::query(), $request->user());
        if ($request->filled('department_id')) {
            $base->where('customer_department_id', (int) $request->input('department_id'));
        }
        // [P30] guruh filtri (hammasini ko'radigan xodim uchun; guruh xodimi baribir faqat o'zinikini ko'radi)
        if ($request->filled('group_id') && $this->requests->hasGroupColumns()) {
            $request->input('group_id') === 'none'
                ? $base->whereNull('laboratory_group_id')
                : $base->where('laboratory_group_id', (int) $request->input('group_id'));
        }
        if ($search = trim((string) $request->input('search'))) {
            $base->where(function ($w) use ($search) {
                $w->where('number', 'like', "%$search%")
                  ->orWhere('contact_name', 'like', "%$search%")
                  ->orWhereIn('id', fn ($s) => $s->select('sample_request_id')->from('sample_request_items')->where('customer_code', 'like', "%$search%"));
            });
        }

        // yorliqlardagi sonlar (filtr va qidiruv hisobga olinadi)
        $counts = (clone $base)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $tabCounts = [];
        foreach ($tabs as $k => $statuses) {
            $tabCounts[$k] = (int) collect($statuses)->sum(fn ($s) => (int) ($counts[$s] ?? 0));
        }

        $tab = (string) $request->input('tab', 'waiting');
        $q = (clone $base)->with('items:id,sample_request_id,customer_code,status');
        if (isset($tabs[$tab])) {
            $q->whereIn('status', $tabs[$tab]);
        }
        $q = $tab === 'waiting' || $tab === 'expired'
            ? $q->orderBy('submitted_at')->orderBy('id')        // eng eskisi birinchi — navbat
            : $q->orderByDesc('updated_at')->orderByDesc('id');

        $page = $q->paginate(min(50, max(5, (int) $request->input('per_page', 20))));
        $page->setCollection($page->getCollection()->map(fn (SampleRequest $r) => $this->payload($r, $request, false)));

        $out = $page->toArray();
        $out['tab_counts'] = $tabCounts;

        // [P30] namunalar yig'indisi: har yorliqdagi buyurtmalarning namunalari (kutilayotgan / qabul qilingan)
        $rows = DB::table('sample_request_items')
            ->join('sample_requests', 'sample_requests.id', '=', 'sample_request_items.sample_request_id')
            ->whereIn('sample_requests.id', (clone $base)->select('sample_requests.id'))
            ->groupBy('sample_requests.status', 'sample_request_items.status')
            ->select('sample_requests.status as rs', 'sample_request_items.status as st', DB::raw('count(*) as n'))
            ->get();
        $tabSamples = [];
        foreach ($tabs as $k => $statuses) {
            $in = $rows->whereIn('rs', $statuses);
            $tabSamples[$k] = [
                'items'    => (int) $in->sum('n'),
                'pending'  => (int) $in->where('st', 'pending')->sum('n'),
                'received' => (int) $in->where('st', 'received')->sum('n'),
            ];
        }
        $out['tab_samples'] = $tabSamples;

        return response()->json($out);
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->payload($this->find($id), $request));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->requests->rules() + [
            'department_id' => ['required', 'integer', Rule::exists('customer_departments', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ]);
        $r = $this->requests->create(CustomerDepartment::findOrFail((int) $data['department_id']), $data, $request->user(), 'lab');
        if ($request->boolean('submit')) {
            $r = $this->requests->submit($r, $request->user());
        }

        return response()->json($this->payload($r, $request), 201);
    }

    public function update(Request $request, int $id)
    {
        $r = $this->find($id);
        abort_unless($r->origin === 'lab', 403, "Buyurtmachi tuzgan so'rovni tahrirlab bo'lmaydi — qabulda to'g'rilang");
        $data = $request->validate($this->requests->rules() + [
            'department_id' => ['nullable', 'integer', Rule::exists('customer_departments', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ]);
        if (! empty($data['department_id'])) {
            $r->customer_department_id = (int) $data['department_id'];
        }
        $r = $this->requests->update($r, $data, $request->user());
        if ($request->boolean('submit')) {
            $r = $this->requests->submit($r, $request->user());
        }

        return response()->json($this->payload($r, $request));
    }

    public function destroy(Request $request, int $id)
    {
        $r = $this->find($id);
        abort_unless($r->origin === 'lab', 403, "Buyurtmachi tuzgan so'rovni o'chirib bo'lmaydi");
        $this->requests->delete($r, $request->user());

        return response()->json(['ok' => true]);
    }

    public function submit(Request $request, int $id)
    {
        $r = $this->find($id);
        abort_unless($r->origin === 'lab', 403, "Ruxsat yo'q");

        return response()->json($this->payload($this->requests->submit($r, $request->user()), $request));
    }

    public function receive(Request $request, int $id)
    {
        $data = $request->validate([
            'handed_over_by'            => ['nullable', 'string', 'max:191'],
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.id'                => ['required', 'integer'],
            'items.*.sample_number'     => ['nullable', 'string', 'max:191'],
            'items.*.customer_code'     => ['nullable', 'string', 'max:64'],
            'items.*.state_of_matter'   => ['nullable', 'in:liquid,solid'],
            'items.*.test_profile_id'   => ['nullable', 'integer'],
            'items.*.element_ids'       => ['nullable', 'array'],
            'items.*.element_ids.*'     => ['integer'],
            'items.*.received_quantity' => ['nullable', 'numeric'],
            'items.*.quantity_unit'     => ['nullable', 'string', 'max:8'],
            'items.*.assigned_group_id' => ['nullable', 'integer'],
            'items.*.notes'             => ['nullable', 'string', 'max:2000'],
        ]);
        $result = $this->requests->receive($this->find($id), $data, $request->user());

        return response()->json([
            'request' => $this->payload($result['request'], $request),
            'samples' => collect($result['samples'])->map(fn ($s) => [
                'id' => (int) $s->id, 'code' => $s->code, 'sample_number' => $s->sample_number, 'customer_code' => $s->customer_code,
            ])->values(),
        ]);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json($this->payload($this->requests->reject($this->find($id), $request->user(), $data['reason']), $request));
    }
}
