<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\WaterOrder;
use App\Models\WaterOrderResult;
use App\Services\EquipmentUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WaterOrderController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = WaterOrder::query()->with([
            'assignedGroup', 'monitoringPoint', 'registeredBy', 'results',
        ])->withCount('results');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%$search%")
                  ->orWhere('source_document', 'like', "%$search%")
                  ->orWhere('division', 'like', "%$search%");
            });
        }
        foreach (['status', 'assigned_group_id', 'monitoring_point_id', 'period_month'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->input($f));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('registered_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('registered_at', '<=', $request->input('date_to'));
        }

        // Guruhlar faqat o'ziga taqsimlangan buyurtmalarni ko'radi
        $user = auth()->user();
        if (! $user->isAdmin() && ! $user->hasPermission('water_orders.view_all') && $user->laboratory_group_id) {
            $query->where('assigned_group_id', $user->laboratory_group_id);
        }

        $query->orderBy($request->input('sort_by', 'registered_at'), $request->input('sort_dir', 'desc'));

        return $query->paginate($request->input('per_page', 15));
    }

    public function show(WaterOrder $waterOrder)
    {
        return $waterOrder->load([
            'assignedGroup', 'monitoringPoint', 'registeredBy', 'starter',
            'results.equipment:id,name,serial_number',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'source_document'     => ['nullable', 'string', 'max:191'],
            'division'            => ['nullable', 'string', 'max:191'],
            'assigned_group_id'   => ['nullable', 'exists:laboratory_groups,id'],
            'monitoring_point_id' => ['nullable', 'exists:monitoring_points,id'],
            'copy_point_parameters' => ['nullable', 'boolean'],
            'notes'               => ['nullable', 'string'],
        ]);

        $copyParams = $data['copy_point_parameters'] ?? true;
        unset($data['copy_point_parameters']);

        $now = now();
        $data['order_number']  = $this->generateNumber();
        $data['status']        = 'registered';
        $data['period_month']  = $now->format('Y-m');
        $data['registered_by'] = auth()->id();
        $data['registered_at'] = $now;

        $order = WaterOrder::create($data);

        // TZ — buyurtma qabul qilinayotganda qanday aniqlashlar kerakligi belgilanadi.
        // Monitoring nuqtasiga biriktirilgan ko'rsatkichlar me'yorlari bilan ko'chiriladi.
        if ($copyParams && $order->monitoring_point_id) {
            $point = \App\Models\MonitoringPoint::with('parameters')->find($order->monitoring_point_id);
            foreach ($point?->parameters ?? [] as $p) {
                $order->results()->create([
                    'parameter'   => $p->parameter,
                    'unit'        => $p->unit,
                    'norm_min'    => $p->norm_min,
                    'norm_max'    => $p->norm_max,
                    'assessment'  => 'unknown',
                    'value'       => null,
                    'entered_by'  => null,
                ]);
            }
        }

        $this->logActivity('created', $order, $data, "Suv buyurtmasi: {$order->order_number}");

        return response()->json($this->show($order), 201);
    }

    public function update(Request $request, WaterOrder $waterOrder)
    {
        $data = $request->validate([
            'source_document'     => ['nullable', 'string', 'max:191'],
            'division'            => ['nullable', 'string', 'max:191'],
            'assigned_group_id'   => ['nullable', 'exists:laboratory_groups,id'],
            'monitoring_point_id' => ['nullable', 'exists:monitoring_points,id'],
            'status'              => ['nullable', 'in:registered,in_progress,completed,archived'],
            'notes'               => ['nullable', 'string'],
        ]);
        $waterOrder->update($data);
        $this->logActivity('updated', $waterOrder, $data, "Suv buyurtmasi yangilandi");

        return response()->json($this->show($waterOrder));
    }

    public function destroy(WaterOrder $waterOrder)
    {
        $n = $waterOrder->order_number;
        $waterOrder->delete();
        $this->logActivity('deleted', $waterOrder, description: "Suv buyurtmasi o'chirildi: {$n}");

        return response()->json(['message' => 'O\'chirildi']);
    }

    // Natijalarni saqlash (bir nechta parametr)
    public function saveResults(Request $request, WaterOrder $waterOrder, EquipmentUsageService $usage)
    {
        $data = $request->validate([
            'results'                 => ['required', 'array', 'min:1'],
            'results.*.parameter'     => ['required', 'string', 'max:191'],
            'results.*.value'         => ['nullable', 'numeric'],
            'results.*.unit'          => ['nullable', 'string', 'max:50'],
            'results.*.equipment_id'  => ['nullable', 'exists:equipment,id'],
            'results.*.norm_min'      => ['nullable', 'numeric'],
            'results.*.norm_max'      => ['nullable', 'numeric'],
            'results.*.measured_at'   => ['nullable', 'date'],
        ]);

        // TZ 1.7 — avval barcha uskunalarni tekshiramiz, keyin saqlaymiz
        $equipmentIds = collect($data['results'])->pluck('equipment_id')->filter()->unique();
        $equipmentList = Equipment::whereIn('id', $equipmentIds)->get()->keyBy('id');
        foreach ($equipmentList as $eq) {
            $usage->assertUsable($eq);
        }

        DB::transaction(function () use ($waterOrder, $data) {
            $waterOrder->results()->delete();
            foreach ($data['results'] as $r) {
                $result = new WaterOrderResult([
                    'parameter'    => $r['parameter'],
                    'value'        => $r['value'] ?? null,
                    'unit'         => $r['unit'] ?? null,
                    'equipment_id' => $r['equipment_id'] ?? null,
                    'norm_min'     => $r['norm_min'] ?? null,
                    'norm_max'     => $r['norm_max'] ?? null,
                    'measured_at'  => $r['measured_at'] ?? now(),
                    'entered_by'   => auth()->id(),
                ]);
                $result->assessment = $result->evaluateAssessment();
                $waterOrder->results()->save($result);
            }
            // Barcha ko'rsatkichlar to'ldirilgandagina "Yakunlangan"
            $filled = collect($data['results'])->filter(fn ($r) => ($r['value'] ?? null) !== null)->count();
            $total  = count($data['results']);

            $waterOrder->update([
                'status'       => $filled >= $total && $total > 0 ? 'completed' : 'in_progress',
                'completed_at' => $filled >= $total && $total > 0 ? now() : null,
                'started_at'   => $waterOrder->started_at ?: now(),
                'started_by'   => $waterOrder->started_by ?: auth()->id(),
            ]);
        });

        // TZ 1.8 — har bir ishlatilgan uskunani qayd etish
        foreach ($equipmentList as $eq) {
            $usage->register($eq, $waterOrder, "Buyurtma {$waterOrder->order_number}");
        }

        $this->logActivity('updated', $waterOrder, description: "Suv natijalari kiritildi");

        return response()->json($this->show($waterOrder->fresh()));
    }

    /**
     * TZ — buyurtmani ishga olish.
     */
    public function takeToWork(WaterOrder $waterOrder)
    {
        if ($waterOrder->started_at) {
            return response()->json(['message' => 'Buyurtma allaqachon ishga olingan'], 422);
        }

        $waterOrder->update([
            'status'     => 'in_progress',
            'started_at' => now(),
            'started_by' => auth()->id(),
        ]);

        $this->logActivity('updated', $waterOrder, null, "Buyurtma ishga olindi: {$waterOrder->order_number}");

        return response()->json($this->show($waterOrder));
    }

    /**
     * Buyurtmani arxivga o'tkazish.
     */
    public function archive(WaterOrder $waterOrder)
    {
        $waterOrder->update(['status' => 'archived']);

        $this->logActivity('updated', $waterOrder, null, "Buyurtma arxivlandi: {$waterOrder->order_number}");

        return response()->json($this->show($waterOrder));
    }

    private function generateNumber(): string
    {
        $prefix = 'WO-' . now()->format('Ym') . '-';
        $last = WaterOrder::where('order_number', 'like', $prefix . '%')->orderByDesc('order_number')->first();
        $num = $last ? ((int) substr($last->order_number, -4)) + 1 : 1;

        return $prefix . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }
}
