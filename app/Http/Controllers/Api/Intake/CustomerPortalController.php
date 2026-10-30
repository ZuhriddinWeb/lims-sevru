<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\Sample;
use App\Services\Samples\CustomerPortal;
use App\Services\Samples\PortalVisibility; // [P27] admin belgilagan ko'rinish
use App\Services\Samples\SampleAnalyses;
use App\Services\Samples\SampleLedger;
use App\Services\Svodka\SvodkaGate; // [P29]
use Illuminate\Http\Request;

/**
 * Buyurtmachi portali — FAQAT o'qish.
 * Bo'lim foydalanuvchisi o'z bo'limi topshirgan probalarni, ularning bosqichini
 * va o'tkazilgan tahlillar ma'lumotini (kiritilishi bilanoq) ko'radi.
 * Partiya varaqlarida boshqa probalarning ma'lumoti ko'rsatilmaydi.
 */
class CustomerPortalController extends Controller
{
    public function __construct(
        private CustomerPortal $portal,
        private SampleAnalyses $analyses,
        private SampleLedger $ledger,
        private PortalVisibility $visibility, // [P27]
    ) {
    }

    /** Portal holati: kim, qaysi bo'limlar, ko'rib chiqish rejimimi */
    public function context(Request $request)
    {
        $user = $request->user();
        $isPortal = $this->portal->isPortalUser($user);
        abort_unless($isPortal || $this->portal->canPreview($user), 403, "Buyurtmachi portalini ko'rish uchun ruxsat yo'q");

        $departments = $isPortal
            ? $this->portal->linkedDepartments($user)
            : CustomerDepartment::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);

        return response()->json([
            'portal'      => $isPortal,
            'preview'     => ! $isPortal,
            'departments' => $departments->map(fn ($d) => ['id' => (int) $d->id, 'code' => $d->code, 'name' => $d->name])->values(),
            // [P27] nima ko'rinadi (interfeys bo'sh bo'limlarni yashiradi; ma'lumotning o'zi serverda olib tashlanadi)
            'visibility'    => $this->visibility->get(),
            'can_configure' => ! $isPortal && $user->hasPermission('portal.settings'),
        ]);
    }

    /** ?search=&status=active|done|<status>&department_id=&date_from=&date_to=&page= */
    public function samples(Request $request)
    {
        $scope = $this->portal->scope($request);

        $query = Sample::query()->with([
            'sampleElements:id,sample_id,element_id,status,result_value,result_unit', // [P28] natija ham
            'sampleElements.element:id,symbol',
            'customerDepartment:id,code,name',
        ]);

        if ($scope['preview'] && ! $scope['ids']) {
            $query->whereNotNull('customer_department_id');
        } else {
            $query->whereIn('customer_department_id', $scope['ids'] ?: [0]);
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_code', 'like', "%$search%")
                  ->orWhere('code', 'like', "%$search%")
                  ->orWhere('sample_number', 'like', "%$search%");
            });
        }
        $status = $request->input('status');
        if ($status === 'active') {
            $query->whereNotIn('status', ['completed', 'archived']);
        } elseif ($status === 'done') {
            $query->whereIn('status', ['completed', 'archived']);
        } elseif ($status && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('registered_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('registered_at', '<=', $request->input('date_to'));
        }

        $perPage = min(50, max(5, (int) $request->input('per_page', 20)));
        $page = $query->orderByDesc('registered_at')->orderByDesc('id')->paginate($perPage);

        $ids = $page->getCollection()->pluck('id')->map(fn ($v) => (int) $v)->all();
        $meta = $this->analyses->worksheetMeta($ids);
        $results = $this->analyses->listResults($page->getCollection()); // [P28]
        $gates = SvodkaGate::for($page->getCollection());                 // [P29] svodka rejimi: chiqarilmaganlari yopiq

        $page->setCollection($page->getCollection()->map(function (Sample $s) use ($meta, $results, $gates) {
            $steps = $this->analyses->steps($s, $meta[(int) $s->id] ?? []);

            $item = $this->visibility->filterListItem([ // [P27]
                'id'              => (int) $s->id,
                'code'            => $s->code,
                'customer_code'   => $s->customer_code,
                'sample_number'   => $s->sample_number,
                'state_of_matter' => $s->state_of_matter,
                'status'          => $s->status,
                'registered_at'   => $s->registered_at?->toDateTimeString(),
                'completed_at'    => $s->completed_at?->toDateTimeString(),
                'department'      => $s->customerDepartment ? ['id' => (int) $s->customerDepartment->id, 'name' => $s->customerDepartment->name, 'code' => $s->customerDepartment->code] : null,
                'progress'        => $s->progress,
                'elements'        => $s->sampleElements->map(fn ($se) => [
                    'symbol' => $se->element?->symbol,
                    'done'   => $se->status === 'done',
                ])->values(),
                'stage'           => $steps['current'],
                'steps'           => $steps['steps'],
                'worksheets'      => $steps['worksheets'],
                'results'         => $results[(int) $s->id] ?? [], // [P28] qiymatlar — kartochkani ochmasdan
            ]);
            $g = $gates[(int) $s->id] ?? null;
            $item['results'] = SvodkaGate::chips($item['results'], $g);
            $item['svodka_locked'] = $g && $g['enabled'] && ! $g['full'];

            return $item;
        }));

        // [P28] shu sahifadagi namunalar o'tgan varaqlar bo'yicha jami (faqat ko'ruvchining namunalari bo'yicha)
        $wsIds = [];
        foreach ($meta as $list) {
            foreach ($list as $m) {
                $wsIds[] = $m['worksheet_id'];
            }
        }
        $totals = SvodkaGate::totals($this->visibility->filterTotals(array_values(
            $this->analyses->worksheetTotals($wsIds, $scope['ids'] ?: null)
        )));

        return response()->json($page->toArray() + ['worksheet_totals' => $totals]);
    }

    /** Bitta proba: bosqichlar, natijalar, varaqlardagi shu probaning ma'lumoti, miqdor */
    public function show(Request $request, Sample $sample)
    {
        $this->portal->assertCanSee($request, $sample);

        $sample->load([
            'sampleElements.element:id,symbol,name_uz,name_ru,name_en',
            'customerDepartment:id,code,name',
            'assignedGroup',
        ]);
        $meta = $this->analyses->worksheetMeta([(int) $sample->id])[(int) $sample->id] ?? [];
        $blocks = $this->analyses->blocks($sample);

        // [P28] har varaq bo'yicha jami — ko'ruvchi bo'limi(lari) namunalari bo'yicha
        // (ko'rib chiqish rejimi: shu namuna bo'limi — buyurtmachi ko'radigan holat)
        $scope = $this->portal->scope($request);
        $deptIds = $scope['ids'] ?: [(int) $sample->customer_department_id];
        $wsTotals = collect(SvodkaGate::totals(array_values($this->analyses->worksheetTotals(array_column($blocks, 'id'), $deptIds))))
            ->keyBy('worksheet_id'); // [P29] svodka: to'liq chiqarilmagan namunali varaq jamisi yo'q
        foreach ($blocks as $i => $b) {
            $blocks[$i]['totals'] = $wsTotals->get((int) $b['id']);
        }

        // Elementlar bo'yicha natija: yakuniy (probaga yozilgan) yoki varaqdan dastlabki
        $prelim = [];
        foreach ($blocks as $b) {
            if ($b['kind'] === 'control' || $b['member_status'] === 'retest') {
                continue;
            }
            foreach ($b['summary_columns'] as $c) {
                $v = ((array) $b['summary'])[$c['key']] ?? null;
                if ($c['element_symbol'] && $v !== null) {
                    $prelim[$c['element_symbol']] = [
                        'value' => $v, 'unit' => $c['unit'], 'decimals' => $c['decimals'],
                        'worksheet' => $b['number'], 'approved' => ! $b['preliminary'],
                    ];
                }
            }
        }

        $ledger = $this->ledger->summary($sample);
        $gate = SvodkaGate::for(collect([$sample]))[(int) $sample->id] ?? null; // [P29]

        return response()->json(SvodkaGate::sample($this->visibility->filterSample([ // [P27] admin yopgan qismlar serverda olib tashlanadi
            'sample'     => [
                'id'              => (int) $sample->id,
                'code'            => $sample->code,
                'customer_code'   => $sample->customer_code,
                'sample_number'   => $sample->sample_number,
                'source_document' => $sample->source_document,
                'state_of_matter' => $sample->state_of_matter,
                'status'          => $sample->status,
                'progress'        => $sample->progress,
                'registered_at'   => $sample->registered_at?->toDateTimeString(),
                'assigned_at'     => $sample->assigned_at?->toDateTimeString(),
                'started_at'      => $sample->started_at?->toDateTimeString(),
                'completed_at'    => $sample->completed_at?->toDateTimeString(),
                'department'      => $sample->customerDepartment ? ['id' => (int) $sample->customerDepartment->id, 'name' => $sample->customerDepartment->name, 'code' => $sample->customerDepartment->code] : null,
                'assigned_group'  => $sample->assignedGroup ? [
                    'name_uz' => $sample->assignedGroup->name_uz, 'name_ru' => $sample->assignedGroup->name_ru, 'name_en' => $sample->assignedGroup->name_en,
                ] : null,
            ],
            'steps'      => $this->analyses->steps($sample, $meta),
            'results'    => $sample->sampleElements->map(function ($se) use ($prelim) {
                $symbol = $se->element?->symbol;
                $final = $se->status === 'done' && $se->result_value !== null;

                return [
                    'element_id'  => (int) $se->element_id, // [P29]
                    'element'     => $se->element ? [
                        'symbol' => $symbol, 'name_uz' => $se->element->name_uz,
                        'name_ru' => $se->element->name_ru, 'name_en' => $se->element->name_en,
                    ] : null,
                    'status'      => $se->status,
                    'value'       => $final ? (float) $se->result_value : null,
                    'unit'        => $se->result_unit,
                    'entered_at'  => $final ? $se->result_entered_at?->toDateTimeString() : null,
                    'preliminary' => ! $final && $symbol && isset($prelim[$symbol]) ? $prelim[$symbol] : null,
                ];
            })->values(),
            'quantity'   => [
                'unit'      => $ledger['unit'],
                'received'  => $ledger['received'],
                'consumed'  => $ledger['consumed'],
                'balance'   => $ledger['balance'],
                'state'     => $ledger['state'],
            ],
            'analyses'   => $blocks,
            'comparison' => $this->analyses->comparison($sample, $blocks),
        ]), $gate));
    }
}
