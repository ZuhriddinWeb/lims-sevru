<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\Sample;
use App\Models\SampleElement;
use App\Services\EquipmentUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SampleController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = Sample::query()->with([
            'customerGroup', 'registeringLab', 'registeringGroup',
            'assignedGroup', 'registeredBy',
            'sampleElements.element',
        ])->withCount('sampleElements');

        // Qidiruv — proba raqami yoki shifr bo'yicha (TZ: поиск по дате или по номеру)
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%$search%")
                  ->orWhere('sample_number', 'like', "%$search%")
                  ->orWhere('source_document', 'like', "%$search%");
            });
        }

        // Oylar bo'yicha ajratish (TZ: нужно разделить по месяцам)
        if ($request->filled('period_month')) {
            $query->where('period_month', $request->input('period_month'));
        }

        // Sanalar oralig'i bo'yicha qidiruv
        if ($request->filled('date_from')) {
            $query->whereDate('registered_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('registered_at', '<=', $request->input('date_to'));
        }

        // Guruh / status filtrlari
        if ($request->filled('assigned_group_id')) {
            $query->where('assigned_group_id', $request->input('assigned_group_id'));
        }
        if ($request->filled('customer_group_id')) {
            $query->where('customer_group_id', $request->input('customer_group_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('state_of_matter')) {
            $query->where('state_of_matter', $request->input('state_of_matter'));
        }

        // TZ — guruhlar faqat o'ziga yuborilgan probalarni ko'radi.
        // samples.view_all ruxsati bo'lmaganlar uchun bu majburiy (serverda).
        $user = auth()->user();
        $seesAll = $user->isAdmin() || $user->hasPermission('samples.view_all');

        if (! $seesAll && $user->laboratory_group_id) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_group_id', $user->laboratory_group_id)
                  ->orWhere('customer_group_id', $user->laboratory_group_id)
                  ->orWhere('registering_group_id', $user->laboratory_group_id);
            });
        } elseif ($request->boolean('my_group_only') && $user->laboratory_group_id) {
            // Ruxsati borlar uchun ixtiyoriy filtr
            $query->where('assigned_group_id', $user->laboratory_group_id);
        }

        $query->orderBy($request->input('sort_by', 'registered_at'), $request->input('sort_dir', 'desc'));

        return $query->paginate($request->input('per_page', 15));
    }

    public function show(Sample $sample)
    {
        return $sample->load([
            'customerGroup', 'registeringLab', 'registeringGroup', 'assignedGroup',
            'registeredBy', 'starter', 'sampleElements.element', 'sampleElements.resultEnteredBy',
            'sampleElements.equipment:id,name,serial_number',
        ]);
    }

    // Probani ro'yxatga olish (Texnologik laboratoriya)
    public function store(Request $request)
    {
        $data = $request->validate([
            'sample_number'        => ['required', 'string', 'max:191'],
            'source_document'      => ['nullable', 'string', 'max:191'],
            'source_division'      => ['nullable', 'string', 'max:191'],
            'customer_group_id'    => ['nullable', 'exists:laboratory_groups,id'],
            'registering_lab_id'   => ['nullable', 'exists:laboratories,id'],
            'registering_group_id' => ['nullable', 'exists:laboratory_groups,id'],
            'assigned_group_id'    => ['nullable', 'exists:laboratory_groups,id'],
            'state_of_matter'      => ['required', 'in:liquid,solid'],
            'notes'                => ['nullable', 'string'],
            'test_profile_id'      => ['nullable', 'exists:test_profiles,id'],
            'element_ids'          => ['required_without:test_profile_id', 'array'],
            'element_ids.*'        => ['exists:elements,id'],
        ]);

        $sample = DB::transaction(function () use ($data) {
            $now = now();

            $sample = Sample::create([
                'code'                 => $this->generateCode(),
                'sample_number'        => $data['sample_number'],
                'source_document'      => $data['source_document'] ?? null,
                'source_division'      => $data['source_division'] ?? null,
                'customer_group_id'    => $data['customer_group_id'] ?? null,
                'registering_lab_id'   => $data['registering_lab_id'] ?? auth()->user()->laboratory_id,
                'registering_group_id' => $data['registering_group_id'] ?? auth()->user()->laboratory_group_id,
                'assigned_group_id'    => $data['assigned_group_id'] ?? null,
                'state_of_matter'      => $data['state_of_matter'],
                'status'               => ! empty($data['assigned_group_id']) ? 'assigned' : 'registered',
                'period_month'         => $now->format('Y-m'),
                'registered_by'        => auth()->id(),
                'registered_at'        => $now,
                'assigned_at'          => ! empty($data['assigned_group_id']) ? $now : null,
                'notes'                => $data['notes'] ?? null,
                'test_profile_id'      => $data['test_profile_id'] ?? null,
            ]);

            // Elementlar: qo'lda tanlangan yoki profildan olingan (TZ 2.10)
            $this->syncElements($sample, $this->resolveElementIds($data));

            return $sample;
        });

        $this->logActivity('created', $sample, $data, "Proba ro'yxatga olindi: {$sample->code}");

        return response()->json($this->show($sample), 201);
    }

    public function update(Request $request, Sample $sample)
    {
        $data = $request->validate([
            'sample_number'        => ['required', 'string', 'max:191'],
            'source_document'      => ['nullable', 'string', 'max:191'],
            'source_division'      => ['nullable', 'string', 'max:191'],
            'customer_group_id'    => ['nullable', 'exists:laboratory_groups,id'],
            'assigned_group_id'    => ['nullable', 'exists:laboratory_groups,id'],
            'state_of_matter'      => ['required', 'in:liquid,solid'],
            'notes'                => ['nullable', 'string'],
            'test_profile_id'      => ['nullable', 'exists:test_profiles,id'],
            'element_ids'          => ['nullable', 'array'],
            'element_ids.*'        => ['exists:elements,id'],
        ]);

        DB::transaction(function () use ($sample, $data) {
            $sample->update(collect($data)->except('element_ids')->all());

            // Elementlar faqat yuborilgan bo'lsa (yoki profil tanlangan bo'lsa) o'zgaradi
            if (! empty($data['element_ids']) || ! empty($data['test_profile_id'])) {
                $this->syncElements($sample, $this->resolveElementIds($data));

                if ($sample->status !== 'archived') {
                    $sample->unsetRelation('sampleElements');
                    $sample->refreshStatus();
                }
            }
        });

        $this->logActivity('updated', $sample, $data, "Proba yangilandi: {$sample->code}");

        return response()->json($this->show($sample));
    }

    /** Qo'lda tanlangan elementlar, bo'lmasa — profil elementlari (TZ 2.10) */
    private function resolveElementIds(array $data): array
    {
        $elementIds = $data['element_ids'] ?? [];
        if (empty($elementIds) && ! empty($data['test_profile_id'])) {
            $profile = \App\Models\TestProfile::with('elements')->find($data['test_profile_id']);
            $elementIds = $profile ? $profile->elements->pluck('id')->all() : [];
        }

        return array_values(array_unique(array_map('intval', $elementIds)));
    }

    /**
     * Probaning aniqlanadigan elementlarini berilgan ro'yxatga moslaydi.
     * Yangilari qo'shiladi; ro'yxatdan chiqarilganlari faqat natija kiritilmagan
     * bo'lsa o'chiriladi — natijasi bor element hech qachon yo'qolmaydi.
     */
    private function syncElements(Sample $sample, array $elementIds): void
    {
        $existing = $sample->sampleElements()->get()->keyBy('element_id');

        foreach ($existing as $elementId => $sampleElement) {
            if (! in_array((int) $elementId, $elementIds, true)
                && $sampleElement->status !== 'done'
                && $sampleElement->result_value === null) {
                $sampleElement->delete();
            }
        }

        foreach ($elementIds as $elementId) {
            if ($existing->has($elementId)) {
                continue;
            }
            $element = \App\Models\Element::find($elementId);
            $sample->sampleElements()->create([
                'element_id'  => $elementId,
                'result_unit' => $element?->default_unit,
                'status'      => 'pending',
            ]);
        }
    }

    // Probani analitik guruhga uzatish (Спектральная / Химическая)
    public function assign(Request $request, Sample $sample)
    {
        $data = $request->validate([
            'assigned_group_id' => ['required', 'exists:laboratory_groups,id'],
        ]);

        $sample->update([
            'assigned_group_id' => $data['assigned_group_id'],
            'status'            => 'assigned',
            'assigned_at'       => now(),
        ]);

        $this->logActivity('updated', $sample, $data, "Proba guruhga uzatildi");

        return response()->json($this->show($sample));
    }

    // Element bo'yicha natija kiritish (Analitik lab)
    public function saveResult(Request $request, Sample $sample, SampleElement $sampleElement, EquipmentUsageService $usage)
    {
        abort_if($sampleElement->sample_id !== $sample->id, 404);

        // Guruh tekshiruvi: o'zga guruh probasiga natija kiritib bo'lmaydi
        $this->assertOwnGroup($sample);

        $data = $request->validate([
            'result_value' => ['required', 'numeric'],
            'result_unit'  => ['nullable', 'string', 'max:50'],
            'equipment_id' => ['nullable', 'exists:equipment,id'],
            'method'       => ['nullable', 'string', 'max:191'],
            'notes'        => ['nullable', 'string'],
        ]);

        // TZ 1.7 + 1.8 — uskuna yaroqliligini tekshirib, foydalanishni qayd etish
        $equipment = null;
        if (! empty($data['equipment_id'])) {
            $equipment = Equipment::findOrFail($data['equipment_id']);
            $usage->assertUsable($equipment);
        }

        $sampleElement->update([
            'result_value'      => $data['result_value'],
            'result_unit'       => $data['result_unit'] ?? $sampleElement->result_unit,
            'equipment_id'      => $data['equipment_id'] ?? null,
            'method'            => $data['method'] ?? null,
            'status'            => 'done',
            'result_entered_by' => auth()->id(),
            'result_entered_at' => now(), // avtomatik sana/vaqt
            'notes'             => $data['notes'] ?? null,
        ]);

        if ($equipment) {
            $usage->register($equipment, $sample, "Proba {$sample->code}, element: "
                . ($sampleElement->element->symbol ?? $sampleElement->element_id));
        }

        // Natija kiritilsa, proba avtomatik ishga olingan hisoblanadi
        if ($sample->status === 'assigned' || ! $sample->started_at) {
            $sample->status     = 'in_progress';
            $sample->started_at = $sample->started_at ?: now();
            $sample->started_by = $sample->started_by ?: auth()->id();
            $sample->save();
        }
        $sample->refreshStatus();

        $this->logActivity('updated', $sampleElement, $data, "Natija kiritildi");

        return response()->json($this->show($sample->fresh()));
    }

    /**
     * TZ — "берут на работу": guruh probani ishga oladi.
     * Kim va qachon boshlagani qayd etiladi.
     */
    public function takeToWork(Sample $sample)
    {
        $this->assertOwnGroup($sample);

        if ($sample->started_at) {
            return response()->json(['message' => 'Proba allaqachon ishga olingan'], 422);
        }

        $sample->update([
            'status'     => 'in_progress',
            'started_at' => now(),
            'started_by' => auth()->id(),
        ]);

        $this->logActivity('updated', $sample, null, "Proba ishga olindi: {$sample->code}");

        return response()->json($this->show($sample));
    }

    // Probani arxivlash
    public function archive(Sample $sample)
    {
        $sample->update(['status' => 'archived']);
        $this->logActivity('updated', $sample, description: "Proba arxivlandi");

        return response()->json($this->show($sample));
    }

    public function destroy(Sample $sample)
    {
        $code = $sample->code;
        $sample->delete();
        $this->logActivity('deleted', $sample, description: "Proba o'chirildi: {$code}");

        return response()->json(['message' => 'Proba o\'chirildi']);
    }

    /**
     * Analitik faqat o'z guruhiga biriktirilgan proba bilan ishlaydi.
     * samples.view_all ruxsati borlar uchun cheklov yo'q.
     */
    private function assertOwnGroup(Sample $sample): void
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->hasPermission('samples.view_all')) {
            return;
        }

        if ($user->laboratory_group_id && $sample->assigned_group_id !== $user->laboratory_group_id) {
            abort(403, 'Bu proba sizning guruhingizga biriktirilmagan');
        }
    }

    // Noyob shifr generatsiyasi: PR-YYYYMM-NNNN
    private function generateCode(): string
    {
        $prefix = 'PR-' . now()->format('Ym') . '-';
        // withTrashed() — o'chirilgan probalarni ham hisobga olib, takror shifr bermaydi
        $last = Sample::withTrashed()
            ->where('code', 'like', $prefix . '%')
            ->orderByDesc('code')->first();
        $num = $last ? ((int) substr($last->code, -4)) + 1 : 1;

        return $prefix . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }
}
