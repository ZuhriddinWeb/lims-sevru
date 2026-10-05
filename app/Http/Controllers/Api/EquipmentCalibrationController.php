<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentCalibration;
use Illuminate\Http\Request;

class EquipmentCalibrationController extends Controller
{
    use LogsActivity;

    public function index(Equipment $equipment)
    {
        return $equipment->calibrations()->paginate(request('per_page', 15));
    }

    public function store(Request $request, Equipment $equipment)
    {
        $data = $request->validate([
            'type'               => ['required', 'in:calibration,verification'],
            'performed_at'       => ['required', 'date'],
            'performed_by'       => ['nullable', 'string', 'max:191'],
            'result'             => ['required', 'in:passed,failed'],
            'certificate_number' => ['nullable', 'string', 'max:191'],
            'valid_until'        => ['nullable', 'date'],
            'notes'              => ['nullable', 'string'],
        ]);
        $data['created_by'] = auth()->id();

        $calibration = $equipment->calibrations()->create($data);

        // Uskunaning kalibrovka sanalarini yangilash
        $equipment->last_calibration_at = $data['performed_at'];
        if (! empty($data['valid_until'])) {
            $equipment->next_calibration_at = $data['valid_until'];
        } elseif ($equipment->calibration_interval_days) {
            $equipment->next_calibration_at = \Carbon\Carbon::parse($data['performed_at'])
                ->addDays($equipment->calibration_interval_days);
        }
        // Agar o'tmagan bo'lsa — bloklash (TZ 1.7)
        if ($data['result'] === 'failed') {
            $equipment->is_blocked = true;
            $equipment->block_reason = 'Kalibrovkadan o\'tmadi';
            $equipment->status = 'awaiting_calibration';
        } else {
            $equipment->is_blocked = false;
            $equipment->block_reason = null;
            if ($equipment->status === 'awaiting_calibration') {
                $equipment->status = 'working';
            }
        }
        $equipment->save();

        $this->logActivity('created', $calibration, $data, "Kalibrovka qo'shildi");

        return response()->json($calibration, 201);
    }

    public function update(Request $request, Equipment $equipment, EquipmentCalibration $calibration)
    {
        $data = $request->validate([
            'type'               => ['required', 'in:calibration,verification'],
            'performed_at'       => ['required', 'date'],
            'performed_by'       => ['nullable', 'string', 'max:191'],
            'result'             => ['required', 'in:passed,failed'],
            'certificate_number' => ['nullable', 'string', 'max:191'],
            'valid_until'        => ['nullable', 'date'],
            'notes'              => ['nullable', 'string'],
        ]);
        $calibration->update($data);
        $this->logActivity('updated', $calibration, $data, "Kalibrovka yangilandi");

        return response()->json($calibration);
    }

    public function destroy(Equipment $equipment, EquipmentCalibration $calibration)
    {
        $calibration->delete();
        $this->logActivity('deleted', $calibration, description: "Kalibrovka o'chirildi");

        return response()->json(['message' => 'O\'chirildi']);
    }
}
