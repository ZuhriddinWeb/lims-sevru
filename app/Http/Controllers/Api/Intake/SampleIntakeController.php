<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Sample;
use App\Models\SampleQuantityMovement;
use App\Services\Samples\QuantityUnit;
use App\Services\Samples\SampleAnalyses;
use App\Services\Samples\SampleLedger;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Laboratoriya tomoni: probaning qabul ma'lumoti, miqdor hisobi (qoldiq), qo'lda harakatlar,
 * nazorat tahlili solishtiruvi va «Proba qoldiqlari» hisoboti.
 */
class SampleIntakeController extends Controller
{
    use LogsActivity;

    public function __construct(private SampleLedger $ledger, private SampleAnalyses $analyses)
    {
    }

    /** Proba kartochkasidagi panel uchun hammasi bitta so'rovda */
    public function show(Sample $sample)
    {
        $sample->load(['customerDepartment:id,code,name,contact_person,phone', 'registeredBy:id,name']);

        return response()->json([
            'sample'     => ['id' => (int) $sample->id, 'code' => $sample->code, 'sample_number' => $sample->sample_number],
            'intake'     => [
                'customer_department' => $sample->customerDepartment ? [
                    'id'             => (int) $sample->customerDepartment->id,
                    'code'           => $sample->customerDepartment->code,
                    'name'           => $sample->customerDepartment->name,
                    'contact_person' => $sample->customerDepartment->contact_person,
                    'phone'          => $sample->customerDepartment->phone,
                    'deleted'        => $sample->customerDepartment->trashed(),
                ] : null,
                'customer_code'     => $sample->customer_code,
                'received_quantity' => QuantityUnit::number($sample->received_quantity),
                'quantity_unit'     => $sample->quantity_unit,
                'retained_quantity' => QuantityUnit::number($sample->retained_quantity),
                'registered_at'     => $sample->registered_at?->toDateTimeString(),
                'registered_by'     => $sample->registeredBy?->name,
                'handed_over_by'    => $sample->getAttribute('handed_over_by'), // [P24]
            ],
            'ledger'     => $this->ledger->detail($sample),
            'comparison' => $this->analyses->comparison($sample),
        ]);
    }

    /** Qabul qilingan miqdor / birlik / arxiv uchun ajratilgan qism (eski probalarga ham kiritish mumkin) */
    public function updateQuantity(Request $request, Sample $sample)
    {
        $data = $request->validate([
            'received_quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'quantity_unit'     => ['required', Rule::in(QuantityUnit::UNITS)],
            'retained_quantity' => ['nullable', 'numeric', 'gte:0', 'max:9999999999'],
        ]);
        if (isset($data['retained_quantity']) && (float) $data['retained_quantity'] > (float) $data['received_quantity']) {
            throw ValidationException::withMessages(['retained_quantity' => ["Arxiv uchun qism qabul qilingan miqdordan katta bo'lmasin"]]);
        }

        $before = [
            'received_quantity' => QuantityUnit::number($sample->received_quantity),
            'quantity_unit'     => $sample->quantity_unit,
            'retained_quantity' => QuantityUnit::number($sample->retained_quantity),
        ];
        $sample->forceFill([
            'received_quantity' => (float) $data['received_quantity'],
            'quantity_unit'     => $data['quantity_unit'],
            'retained_quantity' => isset($data['retained_quantity']) && $data['retained_quantity'] !== '' ? (float) $data['retained_quantity'] : null,
        ])->save();

        $this->logActivity('updated', $sample, ['before' => $before, 'after' => $data],
            "Proba miqdori yangilandi: {$sample->code} — {$data['received_quantity']} {$data['quantity_unit']}");

        return $this->show($sample->fresh());
    }

    /**
     * Qo'lda harakat: { kind: other|return|dispose|correction, quantity, note }
     * other/return/dispose — qoldiqdan ayiriladi; correction — ishorasi bilan (+ qo'shiladi, − ayiriladi).
     */
    public function storeMovement(Request $request, Sample $sample)
    {
        $data = $request->validate([
            'kind'     => ['required', Rule::in(SampleQuantityMovement::KINDS)],
            'quantity' => ['required', 'numeric', 'not_in:0', 'max:9999999999', 'min:-9999999999'],
            'note'     => ['nullable', 'string', 'max:500'],
        ]);

        $unit = QuantityUnit::normalize($sample->quantity_unit);
        if ($sample->received_quantity === null || ! $unit) {
            throw ValidationException::withMessages(['quantity' => ["Avval probaning qabul qilingan miqdorini kiriting"]]);
        }
        $qty = (float) $data['quantity'];
        $note = trim((string) ($data['note'] ?? ''));

        if (abs($qty) < 1e-9) {
            throw ValidationException::withMessages(['quantity' => ["Miqdor noldan farqli bo'lsin"]]);
        }
        if ($data['kind'] !== 'correction' && $qty < 0) {
            throw ValidationException::withMessages(['quantity' => ["Miqdor musbat son bo'lsin"]]);
        }
        if (in_array($data['kind'], ['correction', 'other'], true) && mb_strlen($note) < 3) {
            throw ValidationException::withMessages(['note' => ["Sababini yozing"]]);
        }

        $delta = $data['kind'] === 'correction' ? $qty : -abs($qty);

        // Sarf qoldiqdan oshmasin (tuzatish bundan mustasno — u qoldiqni tenglashtirish uchun)
        if ($delta < 0 && ! $request->boolean('confirm')) {
            $summary = $this->ledger->summary($sample);
            if ($summary['balance'] !== null && -$delta > $summary['balance'] + 1e-9) {
                return response()->json([
                    'message'      => "Qoldiq yetarli emas: {$summary['balance']} {$unit}. Baribir yozish uchun tasdiqlang.",
                    'need_confirm' => true,
                    'balance'      => $summary['balance'],
                    'unit'         => $unit,
                ], 409);
            }
        }

        $movement = $sample->quantityMovements()->create([
            'kind'       => $data['kind'],
            'delta'      => $delta,
            'unit'       => $unit,
            'note'       => $note !== '' ? $note : null,
            'created_by' => $request->user()->id,
        ]);

        $this->logActivity('created', $movement, ['sample' => $sample->code, 'kind' => $data['kind'], 'delta' => $delta, 'unit' => $unit, 'note' => $note],
            "Proba miqdori harakati: {$sample->code} — {$data['kind']} {$delta} {$unit}");

        return response()->json($this->show($sample->fresh())->getData(true), 201);
    }

    public function destroyMovement(Request $request, Sample $sample, SampleQuantityMovement $movement)
    {
        abort_if((int) $movement->sample_id !== (int) $sample->id, 404);

        $movement->delete();
        $this->logActivity('deleted', $movement, ['sample' => $sample->code, 'kind' => $movement->kind, 'delta' => $movement->delta],
            "Proba miqdori harakati bekor qilindi: {$sample->code}");

        return $this->show($sample->fresh());
    }

    /**
     * «Proba qoldiqlari» hisoboti.
     *   ?search=&customer_department_id=&date_from=&date_to=&quantity=with|without|all&state=low|empty|negative|ok|attention
     */
    public function balances(Request $request)
    {
        $user = $request->user();
        $query = Sample::query()->with(['customerDepartment:id,code,name', 'customerGroup', 'assignedGroup']);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%$search%")
                  ->orWhere('sample_number', 'like', "%$search%")
                  ->orWhere('customer_code', 'like', "%$search%");
            });
        }
        if ($request->filled('customer_department_id')) {
            $query->where('customer_department_id', (int) $request->input('customer_department_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('registered_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('registered_at', '<=', $request->input('date_to'));
        }
        $mode = $request->input('quantity', 'with');
        if ($mode === 'with') {
            $query->whereNotNull('received_quantity');
        } elseif ($mode === 'without') {
            $query->whereNull('received_quantity');
        }

        // Probalar ro'yxatidagi kabi: hammasini ko'rish ruxsati bo'lmasa — o'z guruhi
        $seesAll = $user->isAdmin() || $user->hasPermission('samples.view_all');
        if (! $seesAll && $user->laboratory_group_id) {
            $g = $user->laboratory_group_id;
            $query->where(fn ($q) => $q->where('assigned_group_id', $g)->orWhere('customer_group_id', $g)->orWhere('registering_group_id', $g));
        }

        $query->orderByDesc('registered_at')->orderByDesc('id');
        $perPage = min(100, max(5, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));
        $state = $request->input('state');

        if ($state) {
            // Holat hisoblab topiladi — oxirgi 1000 ta proba ichidan filtrlanadi
            $all = $query->limit(1000)->get();
            $sums = $this->ledger->summaries($all);
            $wanted = $state === 'attention' ? ['low', 'empty', 'negative'] : [$state];
            $filtered = $all->filter(fn ($s) => in_array($sums[$s->id]['state'] ?? 'unknown', $wanted, true))->values();
            $items = $filtered->forPage($page, $perPage)->values();
            $paginator = new LengthAwarePaginator($items->map(fn ($s) => $this->balanceRow($s, $sums[$s->id])), $filtered->count(), $perPage, $page);
        } else {
            $paginator = $query->paginate($perPage);
            $sums = $this->ledger->summaries($paginator->getCollection());
            $paginator->setCollection($paginator->getCollection()->map(fn ($s) => $this->balanceRow($s, $sums[$s->id])));
        }

        return response()->json($paginator);
    }

    private function balanceRow(Sample $s, array $sum): array
    {
        return [
            'id'              => (int) $s->id,
            'code'            => $s->code,
            'sample_number'   => $s->sample_number,
            'customer_code'   => $s->customer_code,
            'status'          => $s->status,
            'registered_at'   => $s->registered_at?->toDateTimeString(),
            'customer_department' => $s->customerDepartment ? ['id' => (int) $s->customerDepartment->id, 'name' => $s->customerDepartment->name, 'code' => $s->customerDepartment->code] : null,
            'customer_group'  => $s->customerGroup,
            'assigned_group'  => $s->assignedGroup,
        ] + $sum;
    }
}
