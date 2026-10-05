<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentMaintenance;
use Illuminate\Http\Request;

class EquipmentMaintenanceController extends Controller
{
    use LogsActivity;

    public function index(Equipment $equipment)
    {
        return $equipment->maintenances()->paginate(request('per_page', 15));
    }

    public function store(Request $request, Equipment $equipment)
    {
        $data = $this->validateData($request);
        $data['created_by'] = auth()->id();

        $maintenance = $equipment->maintenances()->create($data);

        // Bajarilgan bo'lsa — uskuna sanalarini yangilash
        if ($data['status'] === 'done' && ! empty($data['performed_at'])) {
            $equipment->last_maintenance_at = $data['performed_at'];
            if ($equipment->maintenance_interval_days) {
                $equipment->next_maintenance_at = \Carbon\Carbon::parse($data['performed_at'])
                    ->addDays($equipment->maintenance_interval_days);
            }
            if ($equipment->status === 'maintenance') {
                $equipment->status = 'working';
            }
            $equipment->save();
        } elseif ($data['status'] === 'in_progress') {
            $equipment->update(['status' => 'maintenance']);
        }

        $this->logActivity('created', $maintenance, $data, "Texnik xizmat qo'shildi");

        return response()->json($maintenance, 201);
    }

    public function update(Request $request, Equipment $equipment, EquipmentMaintenance $maintenance)
    {
        $data = $this->validateData($request);
        $maintenance->update($data);
        $this->logActivity('updated', $maintenance, $data, "Texnik xizmat yangilandi");

        return response()->json($maintenance);
    }

    public function destroy(Equipment $equipment, EquipmentMaintenance $maintenance)
    {
        $maintenance->delete();
        $this->logActivity('deleted', $maintenance, description: "Texnik xizmat o'chirildi");

        return response()->json(['message' => 'O\'chirildi']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'type'                => ['required', 'in:scheduled,repair,component_replacement'],
            'scheduled_at'        => ['nullable', 'date'],
            'performed_at'        => ['nullable', 'date'],
            'performed_by'        => ['nullable', 'string', 'max:191'],
            'description'         => ['nullable', 'string'],
            'components_replaced' => ['nullable', 'string'],
            'cost'                => ['nullable', 'numeric', 'min:0'],
            'status'              => ['required', 'in:planned,in_progress,done,cancelled'],
        ]);
    }
}
