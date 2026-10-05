<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\LaboratoryGroup;
use Illuminate\Http\Request;

class LaboratoryGroupController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = LaboratoryGroup::query()->with('laboratory');
        if ($request->filled('laboratory_id')) {
            $query->where('laboratory_id', $request->input('laboratory_id'));
        }
        if ($request->filled('kind')) {
            $query->whereIn('kind', [$request->input('kind'), 'both']);
        }
        if ($request->boolean('all')) {
            return $query->where('is_active', true)->orderBy('sort_order')->get();
        }
        return $query->orderBy('sort_order')->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $group = LaboratoryGroup::create($data);
        $this->logActivity('created', $group, $data, "Guruh qo'shildi");
        return response()->json($group->load('laboratory'), 201);
    }

    public function update(Request $request, LaboratoryGroup $laboratoryGroup)
    {
        $data = $this->validateData($request, $laboratoryGroup->id);
        $laboratoryGroup->update($data);
        $this->logActivity('updated', $laboratoryGroup, $data, "Guruh yangilandi");
        return response()->json($laboratoryGroup->load('laboratory'));
    }

    public function destroy(LaboratoryGroup $laboratoryGroup)
    {
        $laboratoryGroup->delete();
        $this->logActivity('deleted', $laboratoryGroup, description: "Guruh o'chirildi");
        return response()->json(['message' => 'O\'chirildi']);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'laboratory_id' => ['required', 'exists:laboratories,id'],
            'code'          => ['required', 'string', 'max:50', 'unique:laboratory_groups,code' . ($id ? ",$id" : '')],
            'name_ru'       => ['required', 'string', 'max:191'],
            'name_uz'       => ['required', 'string', 'max:191'],
            'name_en'       => ['required', 'string', 'max:191'],
            'kind'          => ['required', 'in:customer,executor,both'],
            'is_active'     => ['nullable', 'boolean'],
            'sort_order'    => ['nullable', 'integer'],
        ]);
    }
}
