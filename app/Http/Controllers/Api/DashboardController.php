<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\Sample;
use App\Models\TestObject;
use App\Models\WaterOrder;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'cards' => [
                'equipment_total'       => Equipment::count(),
                'equipment_blocked'     => Equipment::where('is_blocked', true)->count(),
                'samples_total'         => Sample::count(),
                'samples_in_progress'   => Sample::whereIn('status', ['registered', 'assigned', 'in_progress'])->count(),
                'test_objects_total'    => TestObject::count(),
                'water_orders_total'    => WaterOrder::count(),
            ],

            // Yaqinlashayotgan kalibrovkalar (TZ 1.5 — eslatmalar)
            'calibration_reminders' => Equipment::with('laboratory')
                ->whereNotNull('next_calibration_at')
                ->whereDate('next_calibration_at', '<=', now()->addDays(30))
                ->orderBy('next_calibration_at')
                ->limit(10)
                ->get(['id', 'name', 'serial_number', 'laboratory_id', 'next_calibration_at', 'status', 'is_blocked']),

            // Saqlash muddati tugayotgan obyektlar (TZ 2.12)
            'expiring_objects' => TestObject::whereNotNull('storage_expiry')
                ->whereDate('storage_expiry', '<=', now()->addDays(14))
                ->where('status', '!=', 'disposed')
                ->orderBy('storage_expiry')
                ->limit(10)
                ->get(['id', 'identifier', 'name', 'storage_expiry', 'status']),

            // Oxirgi probalar
            'recent_samples' => Sample::with(['assignedGroup', 'customerGroup'])
                ->orderByDesc('registered_at')
                ->limit(8)
                ->get(),

            // Status bo'yicha taqsimot (grafik uchun)
            'samples_by_status' => Sample::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')->pluck('count', 'status'),

            'equipment_by_status' => Equipment::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')->pluck('count', 'status'),

            // Oylar bo'yicha probalar (so'nggi 6 oy)
            'samples_by_month' => Sample::selectRaw('period_month, COUNT(*) as count')
                ->groupBy('period_month')
                ->orderByDesc('period_month')
                ->limit(6)
                ->get()
                ->reverse()
                ->values(),
        ]);
    }
}
