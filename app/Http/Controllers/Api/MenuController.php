<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    // Foydalanuvchining ruxsatlariga mos sidebar menyusi
    public function index(Request $request)
    {
        $user = $request->user();
        $items = MenuItem::where('is_active', true)
            ->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();

        $filter = function ($item) use ($user) {
            if (! $item->permission) {
                return true;
            }
            return $user->hasPermission($item->permission);
        };

        $menu = $items->filter(function ($item) use ($filter, $user) {
            // Agar bolalari bo'lsa, kamida bittasiga ruxsat bo'lsa ko'rsat
            if ($item->children->isNotEmpty()) {
                $item->setRelation('children', $item->children->filter($filter)->values());
                return $item->children->isNotEmpty();
            }
            return $filter($item);
        })->values();

        return response()->json($menu);
    }

    // Menyu CRUD (admin uchun)
    public function all()
    {
        return MenuItem::orderBy('sort_order')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        return response()->json(MenuItem::create($data), 201);
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $data = $this->validateData($request, $menuItem->id);
        $menuItem->update($data);
        return response()->json($menuItem);
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();
        return response()->json(['message' => 'O\'chirildi']);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'key'        => ['required', 'string', 'max:50', 'unique:menu_items,key' . ($id ? ",$id" : '')],
            'title_ru'   => ['required', 'string', 'max:191'],
            'title_uz'   => ['required', 'string', 'max:191'],
            'title_en'   => ['required', 'string', 'max:191'],
            'icon'       => ['nullable', 'string', 'max:50'],
            'route'      => ['nullable', 'string', 'max:191'],
            'permission' => ['nullable', 'string', 'max:191'],
            'parent_id'  => ['nullable', 'exists:menu_items,id'],
            'sort_order' => ['nullable', 'integer'],
            'is_active'  => ['nullable', 'boolean'],
        ]);
    }
}
