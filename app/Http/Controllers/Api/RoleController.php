<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = Role::query()->with('permissions')->withCount('users');
        if ($request->boolean('all')) {
            return $query->get();
        }
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%$search%");
        }
        return $query->orderBy('id')->paginate($request->input('per_page', 15));
    }

    public function show(Role $role)
    {
        return $role->load('permissions');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $role = Role::create($data);
        if (isset($data['permission_ids'])) {
            $role->syncPermissions($data['permission_ids']);
        }
        $this->logActivity('created', $role, description: "Rol qo'shildi: {$role->name}");
        return response()->json($this->show($role), 201);
    }

    public function update(Request $request, Role $role)
    {
        $data = $this->validateData($request, $role->id);
        $role->update($data);
        if (isset($data['permission_ids'])) {
            $role->syncPermissions($data['permission_ids']);
        }
        $this->logActivity('updated', $role, description: "Rol yangilandi: {$role->name}");
        return response()->json($this->show($role));
    }

    public function destroy(Role $role)
    {
        if ($role->is_system) {
            return response()->json(['message' => 'Tizim rolini o\'chirib bo\'lmaydi'], 422);
        }
        $n = $role->name;
        $role->delete();
        $this->logActivity('deleted', $role, description: "Rol o'chirildi: {$n}");
        return response()->json(['message' => 'O\'chirildi']);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name'            => ['required', 'string', 'max:50', 'unique:roles,name' . ($id ? ",$id" : '')],
            'display_name_ru' => ['nullable', 'string', 'max:191'],
            'display_name_uz' => ['nullable', 'string', 'max:191'],
            'display_name_en' => ['nullable', 'string', 'max:191'],
            'description'     => ['nullable', 'string'],
            'permission_ids'  => ['nullable', 'array'],
            'permission_ids.*'=> ['exists:permissions,id'],
        ]);
    }
}
