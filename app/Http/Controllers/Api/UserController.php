<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = User::query()->with(['role', 'laboratory', 'laboratoryGroup']);
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('username', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%")
                  ->orWhere('phone', 'like', "%$search%");
            });
        }
        foreach (['role_id', 'laboratory_id'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->input($f));
            }
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        return $query->orderByDesc('created_at')->paginate($request->input('per_page', 15));
    }

    public function show(User $user)
    {
        return $user->load(['role', 'laboratory', 'laboratoryGroup']);
    }

    public function store(Request $request)
    {
        // O'chirilgan foydalanuvchida login/email band emasligini tekshirish
        foreach (['username', 'email'] as $field) {
            if ($request->filled($field)) {
                $trashed = User::onlyTrashed()->where($field, $request->input($field))->first();
                if ($trashed) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        $field => ["Bu qiymat o'chirilgan foydalanuvchida ishlatilgan ({$trashed->name}). "
                            . "Administrator orqali eski hisobni tiklang yoki boshqa qiymat kiriting."],
                    ]);
                }
            }
        }

        $data = $request->validate([
            'name'                => ['required', 'string', 'max:191'],
            'username'            => ['required', 'string', 'max:191', 'unique:users,username'],
            'email'               => ['nullable', 'email', 'unique:users,email'],
            'phone'               => ['nullable', 'string', 'max:50'],
            'position'            => ['nullable', 'string', 'max:191'],
            'password'            => ['required', 'string', 'min:6'],
            'role_id'             => ['nullable', 'exists:roles,id'],
            'laboratory_id'       => ['nullable', 'exists:laboratories,id'],
            'laboratory_group_id' => ['nullable', 'exists:laboratory_groups,id'],
            'is_active'           => ['nullable', 'boolean'],
        ]);
        $user = User::create($data);
        $this->logActivity('created', $user, \Illuminate\Support\Arr::except($data, ['password']), "Foydalanuvchi qo'shildi: {$user->username}");
        return response()->json($this->show($user), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'                => ['required', 'string', 'max:191'],
            'username'            => ['required', 'string', 'max:191', 'unique:users,username,' . $user->id],
            'email'               => ['nullable', 'email', 'unique:users,email,' . $user->id],
            'phone'               => ['nullable', 'string', 'max:50'],
            'position'            => ['nullable', 'string', 'max:191'],
            'password'            => ['nullable', 'string', 'min:6'],
            'role_id'             => ['nullable', 'exists:roles,id'],
            'laboratory_id'       => ['nullable', 'exists:laboratories,id'],
            'laboratory_group_id' => ['nullable', 'exists:laboratory_groups,id'],
            'is_active'           => ['nullable', 'boolean'],
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $this->logActivity('updated', $user, \Illuminate\Support\Arr::except($data, ['password']), "Foydalanuvchi yangilandi: {$user->username}");
        return response()->json($this->show($user));
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'O\'zingizni o\'chira olmaysiz'], 422);
        }
        $u = $user->username;
        $user->delete();
        $this->logActivity('deleted', $user, description: "Foydalanuvchi o'chirildi: {$u}");
        return response()->json(['message' => 'O\'chirildi']);
    }
}
