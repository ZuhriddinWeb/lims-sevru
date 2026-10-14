<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Controller;
use App\Services\Insights\ExecutiveInsights;
use App\Services\Insights\InsightPeriod;
use App\Services\Insights\ShiftCalendar;
use Illuminate\Http\Request;

/**
 * Rahbariyat paneli 2.0 — GET /api/insights/executive
 *
 *   ?period=shift|prev_shift|today|yesterday|week|month|year|custom
 *   &date_from=YYYY-MM-DD&date_to=YYYY-MM-DD   (custom uchun)
 *   &laboratory_id= &group_id= &customer_department_id=
 *   &blocks=kpi,trend                          (ixtiyoriy — faqat shu bloklar)
 */
class ExecutiveInsightsController extends Controller
{
    // Xizmatlar har so'rovda yangidan olinadi (controller nusxasi uzoq yashovchi jarayonda saqlanib qolishi mumkin)
    public function index(Request $request, ExecutiveInsights $insights, ShiftCalendar $calendar)
    {
        $request->validate([
            'period'                 => ['nullable', 'string', 'in:' . implode(',', InsightPeriod::PRESETS)],
            'date_from'              => ['nullable', 'date'],
            'date_to'                => ['nullable', 'date'],
            'laboratory_id'          => ['nullable', 'integer'],
            'group_id'               => ['nullable', 'integer'],
            'customer_department_id' => ['nullable', 'integer'],
            'blocks'                 => ['nullable', 'string', 'max:200'],
        ]);

        $period = InsightPeriod::fromRequest($request, $calendar);
        $only = $request->filled('blocks')
            ? array_values(array_intersect(array_map('trim', explode(',', (string) $request->input('blocks'))), ExecutiveInsights::BLOCKS))
            : null;

        return response()->json($insights->build($period, $request->only(['laboratory_id', 'group_id', 'customer_department_id']), $only));
    }
}
