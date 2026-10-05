<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Element;
use Illuminate\Http\Request;

class ElementController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = Element::query();
        if ($search = $request->input('search')) {
            $query->where('symbol', 'like', "%$search%")
                  ->orWhere('name_ru', 'like', "%$search%")
                  ->orWhere('name_uz', 'like', "%$search%")
                  ->orWhere('name_en', 'like', "%$search%");
        }
        if ($request->boolean('all')) {
            return $query->where('is_active', true)->orderBy('sort_order')->get();
        }
        return $query->orderBy('sort_order')->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $element = Element::create($data);
        $this->logActivity('created', $element, $data, "Element qo'shildi");
        return response()->json($element, 201);
    }

    public function update(Request $request, Element $element)
    {
        $data = $this->validateData($request);
        $element->update($data);
        $this->logActivity('updated', $element, $data, "Element yangilandi");
        return response()->json($element);
    }

    public function destroy(Element $element)
    {
        $element->delete();
        $this->logActivity('deleted', $element, description: "Element o'chirildi");
        return response()->json(['message' => 'O\'chirildi']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'symbol'       => ['nullable', 'string', 'max:20'],
            'name_ru'      => ['required', 'string', 'max:191'],
            'name_uz'      => ['required', 'string', 'max:191'],
            'name_en'      => ['required', 'string', 'max:191'],
            'default_unit' => ['nullable', 'string', 'max:50'],
            'is_active'    => ['nullable', 'boolean'],
            'sort_order'   => ['nullable', 'integer'],
        ]);
    }
}
