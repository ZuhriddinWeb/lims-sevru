<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;

class PermissionController extends Controller
{
    // Ruxsatlarni modul bo'yicha guruhlab qaytarish (rol tahrirlash sahifasi uchun)
    public function index()
    {
        $permissions = Permission::orderBy('module')->orderBy('sort_order')->get();

        $grouped = $permissions->groupBy('module')->map(function ($items, $module) {
            return [
                'module'      => $module,
                'permissions' => $items->values(),
            ];
        })->values();

        return response()->json($grouped);
    }
}
