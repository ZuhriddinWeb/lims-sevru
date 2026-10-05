<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\TestProfile;
use Illuminate\Http\Request;

/**
 * Sinov profillari — TZ 2.10: obyekt kategoriyasiga qarab
 * aniqlanadigan elementlar to'plamini avtomatik belgilash.
 */
class TestProfileController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = TestProfile::query()->with(['elements', 'laboratory']);

        if ($request->filled('object_type')) {
            $query->where(function ($q) use ($request) {
                $q->where('object_type', $request->input('object_type'))
                  ->orWhereNull('object_type');
            });
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%$search%")
                  ->orWhere('name_ru', 'like', "%$search%")
                  ->orWhere('name_uz', 'like', "%$search%");
            });
        }
        if ($request->boolean('all')) {
            return $query->where('is_active', true)->orderBy('sort_order')->get();
        }

        return $query->orderBy('sort_order')->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $profile = TestProfile::create($data);
        $profile->elements()->sync($data['element_ids'] ?? []);
        $this->ensureSingleDefault($profile);

        $this->logActivity('created', $profile, null, "Sinov profili qo'shildi: {$profile->code}");

        return response()->json($profile->load('elements'), 201);
    }

    public function update(Request $request, TestProfile $testProfile)
    {
        $data = $this->validateData($request, $testProfile->id);
        $testProfile->update($data);
        if (array_key_exists('element_ids', $data)) {
            $testProfile->elements()->sync($data['element_ids'] ?? []);
        }
        $this->ensureSingleDefault($testProfile);

        $this->logActivity('updated', $testProfile, null, "Sinov profili yangilandi: {$testProfile->code}");

        return response()->json($testProfile->load('elements'));
    }

    public function destroy(TestProfile $testProfile)
    {
        $code = $testProfile->code;
        $testProfile->delete();
        $this->logActivity('deleted', $testProfile, null, "Sinov profili o'chirildi: {$code}");

        return response()->json(['message' => 'O\'chirildi']);
    }

    // Bir obyekt turiga faqat bitta standart profil bo'lishi mumkin
    private function ensureSingleDefault(TestProfile $profile): void
    {
        if ($profile->is_default && $profile->object_type) {
            TestProfile::where('object_type', $profile->object_type)
                ->where('id', '!=', $profile->id)
                ->update(['is_default' => false]);
        }
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code'          => ['required', 'string', 'max:50', 'unique:test_profiles,code' . ($id ? ",$id" : '')],
            'name_ru'       => ['required', 'string', 'max:191'],
            'name_uz'       => ['required', 'string', 'max:191'],
            'name_en'       => ['required', 'string', 'max:191'],
            'object_type'   => ['nullable', 'in:raw_material,product,water,air,biomaterial,other'],
            'laboratory_id' => ['nullable', 'exists:laboratories,id'],
            'is_default'    => ['nullable', 'boolean'],
            'is_active'     => ['nullable', 'boolean'],
            'sort_order'    => ['nullable', 'integer'],
            'element_ids'   => ['nullable', 'array'],
            'element_ids.*' => ['exists:elements,id'],
        ]);
    }
}
