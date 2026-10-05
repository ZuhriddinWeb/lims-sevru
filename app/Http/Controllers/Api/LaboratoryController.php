<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Laboratory;
use Illuminate\Http\Request;

class LaboratoryController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = Laboratory::query()->with('groups')->withCount('equipment');
        if ($request->boolean('all')) {
            return $query->where('is_active', true)->orderBy('sort_order')->get();
        }
        if ($search = $request->input('search')) {
            $query->where('name_ru', 'like', "%$search%")
                  ->orWhere('name_uz', 'like', "%$search%")
                  ->orWhere('code', 'like', "%$search%");
        }
        return $query->orderBy('sort_order')->paginate($request->input('per_page', 15));
    }

    public function show(Laboratory $laboratory)
    {
        return $laboratory->load('groups');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $lab = Laboratory::create($data);
        $this->logActivity('created', $lab, $data, "Laboratoriya qo'shildi");
        return response()->json($lab, 201);
    }

    public function update(Request $request, Laboratory $laboratory)
    {
        $data = $this->validateData($request, $laboratory->id);
        $laboratory->update($data);
        $this->logActivity('updated', $laboratory, $data, "Laboratoriya yangilandi");
        return response()->json($laboratory);
    }

    public function destroy(Laboratory $laboratory)
    {
        $laboratory->delete();
        $this->logActivity('deleted', $laboratory, description: "Laboratoriya o'chirildi");
        return response()->json(['message' => 'O\'chirildi']);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code'        => ['required', 'string', 'max:50', 'unique:laboratories,code' . ($id ? ",$id" : '')],
            'name_ru'     => ['required', 'string', 'max:191'],
            'name_uz'     => ['required', 'string', 'max:191'],
            'name_en'     => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer'],
        ]);
    }
}
