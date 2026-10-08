<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\TestObject;
use App\Models\TestProfile;
use Illuminate\Http\Request;

class TestObjectController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = TestObject::query()->with(['laboratory', 'elements', 'creator', 'profile']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('identifier', 'like', "%$search%")
                  ->orWhere('name', 'like', "%$search%")
                  ->orWhere('code', 'like', "%$search%")
                  ->orWhere('batch', 'like', "%$search%");
            });
        }
        foreach (['type', 'status', 'laboratory_id'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->input($f));
            }
        }
        // Saqlash muddati tugayotganlar
        if ($request->boolean('expiring_soon')) {
            $query->whereNotNull('storage_expiry')
                  ->whereDate('storage_expiry', '<=', now()->addDays(14));
        }

        $query->orderBy($request->input('sort_by', 'created_at'), $request->input('sort_dir', 'desc'));

        return $query->paginate($request->input('per_page', 15));
    }

    public function show(TestObject $testObject)
    {
        return $testObject->load(['laboratory', 'elements', 'creator', 'profile', 'documents.uploader:id,name']);
    }

    public function store(Request $request)
    {
        // Qo'lda identifier kiritilsa, o'chirilganda band emasligini tekshirish
        if ($request->filled('identifier')) {
            $trashed = TestObject::onlyTrashed()->where('identifier', $request->input('identifier'))->first();
            if ($trashed) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'identifier' => ["Bu identifikator o'chirilgan obyektda ishlatilgan (#{$trashed->id})."],
                ]);
            }
        }

        $data = $this->validateData($request);
        $data['created_by'] = auth()->id();
        if (empty($data['identifier'])) {
            $data['identifier'] = $this->generateIdentifier();
        }

        $obj = TestObject::create($data);

        // TZ 2.10 — elementlar qo'lda berilmasa, profildan yoki obyekt turidan olinadi
        $elementIds = $data['element_ids'] ?? $this->resolveElements($data);
        $obj->elements()->sync($elementIds);
        $this->logActivity('created', $obj, $data, "Sinov obyekti qo'shildi: {$obj->identifier}");

        return response()->json($this->show($obj), 201);
    }

    public function update(Request $request, TestObject $testObject)
    {
        $data = $this->validateData($request, $testObject->id);
        $testObject->update($data);
        if (array_key_exists('element_ids', $data)) {
            $testObject->elements()->sync($data['element_ids'] ?? []);
        } elseif (! empty($data['test_profile_id'])) {
            $testObject->elements()->sync($this->resolveElements($data));
        }
        $this->logActivity('updated', $testObject, $data, "Sinov obyekti yangilandi");

        return response()->json($this->show($testObject));
    }

    public function destroy(TestObject $testObject)
    {
        $id = $testObject->identifier;
        $testObject->delete();
        $this->logActivity('deleted', $testObject, description: "Sinov obyekti o'chirildi: {$id}");

        return response()->json(['message' => 'O\'chirildi']);
    }

    // Utilizatsiya (TZ 2.14)
    public function dispose(Request $request, TestObject $testObject)
    {
        $data = $request->validate([
            'disposal_act' => ['nullable', 'string', 'max:191'],
            'disposed_at'  => ['nullable', 'date'],
        ]);
        $testObject->update([
            'status'       => 'disposed',
            'disposed_at'  => $data['disposed_at'] ?? now(),
            'disposal_act' => $data['disposal_act'] ?? null,
        ]);
        $this->logActivity('updated', $testObject, $data, "Obyekt utilizatsiya qilindi");

        return response()->json($this->show($testObject));
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'identifier'               => ['nullable', 'string', 'max:191', 'unique:test_objects,identifier' . ($id ? ",$id" : '')],
            'type'                     => ['required', 'in:raw_material,product,water,air,biomaterial,other'],
            'name'                     => ['required', 'string', 'max:191'],
            'code'                     => ['nullable', 'string', 'max:191'],
            'batch'                    => ['nullable', 'string', 'max:191'],
            'description'              => ['nullable', 'string'],
            'physical_characteristics' => ['nullable', 'string'],
            'chemical_composition'     => ['nullable', 'string'],
            'source_supplier'          => ['nullable', 'string', 'max:191'],
            'received_at'              => ['nullable', 'date'],
            'storage_conditions'       => ['nullable', 'string'],
            'transport_conditions'     => ['nullable', 'string'],
            'accompanying_documents'   => ['nullable', 'string'],
            'status'                   => ['nullable', 'in:new,in_testing,tested,defect,disposed'],
            'storage_location'         => ['nullable', 'string', 'max:191'],
            'storage_expiry'           => ['nullable', 'date'],
            'laboratory_id'            => ['nullable', 'exists:laboratories,id'],
            'test_profile_id'          => ['nullable', 'exists:test_profiles,id'],
            'element_ids'              => ['nullable', 'array'],
            'element_ids.*'            => ['exists:elements,id'],
        ]);
    }

    /**
     * TZ 2.10 — sinov turlarini profildan yoki obyekt kategoriyasidan aniqlash.
     */
    private function resolveElements(array $data): array
    {
        // 1. Aniq profil ko'rsatilgan bo'lsa
        if (! empty($data['test_profile_id'])) {
            $profile = TestProfile::with('elements')->find($data['test_profile_id']);
            if ($profile) {
                return $profile->elements->pluck('id')->all();
            }
        }

        // 2. Obyekt turi bo'yicha standart profil
        if (! empty($data['type'])) {
            $profile = TestProfile::with('elements')
                ->where('object_type', $data['type'])
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();
            if ($profile) {
                return $profile->elements->pluck('id')->all();
            }
        }

        return [];
    }

    private function generateIdentifier(): string
    {
        $prefix = 'OB-' . now()->format('Ym') . '-';
        // withTrashed() — o'chirilgan obyektlarni ham hisobga oladi
        $last = TestObject::withTrashed()->where('identifier', 'like', $prefix . '%')->orderByDesc('identifier')->first();
        $num = $last ? ((int) substr($last->identifier, -4)) + 1 : 1;

        return $prefix . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }
}
