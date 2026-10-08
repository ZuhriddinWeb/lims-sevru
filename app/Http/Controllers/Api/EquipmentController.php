<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use Illuminate\Http\Request;

class EquipmentController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = Equipment::query()
            ->with(['laboratory', 'group', 'creator']);

        // Qidiruv
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('model', 'like', "%$search%")
                  ->orWhere('serial_number', 'like', "%$search%")
                  ->orWhere('inventory_number', 'like', "%$search%");
            });
        }

        // Filtrlar
        if ($request->filled('laboratory_id')) {
            $query->where('laboratory_id', $request->input('laboratory_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->boolean('only_blocked')) {
            $query->where('is_blocked', true);
        }
        // Yaqinlashayotgan kalibrovka (kun ichida)
        if ($request->filled('calibration_within_days')) {
            $days = (int) $request->input('calibration_within_days');
            $query->whereNotNull('next_calibration_at')
                  ->whereDate('next_calibration_at', '<=', now()->addDays($days));
        }

        // Sinovga yaroqli uskunalar (natija kiritish formasi uchun)
        if ($request->boolean('usable')) {
            $query->where('is_blocked', false)
                  ->whereIn('status', ['working', 'maintenance'])
                  ->where(function ($q) {
                      $q->whereNull('next_calibration_at')
                        ->orWhereDate('next_calibration_at', '>=', now());
                  });
        }

        // Barcha yozuvlarni qaytarish (tanlov ro'yxatlari uchun)
        if ($request->boolean('all')) {
            return $query->orderBy('name')->limit(500)->get(['id', 'name', 'model', 'serial_number', 'laboratory_id', 'laboratory_group_id', 'status', 'is_blocked']);
        }

        // Saralash
        $sortBy  = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($request->input('per_page', 15));
    }

    public function show(Equipment $equipment)
    {
        return $equipment->load([
            'laboratory', 'group', 'creator',
            'calibrations', 'maintenances', 'documents',
        ]);
    }

    public function store(Request $request)
    {
        // O'chirilgan uskunada shu serial raqam bormi — aniq xabar berish
        $this->checkSoftDeleted($request->input('serial_number'));

        $data = $this->validateData($request);
        $data['created_by'] = auth()->id();

        $equipment = Equipment::create($data);
        $this->logActivity('created', $equipment, $data, "Uskuna qo'shildi: {$equipment->name}");

        return response()->json($equipment->load(['laboratory', 'group']), 201);
    }

    public function update(Request $request, Equipment $equipment)
    {
        $data = $this->validateData($request, $equipment->id);
        $equipment->update($data);

        $this->logActivity('updated', $equipment, $data, "Uskuna yangilandi: {$equipment->name}");

        return response()->json($equipment->load(['laboratory', 'group']));
    }

    public function destroy(Equipment $equipment)
    {
        $name = $equipment->name;
        $equipment->delete();

        $this->logActivity('deleted', $equipment, description: "Uskuna o'chirildi: {$name}");

        return response()->json(['message' => 'Uskuna o\'chirildi']);
    }

    // Holatni o'zgartirish — TZ 1.6
    public function changeStatus(Request $request, Equipment $equipment)
    {
        $data = $request->validate([
            'status' => ['required', 'in:working,maintenance,out_of_service,awaiting_calibration'],
        ]);
        $equipment->update($data);
        $this->logActivity('updated', $equipment, $data, "Holat o'zgartirildi: {$data['status']}");

        return response()->json($equipment);
    }

    // Bloklash / blokdan chiqarish — TZ 1.7
    public function toggleBlock(Request $request, Equipment $equipment)
    {
        $data = $request->validate([
            'is_blocked'   => ['required', 'boolean'],
            'block_reason' => ['nullable', 'string', 'max:255'],
        ]);
        $equipment->update($data);
        $this->logActivity('updated', $equipment, $data,
            $data['is_blocked'] ? "Uskuna bloklandi" : "Uskuna blokdan chiqarildi");

        return response()->json($equipment);
    }

    /**
     * TZ 1.4 — uskuna bo'yicha barcha o'zgarishlar, ta'mirlashlar va foydalanishlar tarixi.
     */
    public function history(Equipment $equipment)
    {
        $events = collect();

        foreach ($equipment->calibrations as $c) {
            $events->push([
                'type'        => 'calibration',
                'date'        => $c->performed_at,
                'title'       => $c->type === 'verification' ? 'Tekshiruv' : 'Kalibrovka',
                'description' => trim(($c->performed_by ?? '') . ' ' . ($c->certificate_number ?? '')),
                'status'      => $c->result,
            ]);
        }

        foreach ($equipment->maintenances as $m) {
            $events->push([
                'type'        => 'maintenance',
                'date'        => $m->performed_at ?? $m->scheduled_at,
                'title'       => match ($m->type) {
                    'repair' => 'Ta\'mirlash',
                    'component_replacement' => 'Komponent almashtirish',
                    default => 'Rejali xizmat',
                },
                'description' => $m->components_replaced ?: $m->description,
                'status'      => $m->status,
            ]);
        }

        foreach ($equipment->usages()->with('user:id,name')->limit(100)->get() as $u) {
            $events->push([
                'type'        => 'usage',
                'date'        => $u->used_at,
                'title'       => 'Sinovda ishlatildi',
                'description' => $u->description,
                'status'      => null,
                'user'        => $u->user?->name,
            ]);
        }

        foreach (\App\Models\ActivityLog::where('model_type', 'Equipment')
            ->where('model_id', $equipment->id)->with('user:id,name')->latest('created_at')->limit(100)->get() as $log) {
            $events->push([
                'type'        => 'change',
                'date'        => $log->created_at,
                'title'       => $log->description ?? $log->action,
                'description' => null,
                'status'      => null,
                'user'        => $log->user?->name,
            ]);
        }

        return response()->json(
            $events->filter(fn ($e) => $e['date'])->sortByDesc('date')->values()
        );
    }

    /**
     * O'chirilgan uskunada shu serial raqam ishlatilganmi?
     * Agar ha — operatorga aniq xabar beradi (jim "band" o'rniga).
     */
    private function checkSoftDeleted(?string $serial): void
    {
        if (! $serial) {
            return;
        }
        $trashed = Equipment::onlyTrashed()->where('serial_number', $serial)->first();
        if ($trashed) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'serial_number' => ["Bu seriya raqami o'chirilgan uskunada ishlatilgan (#{$trashed->id}). "
                    . "Boshqa raqam kiriting yoki administrator orqali eski yozuvni tiklang/o'chiring."],
            ]);
        }
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'laboratory_id'                 => ['required', 'exists:laboratories,id'],
            'laboratory_group_id'           => ['nullable', 'exists:laboratory_groups,id'],
            'name'                          => ['required', 'string', 'max:191'],
            'model'                         => ['nullable', 'string', 'max:191'],
            'serial_number'                 => ['required', 'string', 'max:191', 'unique:equipment,serial_number' . ($id ? ",$id" : '')],
            'inventory_number'              => ['nullable', 'string', 'max:191'],
            'manufacturer'                  => ['nullable', 'string', 'max:191'],
            'supplier'                      => ['nullable', 'string', 'max:191'],
            'location'                      => ['nullable', 'string', 'max:191'],
            'commissioned_at'               => ['nullable', 'date'],
            'status'                        => ['nullable', 'in:working,maintenance,out_of_service,awaiting_calibration'],
            'is_blocked'                    => ['nullable', 'boolean'],
            'block_reason'                  => ['nullable', 'string', 'max:255'],
            'calibration_interval_days'     => ['nullable', 'integer', 'min:0'],
            'last_calibration_at'           => ['nullable', 'date'],
            'next_calibration_at'           => ['nullable', 'date'],
            'usage_count'                   => ['nullable', 'integer', 'min:0'],
            'max_usage_before_calibration'  => ['nullable', 'integer', 'min:0'],
            'maintenance_interval_days'     => ['nullable', 'integer', 'min:0'],
            'last_maintenance_at'           => ['nullable', 'date'],
            'next_maintenance_at'           => ['nullable', 'date'],
            'notes'                         => ['nullable', 'string'],
        ]);
    }
}
