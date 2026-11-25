<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\LaboratoryGroup;
use App\Models\TestProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Sinov profillari — TZ 2.10: obyekt kategoriyasiga qarab
 * aniqlanadigan elementlar to'plamini avtomatik belgilash.
 */
class TestProfileController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = TestProfile::query()->with(array_merge(['elements', 'laboratory'], $this->hasGroups() ? ['groups'] : []));

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
        $this->syncGroups($profile, $data);
        $this->ensureSingleDefault($profile);

        $this->logActivity('created', $profile, null, "Sinov profili qo'shildi: {$profile->code}");

        return response()->json($profile->load($this->relations()), 201);
    }

    public function update(Request $request, TestProfile $testProfile)
    {
        $data = $this->validateData($request, $testProfile->id);
        $testProfile->update($data);
        if (array_key_exists('element_ids', $data)) {
            $testProfile->elements()->sync($data['element_ids'] ?? []);
        }
        $this->syncGroups($testProfile, $data);
        $this->ensureSingleDefault($testProfile);

        $this->logActivity('updated', $testProfile, null, "Sinov profili yangilandi: {$testProfile->code}");

        return response()->json($testProfile->load($this->relations()));
    }

    public function destroy(TestProfile $testProfile)
    {
        $code = $testProfile->code;
        if ($this->hasGroups()) {
            $testProfile->groups()->detach();
        }
        $testProfile->delete();
        $this->logActivity('deleted', $testProfile, null, "Sinov profili o'chirildi: {$code}");

        return response()->json(['message' => 'O\'chirildi']);
    }

    private function hasGroups(): bool
    {
        return Schema::hasTable('test_profile_groups');
    }

    private function relations(): array
    {
        return $this->hasGroups() ? ['elements', 'groups'] : ['elements'];
    }

    /**
     * [P42] Mas'ul guruhlar — kelgan tartibda (1, 2, …). Laboratoriya tanlangan bo'lsa — faqat o'sha laboratoriya guruhlari.
     */
    private function syncGroups(TestProfile $profile, array $data): void
    {
        if (! $this->hasGroups() || ! array_key_exists('group_ids', $data)) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', (array) ($data['group_ids'] ?? []))));
        if ($ids && $profile->laboratory_id) {
            $foreign = LaboratoryGroup::whereIn('id', $ids)->where('laboratory_id', '!=', $profile->laboratory_id)->pluck('id')->all();
            if ($foreign) {
                throw ValidationException::withMessages(['group_ids' => ["Mas'ul guruh profil laboratoriyasiga tegishli bo'lishi kerak"]]);
            }
        }
        $sync = [];
        foreach ($ids as $i => $id) {
            $sync[$id] = ['sort_order' => $i + 1];
        }
        $profile->groups()->sync($sync);
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
            'group_ids'     => ['nullable', 'array', 'max:20'],          // [P42] mas'ul guruhlar (tartib bilan)
            'group_ids.*'   => ['integer', 'exists:laboratory_groups,id'],
        ]);
    }
}
