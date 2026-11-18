<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\Element;
use App\Models\RequestSchedule;
use App\Models\ScheduleRun;
use App\Models\ScheduleSheet;
use App\Services\Insights\SimpleXlsx;
use App\Services\Samples\RequestSchedules;
use App\Services\Samples\ScheduleSheets;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * [Paket 37] Grafik jadvallari — laboratoriya, rahbariyat va admin.
 *
 *   GET    /api/schedule-sheets                         jadvallar ro'yxati            (schedule_sheets.view|enter)
 *   GET    /api/schedule-sheets/{id}?date=              kunlik jadval                  (schedule_sheets.view|enter)
 *   PUT    /api/schedule-sheets/{id}/cell               {run_id, key, value}           (schedule_sheets.enter)
 *   GET    /api/schedule-sheets/{id}/export?date=       Excel                          (schedule_sheets.view|enter)
 *
 *   GET    /api/schedule-sheet-templates                shablonlar (konstruktor)       (schedule_sheets.manage)
 *   GET    /api/schedule-sheet-templates/options        bo'linmalar, grafiklar, ko'rsatkichlar
 *   POST   /api/schedule-sheet-templates
 *   PUT    /api/schedule-sheet-templates/{id}
 *   DELETE /api/schedule-sheet-templates/{id}
 */
class ScheduleSheetController extends Controller
{
    public function __construct(private ScheduleSheets $sheets)
    {
    }

    private function ready(): void
    {
        abort_unless(ScheduleSheets::available(), 503, "Grafik jadvallari yo'q — php artisan migrate");
    }

    private function canEnter(Request $request): bool
    {
        $u = $request->user();

        return $u->isAdmin() || $u->hasPermission('schedule_sheets.enter');
    }

    private function date(Request $request): string
    {
        try {
            return Carbon::parse($request->query('date') ?: now())->toDateString();
        } catch (\Throwable $e) {
            return now()->toDateString();
        }
    }

    /* ================================================================ ko'rish / kiritish */

    public function index(Request $request)
    {
        $this->ready();
        $rows = ScheduleSheet::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        return [
            'data'      => $rows->map(fn ($s) => $this->sheets->presentSheet($s, false))->values(),
            'can_enter' => $this->canEnter($request),
            'can_manage'=> $request->user()->isAdmin() || $request->user()->hasPermission('schedule_sheets.manage'),
        ];
    }

    public function show(Request $request, int $id)
    {
        $this->ready();
        $sheet = ScheduleSheet::findOrFail($id);

        return $this->sheets->grid($sheet, $this->date($request), false, $this->canEnter($request));
    }

    public function cell(Request $request, int $id)
    {
        $this->ready();
        $sheet = ScheduleSheet::findOrFail($id);
        $data = $request->validate([
            'run_id' => 'required|integer',
            'key'    => 'required|string|max:40',
            'value'  => 'nullable',
        ]);
        $run = ScheduleRun::findOrFail((int) $data['run_id']);
        $this->sheets->setValue($sheet, $run, $data['key'], $data['value'] ?? null, $request->user());

        return $this->sheets->grid($sheet, Carbon::parse($run->run_date)->toDateString(), false, true);
    }

    public function export(Request $request, int $id)
    {
        $this->ready();
        $sheet = ScheduleSheet::findOrFail($id);

        return self::download($this->sheets, $sheet, $this->sheets->grid($sheet, $this->date($request)));
    }

    public static function download(ScheduleSheets $svc, ScheduleSheet $sheet, array $grid)
    {
        [$rows, $widths, $yellow] = $svc->xlsx($sheet, $grid);
        $name = preg_replace('/[^\pL\pN_-]+/u', '_', $sheet->name) . '_' . $grid['date'];
        if (SimpleXlsx::available()) {
            $path = (new SimpleXlsx())->sheet(mb_substr($grid['date'], 0, 31), $rows, $widths, false, $yellow)->save();

            return response()->download($path, $name . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $r) {
                fputcsv($out, $r, ';');
            }
            fclose($out);
        }, $name . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ================================================================ konstruktor (admin) */

    public function templates()
    {
        $this->ready();

        return ['data' => ScheduleSheet::orderBy('customer_department_id')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($s) => $this->sheets->presentSheet($s))->values()];
    }

    public function options()
    {
        $this->ready();
        $enabled = app(RequestSchedules::class)->enabledDepartmentIds();

        return [
            'departments' => CustomerDepartment::orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name, 'code' => $d->code, 'schedule_enabled' => in_array((int) $d->id, $enabled, true)])->values(),
            'schedules'   => RequestSchedule::orderBy('planned_time')->get(['id', 'customer_department_id', 'name', 'planned_time', 'weekdays', 'payload'])
                ->map(fn ($s) => [
                    'id' => (int) $s->id, 'department_id' => (int) $s->customer_department_id, 'name' => $s->name,
                    'time' => $s->planned_time, 'items' => count($s->payload['items'] ?? []),
                ])->values(),
            'elements'    => Element::where('is_active', true)->orderBy('sort_order')->orderBy('symbol')->get(['id', 'symbol', 'name_uz', 'name_ru', 'default_unit'])
                ->map(fn ($e) => ['id' => (int) $e->id, 'symbol' => $e->symbol, 'name' => $e->name_uz ?: $e->name_ru, 'unit' => $e->default_unit])->values(),
            'functions'   => \App\Services\Worksheets\Formula::FUNCTIONS,
        ];
    }

    public function store(Request $request)
    {
        $this->ready();

        return response()->json($this->sheets->presentSheet($this->save($request, new ScheduleSheet())), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->ready();

        return $this->sheets->presentSheet($this->save($request, ScheduleSheet::findOrFail($id)));
    }

    public function destroy(Request $request, int $id)
    {
        $this->ready();
        $s = ScheduleSheet::findOrFail($id);
        $s->forceFill(['updated_by' => $request->user()->id])->save();
        $s->delete();

        return response()->noContent();
    }

    private function save(Request $request, ScheduleSheet $sheet): ScheduleSheet
    {
        $data = $request->validate([
            'department_id'  => 'required|integer|exists:customer_departments,id',
            'name'           => 'required|string|max:150',
            'schedule_ids'   => 'nullable|array',
            'schedule_ids.*' => 'integer',
            'columns'        => 'required|array|max:' . ScheduleSheets::MAX_COLUMNS,
            'is_active'      => 'boolean',
            'sort_order'     => 'nullable|integer|min:0|max:9999',
        ]);
        [$cols, $sched] = $this->sheets->cleanDefinition((int) $data['department_id'], $data['columns'], $data['schedule_ids'] ?? []);
        $sheet->fill([
            'customer_department_id' => (int) $data['department_id'],
            'name'                   => trim($data['name']),
            'schedule_ids'           => $sched,
            'columns'                => $cols,
            'is_active'              => $data['is_active'] ?? true,
            'sort_order'             => (int) ($data['sort_order'] ?? 0),
        ]);
        if (! $sheet->exists) {
            $sheet->created_by = $request->user()->id;
        }
        $sheet->updated_by = $request->user()->id;
        $sheet->save();

        return $sheet;
    }
}
