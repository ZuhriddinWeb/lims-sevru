<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use LogsActivity;

    // Tizimga kirish (SPA — Sanctum cookie)
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $data['username'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Login yoki parol noto\'g\'ri'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => ['Hisobingiz bloklangan. Administrator bilan bog\'laning.'],
            ]);
        }

        auth()->login($user, remember: true);
        $request->session()->regenerate();

        $this->logActivity('login', $user, description: 'Tizimga kirdi');

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request)
    {
        $this->logActivity('logout', $request->user(), description: 'Tizimdan chiqdi');

        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Tizimdan chiqildi']);
    }

    // Joriy foydalanuvchi + ruxsatlar + menular
    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    // Profil (ism, telefon, parol)
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:191'],
            'email'    => ['nullable', 'email', 'max:191'],
            'phone'    => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
        ]);

        $user->name  = $data['name'];
        $user->email = $data['email'] ?? $user->email;
        $user->phone = $data['phone'] ?? $user->phone;

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return response()->json(['user' => $this->userPayload($user)]);
    }

    // Til va mavzu (tun/kun) sozlamalarini saqlash
    public function updatePreferences(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'locale' => ['nullable', 'in:ru,uz,en'],
            'theme'  => ['nullable', 'in:light,dark'],
        ]);

        if (isset($data['locale'])) {
            $user->locale = $data['locale'];
        }
        if (isset($data['theme'])) {
            $user->theme = $data['theme'];
        }
        $user->save();

        return response()->json([
            'locale' => $user->locale,
            'theme'  => $user->theme,
        ]);
    }

    private function userPayload(User $user): array
    {
        $user->load(['role.permissions', 'laboratory', 'laboratoryGroup']);

        return [
            'id'                 => $user->id,
            'name'               => $user->name,
            'username'           => $user->username,
            'email'              => $user->email,
            'phone'              => $user->phone,
            'position'           => $user->position,
            'avatar'             => $user->avatar,
            'locale'             => $user->locale,
            'theme'              => $user->theme,
            'is_admin'           => $user->isAdmin(),
            'role'               => $user->role ? [
                'id'   => $user->role->id,
                'name' => $user->role->name,
                'display_name_ru' => $user->role->display_name_ru,
                'display_name_uz' => $user->role->display_name_uz,
                'display_name_en' => $user->role->display_name_en,
            ] : null,
            'laboratory'         => $user->laboratory,
            'laboratory_group'   => $user->laboratoryGroup,
            'permissions'        => $user->permissionNames(),
        ];
    }
}
