<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Controller;
use App\Services\Insights\WorkDesk;
use Illuminate\Http\Request;

/**
 * Ish stoli (bosh sahifa) — GET /api/insights/desk
 * Kartochkalar foydalanuvchining ruxsatlariga qarab tanlanadi (WorkDesk ga qarang).
 */
class WorkDeskController extends Controller
{
    public function index(Request $request, WorkDesk $desk)
    {
        return response()->json($desk->build($request->user()));
    }
}
