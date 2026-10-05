<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Foydalanish: ->middleware('permission:equipment.view')
     * Bir nechta ruxsat: ->middleware('permission:equipment.view|equipment.create')  (OR)
     */
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Avtorizatsiya talab qilinadi'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Foydalanuvchi faol emas'], 403);
        }

        // Admin barcha ruxsatlarga ega
        if ($user->isAdmin()) {
            return $next($request);
        }

        $required = explode('|', $permissions);

        foreach ($required as $permission) {
            if ($user->hasPermission(trim($permission))) {
                return $next($request);
            }
        }

        return response()->json([
            'message'  => 'Sizda bu amal uchun ruxsat yo\'q',
            'required' => $required,
        ], 403);
    }
}
