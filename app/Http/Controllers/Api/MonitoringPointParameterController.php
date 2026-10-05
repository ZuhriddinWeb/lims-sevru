<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\MonitoringPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring nuqtasining standart ko'rsatkichlari va me'yor chegaralari.
 * Suv buyurtmasiga natija kiritishda shu ro'yxat avtomatik yuklanadi.
 */
class MonitoringPointParameterController extends Controller
{
    use LogsActivity;

    public function index(MonitoringPoint $monitoringPoint)
    {
        return $monitoringPoint->parameters;
    }

    public function sync(Request $request, MonitoringPoint $monitoringPoint)
    {
        $data = $request->validate([
            'parameters'             => ['present', 'array'],
            'parameters.*.parameter' => ['required', 'string', 'max:191'],
            'parameters.*.unit'      => ['nullable', 'string', 'max:50'],
            'parameters.*.norm_min'  => ['nullable', 'numeric'],
            'parameters.*.norm_max'  => ['nullable', 'numeric'],
        ]);

        DB::transaction(function () use ($monitoringPoint, $data) {
            $monitoringPoint->parameters()->delete();
            foreach ($data['parameters'] as $i => $p) {
                $monitoringPoint->parameters()->create([
                    'parameter'  => $p['parameter'],
                    'unit'       => $p['unit'] ?? null,
                    'norm_min'   => $p['norm_min'] ?? null,
                    'norm_max'   => $p['norm_max'] ?? null,
                    'sort_order' => $i,
                ]);
            }
        });

        $this->logActivity('updated', $monitoringPoint, null, 'Nuqta ko\'rsatkichlari yangilandi');

        return response()->json($monitoringPoint->parameters()->get());
    }
}
