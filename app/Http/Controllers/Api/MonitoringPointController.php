<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\MonitoringPoint;
use Illuminate\Http\Request;

class MonitoringPointController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = MonitoringPoint::query()->with('group');
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%$search%")->orWhere('code', 'like', "%$search%");
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        return $query->orderBy('name')->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $point = MonitoringPoint::create($data);
        $this->logActivity('created', $point, $data, "Monitoring nuqtasi qo'shildi");
        return response()->json($point->load('group'), 201);
    }

    public function update(Request $request, MonitoringPoint $monitoringPoint)
    {
        $data = $this->validateData($request, $monitoringPoint->id);
        $monitoringPoint->update($data);
        $this->logActivity('updated', $monitoringPoint, $data, "Monitoring nuqtasi yangilandi");
        return response()->json($monitoringPoint->load('group'));
    }

    public function destroy(MonitoringPoint $monitoringPoint)
    {
        $monitoringPoint->delete();
        $this->logActivity('deleted', $monitoringPoint, description: "Monitoring nuqtasi o'chirildi");
        return response()->json(['message' => 'O\'chirildi']);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code'                => ['required', 'string', 'max:191', 'unique:monitoring_points,code' . ($id ? ",$id" : '')],
            'name'                => ['required', 'string', 'max:191'],
            'type'                => ['required', 'in:water,air,radiation'],
            'location'            => ['nullable', 'string', 'max:191'],
            'latitude'            => ['nullable', 'numeric'],
            'longitude'           => ['nullable', 'numeric'],
            'laboratory_group_id' => ['nullable', 'exists:laboratory_groups,id'],
            'is_active'           => ['nullable', 'boolean'],
        ]);
    }
}
