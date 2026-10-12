<?php

namespace App\Http\Controllers\Api\Interlab;

use App\Services\Interlab\InterlabException;
use Illuminate\Http\JsonResponse;

trait HandlesInterlabErrors
{
    /** Modul xatolarini foydalanuvchiga tushunarli JSON qilib qaytaradi */
    protected function attempt(callable $callback): JsonResponse
    {
        try {
            $result = $callback();

            return $result instanceof JsonResponse ? $result : response()->json($result);
        } catch (InterlabException $e) {
            $status = $e->status() >= 400 && $e->status() < 600 ? $e->status() : 422;

            // 401/403 — UI ularni «sessiya tugadi / ruxsat yo'q» deb tushunadi; modul xatosi esa oddiy xabar
            return response()->json(['message' => $e->getMessage()], in_array($status, [401, 403], true) ? 422 : $status);
        }
    }
}
