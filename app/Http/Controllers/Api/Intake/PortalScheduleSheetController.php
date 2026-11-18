<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\ScheduleSheet;
use App\Services\Samples\CustomerPortal;
use App\Services\Samples\ScheduleSheets;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * [Paket 37] Buyurtmachi — «Grafik jadvali» (faqat ko'rish va Excel; faqat o'z bo'linmasi).
 *
 *   GET /api/portal/schedule-sheets
 *   GET /api/portal/schedule-sheets/{id}?date=
 *   GET /api/portal/schedule-sheets/{id}/export?date=
 *
 * Natija ustunlarida jamlanma hisobot qoidasi (SvodkaGate) — chiqarilmagan natija ko'rinmaydi.
 */
class PortalScheduleSheetController extends Controller
{
    public function __construct(private CustomerPortal $portal, private ScheduleSheets $sheets)
    {
    }

    /** null — barcha bo'linmalar (portalni ko'rish huquqi bor xodim) */
    private function ids(Request $request): ?array
    {
        abort_unless(ScheduleSheets::available(), 503, "Grafik jadvallari yo'q — php artisan migrate");
        $user = $request->user();
        if ($this->portal->isPortalUser($user)) {
            return $this->portal->linkedDepartments($user)->pluck('id')->map(fn ($v) => (int) $v)->all();
        }
        abort_unless($this->portal->canPreview($user), 403, "Buyurtmachi portalini ko'rish uchun ruxsat yo'q");

        return null;
    }

    private function find(Request $request, int $id): ScheduleSheet
    {
        $ids = $this->ids($request);
        $s = ScheduleSheet::where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('customer_department_id', $ids ?: [0]))
            ->find($id);
        abort_unless($s, 404, 'Jadval topilmadi');

        return $s;
    }

    private function date(Request $request): string
    {
        try {
            return Carbon::parse($request->query('date') ?: now())->toDateString();
        } catch (\Throwable $e) {
            return now()->toDateString();
        }
    }

    public function index(Request $request)
    {
        $ids = $this->ids($request);
        $rows = ScheduleSheet::where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('customer_department_id', $ids ?: [0]))
            ->orderBy('sort_order')->orderBy('name')->get();

        return ['data' => $rows->map(fn ($s) => $this->sheets->presentSheet($s, false))->values()];
    }

    public function show(Request $request, int $id)
    {
        return $this->sheets->grid($this->find($request, $id), $this->date($request), true, false);
    }

    public function export(Request $request, int $id)
    {
        $sheet = $this->find($request, $id);

        return ScheduleSheetController::download($this->sheets, $sheet, $this->sheets->grid($sheet, $this->date($request), true, false));
    }
}
