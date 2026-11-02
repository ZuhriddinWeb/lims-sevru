<?php

namespace App\Http\Controllers\Api\Svodka;

use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\Element;
use App\Models\Sample;
use App\Models\SvodkaAssignment;
use App\Models\SvodkaPoint;
use App\Models\SvodkaRelease;
use App\Models\SvodkaSection;
use App\Services\Samples\CustomerPortal;
use App\Services\Samples\SampleRequests;
use App\Services\Svodka\SvodkaExcel;
use App\Services\Svodka\SvodkaReport;
use App\Services\Svodka\SvodkaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Buyurtmachi svodkasi — FAQAT buyurtmachi (portal foydalanuvchisi). [Paket 29]
 * Laboratoriya xodimi (hatto «ko'rib chiqish» rejimida ham) bu yerga kira olmaydi: nuqta nomlari
 * laboratoriyaga ko'rinmasligi kerak (ko'r tahlil).
 *
 *   GET  /api/portal/svodka/context                     bo'limlar, sozlama, joriy navbatchilik
 *   GET  /api/portal/svodka/spravochnik?department_id=  bloklar va nuqtalar
 *   POST/PUT/DELETE /api/portal/svodka/sections[/id], /points[/id], POST /points/bulk
 *   GET  /api/portal/svodka/day?department_id=&date=    bir kunlik svodka (faqat chiqarilgan qiymatlar)
 *   PUT  /api/portal/svodka/assign                      namunani nuqtaga bog'lash (chiqarilguncha)
 *   GET  /api/portal/svodka/export?department_id=&from=&to=   Excel
 *   GET  /api/portal/svodka/release/{id}                xabarnomadan: qaysi kun
 */
class PortalSvodkaController extends Controller
{
    public function __construct(
        private CustomerPortal $portal,
        private SvodkaService $svc,
        private SvodkaReport $report,
    ) {
    }

    /** @return array{ids:int[], write:bool, viewer:bool} */
    private function ctx(Request $request): array
    {
        $user = $request->user();
        abort_unless($this->svc->available(), 404, "Svodka moduli o'rnatilmagan");
        if ($this->portal->isPortalUser($user)) {
            return [
                'ids'    => $this->portal->linkedDepartments($user)->pluck('id')->map(fn ($v) => (int) $v)->all(),
                'write'  => $user->hasPermission(SampleRequests::PORTAL_PERMISSION),
                'viewer' => false,
            ];
        }
        // [P31] admin «Svodkani ko'ruvchilar»ga qo'shgan xodim (yoki rolida svodka.view) — buyurtmachidek ko'radi,
        // lekin hech narsani o'zgartirmaydi. Bo'limlarga biriktirilgan bo'lsa — o'sha bo'limlar, aks holda barcha faol bo'limlar.
        abort_unless($this->svc->canView($user), 403,
            "Svodka faqat buyurtmachi bo'limiga va admin svodkani ko'rishga ruxsat bergan xodimlarga ochiq");
        $linked = $this->portal->linkedDepartments($user)->pluck('id')->map(fn ($v) => (int) $v)->all();

        return [
            'ids'    => $linked ?: CustomerDepartment::where('is_active', true)->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'write'  => false,
            'viewer' => true,
        ];
    }

    public const VIEW_PERMISSION = 'svodka.view';

    private function dept(Request $request, array $ctx, bool $mustBeEnabled = false): int
    {
        $id = (int) $request->input('department_id');
        if (! $id && count($ctx['ids']) === 1) {
            $id = $ctx['ids'][0];
        }
        abort_unless($id && in_array($id, $ctx['ids'], true), 403, "Bu bo'lim sizga biriktirilmagan");
        if ($mustBeEnabled) {
            abort_unless($this->svc->enabled($id), 422, "Bu bo'lim uchun svodka laboratoriya tomonidan yoqilmagan");
        }

        return $id;
    }

    private function writer(array $ctx): void
    {
        abort_unless($ctx['write'], 403, "Spravochnikni tahrirlash uchun ruxsat yo'q");
    }

    // ------------------------------------------------------------------ kontekst

    public function context(Request $request)
    {
        $ctx = $this->ctx($request);
        $depts = CustomerDepartment::whereIn('id', $ctx['ids'] ?: [0])->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);

        return response()->json([
            'can_write'   => $ctx['write'],
            'viewer'      => $ctx['viewer'], // [P31] xodim — faqat ko'rish
            'departments' => $depts->map(function ($d) {
                $s = $this->svc->settings((int) $d->id);
                [$date, $shift] = SvodkaService::periodOf(now(), $s);

                return [
                    'id' => (int) $d->id, 'code' => $d->code, 'name' => $d->name,
                    'enabled'      => (bool) $s->enabled,
                    'release_mode' => $s->release_mode,
                    'shift1_label' => $s->shift1_label, 'shift2_label' => $s->shift2_label,
                    'shift1_hours' => $s->shift1_start . '–' . $s->shift2_start,
                    'shift2_hours' => $s->shift2_start . '–' . $s->shift1_start,
                    'current'      => ['date' => $date, 'shift' => $shift],
                ];
            })->values(),
        ]);
    }

    // ------------------------------------------------------------------ spravochnik

    public function spravochnik(Request $request)
    {
        $ctx = $this->ctx($request);
        $deptId = $this->dept($request, $ctx);

        return response()->json($this->spravochnikData($deptId) + ['can_write' => $ctx['write']]);
    }

    private function spravochnikData(int $deptId): array
    {
        $sections = SvodkaSection::where('customer_department_id', $deptId)->with('points')->orderBy('sort_order')->orderBy('id')->get();
        $used = $this->usedPointIds($deptId);

        return [
            'department_id' => $deptId,
            'sections' => $sections->map(fn ($s) => [
                'id' => (int) $s->id, 'title' => $s->title, 'layout' => $s->layout, 'sort_order' => (int) $s->sort_order,
                'is_active' => (bool) $s->is_active,
                'columns' => collect($s->columns ?? [])->map(fn ($c) => ['element_id' => (int) $c['element_id'], 'label' => $c['label'] ?? null])->values(),
                'points' => $s->points->map(fn ($p) => [
                    'id' => (int) $p->id, 'name' => $p->name, 'sort_order' => (int) $p->sort_order,
                    'daily' => (bool) $p->daily, 'is_active' => (bool) $p->is_active, 'used' => isset($used[(int) $p->id]),
                ])->values(),
            ])->values(),
            'elements' => Element::where('is_active', true)->orderBy('sort_order')->orderBy('symbol')
                ->get(['id', 'symbol', 'name_uz', 'name_ru', 'name_en', 'default_unit'])
                ->map(fn ($e) => ['id' => (int) $e->id, 'symbol' => $e->symbol, 'name_uz' => $e->name_uz, 'name_ru' => $e->name_ru,
                    'name_en' => $e->name_en, 'default_unit' => $e->default_unit])->values(),
        ];
    }

    /** @return array<int, true> */
    private function usedPointIds(int $deptId): array
    {
        $a = SvodkaAssignment::where('customer_department_id', $deptId)->distinct()->pluck('point_id')->all();
        $b = DB::table('sample_request_items')->join('sample_requests', 'sample_requests.id', '=', 'sample_request_items.sample_request_id')
            ->where('sample_requests.customer_department_id', $deptId)->whereNotNull('sample_request_items.svodka_point_id')
            ->distinct()->pluck('sample_request_items.svodka_point_id')->all();

        return array_fill_keys(array_map('intval', array_merge($a, $b)), true);
    }

    private function sectionRules(): array
    {
        return [
            'title'                => ['required', 'string', 'max:150'],
            'layout'               => ['required', Rule::in(SvodkaSection::LAYOUTS)],
            'columns'              => ['present', 'array', 'max:12'],
            'columns.*.element_id' => ['required', 'integer', Rule::exists('elements', 'id')],
            'columns.*.label'      => ['nullable', 'string', 'max:40'],
            'sort_order'           => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active'            => ['nullable', 'boolean'],
        ];
    }

    private function cleanColumns(array $cols): array
    {
        $seen = [];
        $out = [];
        foreach ($cols as $c) {
            $id = (int) $c['element_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = ['element_id' => $id, 'label' => ($l = trim((string) ($c['label'] ?? ''))) === '' ? null : $l];
        }

        return $out;
    }

    public function storeSection(Request $request)
    {
        $ctx = $this->ctx($request);
        $this->writer($ctx);
        $deptId = $this->dept($request, $ctx);
        $data = $request->validate($this->sectionRules());
        SvodkaSection::create([
            'customer_department_id' => $deptId,
            'title'      => trim($data['title']),
            'layout'     => $data['layout'],
            'columns'    => $this->cleanColumns($data['columns']),
            'sort_order' => $data['sort_order'] ?? ((int) SvodkaSection::where('customer_department_id', $deptId)->max('sort_order') + 1),
            'is_active'  => $data['is_active'] ?? true,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json($this->spravochnikData($deptId) + ['can_write' => true], 201);
    }

    public function updateSection(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $this->writer($ctx);
        $sec = SvodkaSection::whereIn('customer_department_id', $ctx['ids'] ?: [0])->findOrFail($id);
        $data = $request->validate($this->sectionRules());
        $sec->update([
            'title' => trim($data['title']), 'layout' => $data['layout'], 'columns' => $this->cleanColumns($data['columns']),
            'sort_order' => $data['sort_order'] ?? $sec->sort_order, 'is_active' => $data['is_active'] ?? $sec->is_active,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json($this->spravochnikData((int) $sec->customer_department_id) + ['can_write' => true]);
    }

    /** Ishlatilgan bo'lsa — o'chirilmaydi, nofaol qilinadi (eski svodkalarda nomi qolishi uchun) */
    public function destroySection(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $this->writer($ctx);
        $sec = SvodkaSection::whereIn('customer_department_id', $ctx['ids'] ?: [0])->with('points')->findOrFail($id);
        $deptId = (int) $sec->customer_department_id;
        $used = $this->usedPointIds($deptId);
        if ($sec->points->contains(fn ($p) => isset($used[(int) $p->id]))) {
            $sec->update(['is_active' => false]);
            $mode = 'deactivated';
        } else {
            SvodkaPoint::where('section_id', $sec->id)->delete();
            $sec->delete();
            $mode = 'deleted';
        }

        return response()->json($this->spravochnikData($deptId) + ['can_write' => true, 'result' => $mode]);
    }

    private function pointSection(array $ctx, int $sectionId): SvodkaSection
    {
        return SvodkaSection::whereIn('customer_department_id', $ctx['ids'] ?: [0])->findOrFail($sectionId);
    }

    public function storePoint(Request $request)
    {
        $ctx = $this->ctx($request);
        $this->writer($ctx);
        $data = $request->validate([
            'section_id' => ['required', 'integer'],
            'name'       => ['required', 'string', 'max:150'],
            'daily'      => ['nullable', 'boolean'],
        ]);
        $sec = $this->pointSection($ctx, (int) $data['section_id']);
        SvodkaPoint::create([
            'customer_department_id' => $sec->customer_department_id, 'section_id' => $sec->id, 'name' => trim($data['name']),
            'daily' => (bool) ($data['daily'] ?? false), 'is_active' => true,
            'sort_order' => (int) SvodkaPoint::where('section_id', $sec->id)->max('sort_order') + 1,
        ]);

        return response()->json($this->spravochnikData((int) $sec->customer_department_id) + ['can_write' => true], 201);
    }

    /** Ro'yxatdan qo'shish: har qatorda bitta nom */
    public function bulkPoints(Request $request)
    {
        $ctx = $this->ctx($request);
        $this->writer($ctx);
        $data = $request->validate([
            'section_id' => ['required', 'integer'],
            'names'      => ['required', 'string', 'max:20000'],
        ]);
        $sec = $this->pointSection($ctx, (int) $data['section_id']);
        $existing = SvodkaPoint::where('section_id', $sec->id)->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $sort = (int) SvodkaPoint::where('section_id', $sec->id)->max('sort_order');
        $added = 0;
        foreach (preg_split('/\r\n|\r|\n/', $data['names']) as $line) {
            $name = trim(preg_replace('/^\s*\d+[\.\)]\s+/u', '', $line));   // «1. Nom» → «Nom»
            if ($name === '' || in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            $existing[] = mb_strtolower($name);
            SvodkaPoint::create([
                'customer_department_id' => $sec->customer_department_id, 'section_id' => $sec->id,
                'name' => mb_substr($name, 0, 150), 'daily' => (bool) preg_match('/^sut[\.\s]/iu', $name),
                'is_active' => true, 'sort_order' => ++$sort,
            ]);
            $added++;
        }

        return response()->json($this->spravochnikData((int) $sec->customer_department_id) + ['can_write' => true, 'added' => $added], 201);
    }

    public function updatePoint(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $this->writer($ctx);
        $p = SvodkaPoint::whereIn('customer_department_id', $ctx['ids'] ?: [0])->findOrFail($id);
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:150'],
            'daily'      => ['nullable', 'boolean'],
            'is_active'  => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'section_id' => ['nullable', 'integer'],
        ]);
        $sectionId = $p->section_id;
        if (! empty($data['section_id']) && (int) $data['section_id'] !== (int) $p->section_id) {
            $sectionId = $this->pointSection($ctx, (int) $data['section_id'])->id;
        }
        $p->update([
            'name' => trim($data['name']), 'daily' => (bool) ($data['daily'] ?? $p->daily),
            'is_active' => $data['is_active'] ?? $p->is_active, 'sort_order' => $data['sort_order'] ?? $p->sort_order,
            'section_id' => $sectionId,
        ]);

        return response()->json($this->spravochnikData((int) $p->customer_department_id) + ['can_write' => true]);
    }

    public function destroyPoint(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $this->writer($ctx);
        $p = SvodkaPoint::whereIn('customer_department_id', $ctx['ids'] ?: [0])->findOrFail($id);
        $deptId = (int) $p->customer_department_id;
        if (isset($this->usedPointIds($deptId)[(int) $p->id])) {
            $p->update(['is_active' => false]);
            $mode = 'deactivated';
        } else {
            $p->delete();
            $mode = 'deleted';
        }

        return response()->json($this->spravochnikData($deptId) + ['can_write' => true, 'result' => $mode]);
    }

    // ------------------------------------------------------------------ svodka

    public function day(Request $request)
    {
        $ctx = $this->ctx($request);
        $deptId = $this->dept($request, $ctx, true);
        $date = $request->validate(['date' => ['required', 'date']])['date'];

        return response()->json($this->report->day($deptId, $date) + ['can_write' => $ctx['write']]);
    }

    /** Namunani nuqtaga bog'lash / davrini o'zgartirish. Chiqarilgan namuna — o'zgartirib bo'lmaydi. */
    public function assign(Request $request)
    {
        $ctx = $this->ctx($request);
        $this->writer($ctx);
        $data = $request->validate([
            'sample_id' => ['required', 'integer'],
            'point_id'  => ['nullable', 'integer'],
            'date'      => ['required', 'date'],
            'shift'     => ['required', Rule::in([1, 2])],
        ]);
        $sample = Sample::with('sampleElements:id,sample_id,element_id,result_value,result_unit,status')->find((int) $data['sample_id']);
        abort_unless($sample && in_array((int) $sample->customer_department_id, $ctx['ids'], true), 404, 'Namuna topilmadi');
        $deptId = (int) $sample->customer_department_id;
        abort_unless($this->svc->enabled($deptId), 422, "Bu bo'lim uchun svodka yoqilmagan");
        if (! empty($data['point_id']) && ! SvodkaPoint::where('id', $data['point_id'])->where('customer_department_id', $deptId)->exists()) {
            throw ValidationException::withMessages(['point_id' => ['Nuqta topilmadi']]);
        }

        $gate = $this->svc->gate(collect([$sample]))[(int) $sample->id] ?? null;
        if ($gate && $gate['released']) {
            throw ValidationException::withMessages(['sample_id' => ["Bu namuna laboratoriya tomonidan chiqarilgan svodkada — bog'lanishni o'zgartirib bo'lmaydi"]]);
        }

        $date = Carbon::parse($data['date'])->toDateString();
        if (empty($data['point_id'])) {
            SvodkaAssignment::where('sample_id', $sample->id)->delete();
        } else {
            SvodkaAssignment::updateOrCreate(['sample_id' => $sample->id], [
                'customer_department_id' => $deptId, 'point_id' => (int) $data['point_id'],
                'production_date' => $date, 'shift' => (int) $data['shift'], 'assigned_by' => $request->user()->id,
            ]);
        }

        return response()->json($this->report->day($deptId, $date) + ['can_write' => true]);
    }

    public function export(Request $request)
    {
        $ctx = $this->ctx($request);
        $deptId = $this->dept($request, $ctx, true);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();
        abort_if($from->diffInDays($to) > 62, 422, "Bir faylda ko'pi bilan 62 kun — davrni qisqartiring");
        abort_unless(\App\Services\Svodka\SvodkaXlsx::available(), 500, 'Serverda PHP zip kengaytmasi yoqilmagan');

        $dept = CustomerDepartment::find($deptId);
        $path = app(SvodkaExcel::class)->build($deptId, $from->toDateString(), $to->toDateString(), $dept?->name ?? '');
        $name = 'svodka_' . ($dept?->code ?: $deptId) . '_' . $from->format('Y-m-d') . ($from->eq($to) ? '' : '_' . $to->format('Y-m-d'));

        return response()->download($path, preg_replace('/[^\w\-\.]+/u', '_', $name) . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function release(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        $rel = SvodkaRelease::whereIn('customer_department_id', $ctx['ids'] ?: [0])->findOrFail($id);

        return response()->json([
            'department_id' => (int) $rel->customer_department_id,
            'date' => $rel->production_date->toDateString(),
            'shift' => $rel->shift,
            'revision' => (int) $rel->revision,
        ]);
    }
}

