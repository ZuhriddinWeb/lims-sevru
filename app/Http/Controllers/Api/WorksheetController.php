<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\Sample;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetScan;
use App\Models\WorksheetTemplate;
use App\Services\Worksheets\WorksheetService;
use App\Services\Worksheets\DisplayTotals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ishchi varaqlar — partiya: bitta varaqda bir yoki bir nechta proba (har biri o'z parallellari bilan).
 * Jarayon: shablon → probalar → chop etish → qog'ozda to'ldirish → kiritish (+ skan) → yakunlash → tasdiqlash.
 */
class WorksheetController extends Controller
{
    use LogsActivity;

    public function __construct(private WorksheetService $service)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Worksheet::query()
            ->with([
                'samples' => fn ($q) => $q->select('samples.id', 'samples.code', 'samples.sample_number', 'samples.assigned_group_id', 'samples.status')
                    ->orderBy('worksheet_samples.position'),
                'template:id,code,name_uz,name_ru,name_uz_cyrl',
                'creator:id,name',
            ])
            ->withCount(['scans', 'members']);

        $this->service->scopeVisible($query, $user);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('template_id')) {
            $query->where('template_id', $request->input('template_id'));
        }
        if ($request->filled('kind') && WorksheetService::hasKind()) {
            $query->where('kind', WorksheetService::normalizeKind($request->input('kind')));
        }
        if ($request->filled('sample_id')) {
            $sid = (int) $request->input('sample_id');
            $query->whereIn('id', fn ($sub) => $sub->select('worksheet_id')->from('worksheet_samples')->where('sample_id', $sid));
        }
        if ($request->boolean('warnings_only')) {
            $query->where('has_warnings', true);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('worksheet_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('worksheet_date', '<=', $request->input('date_to'));
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%$search%")
                  ->orWhereIn('id', fn ($sub) => $sub->select('worksheet_samples.worksheet_id')->from('worksheet_samples')
                      ->join('samples', 'samples.id', '=', 'worksheet_samples.sample_id')
                      ->where(fn ($w) => $w->where('samples.code', 'like', "%$search%")
                          ->orWhere('samples.sample_number', 'like', "%$search%")));
            });
        }

        $perPage = min(100, max(5, (int) $request->input('per_page', 20)));
        $page = $query->orderByDesc('worksheet_date')->orderByDesc('id')->paginate($perPage);

        // Ro'yxatda probaning «progress» maydoni kerak emas (har proba uchun qo'shimcha so'rov bo'lmasin)
        $page->getCollection()->each(fn (Worksheet $w) => $w->samples->each->setAppends([]));

        return $page;
    }

    /**
     * Probalar jadvali uchun: bir nechta probaning varaqlari bitta so'rovda.
     *   GET /worksheets/by-samples?ids=12,13,14
     * Javob: { "12": [{id, number, status, samples_count, member_status, ...}], ... }
     * Partiya varaq har bir a'zo probasi ostida chiqadi. Varag'i yo'q probalar kelmaydi.
     */
    public function bySamples(Request $request)
    {
        $ids = collect(explode(',', (string) $request->input('ids')))
            ->map(fn ($x) => (int) trim($x))
            ->filter(fn ($x) => $x > 0)
            ->unique()
            ->take(200)
            ->values();

        if ($ids->isEmpty()) {
            return response()->json((object) []);
        }

        $query = Worksheet::query()
            ->whereIn('id', fn ($sub) => $sub->select('worksheet_id')->from('worksheet_samples')->whereIn('sample_id', $ids->all()))
            ->with(['template:id,code,name_uz,name_ru,name_uz_cyrl', 'members:id,worksheet_id,sample_id,status'])
            ->withCount('members')
            ->orderBy('id');

        $this->service->scopeVisible($query, $request->user());

        $wanted = array_flip($ids->all());
        $out = [];
        $cols = ['id', 'sample_id', 'template_id', 'number', 'status', 'worksheet_date', 'has_warnings'];
        if (WorksheetService::hasKind()) {
            $cols[] = 'kind';
        }
        foreach ($query->get($cols) as $w) {
            foreach ($w->members as $m) {
                if (! isset($wanted[$m->sample_id])) {
                    continue;
                }
                $out[$m->sample_id][] = [
                    'id'             => $w->id,
                    'number'         => $w->number,
                    'kind'           => $w->kind ?: 'main',
                    'status'         => $w->status,
                    'worksheet_date' => $w->worksheet_date?->toDateString(),
                    'has_warnings'   => (bool) $w->has_warnings,
                    'samples_count'  => (int) $w->members_count,
                    'member_status'  => $m->status,
                    'template'       => $w->template ? [
                        'code'         => $w->template->code,
                        'name_uz'      => $w->template->name_uz,
                        'name_ru'      => $w->template->name_ru,
                        'name_uz_cyrl' => $w->template->name_uz_cyrl,
                    ] : null,
                ];
            }
        }

        return response()->json((object) $out);
    }

    /**
     * Yangi varaq uchun probalar: shablonga mos, natijasi chiqmagan, boshqa varaqda band emas.
     *   GET /worksheets/candidates?template_id=4&search=&date_from=&date_to=&except_worksheet=
     */
    public function candidates(Request $request)
    {
        $data = $request->validate([
            'template_id'      => ['required', 'exists:worksheet_templates,id'],
            'search'           => ['nullable', 'string', 'max:100'],
            'date_from'        => ['nullable', 'date'],
            'date_to'          => ['nullable', 'date'],
            'group_id'         => ['nullable', 'integer'],
            'except_worksheet' => ['nullable', 'integer'],
            'kind'             => ['nullable', 'in:main,control'],
        ]);

        $template = WorksheetTemplate::findOrFail($data['template_id']);
        if (! empty($data['except_worksheet']) && empty($data['kind'])) {
            // Mavjud varaqqa qo'shishda — o'sha varaqning turi
            $data['kind'] = Worksheet::find($data['except_worksheet'])?->kind;
        }
        $consume = class_exists(\App\Services\Samples\SampleLedger::class)
            ? app(\App\Services\Samples\SampleLedger::class)->consumeColumn($template->toDefinition(), (int) $template->id)
            : null;

        return response()->json([
            'template' => [
                'id'               => $template->id,
                'default_rows'     => max(1, min(WorksheetService::MAX_PARALLELS, (int) $template->default_rows ?: 2)),
                'max_samples'      => $template->max_samples,
                'orientation'      => $template->orientation,
                'result_elements'  => $template->resultElementIds(),
                'consume'          => $consume, // proba sarfi ustuni: {key, unit, default, label}
            ],
            'samples' => $this->service->candidates($template, $request->user(), $data),
        ]);
    }

    /** Bosqich bajaruvchilari ro'yxati (users.view ruxsati bo'lmaganlar uchun ham) */
    public function staff()
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'laboratory_group_id']);
    }

    public function show(Request $request, Worksheet $worksheet)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);

        return $this->payload($worksheet);
    }

    /**
     * Yangi varaq (partiya):
     *   { template_id, worksheet_date?, samples: [{id, parallels?}, ...] }
     * Eski ko'rinish ham qabul qilinadi: { template_id, sample_id, rows? }
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'template_id'         => ['required', 'exists:worksheet_templates,id'],
            'worksheet_date'      => ['nullable', 'date'],
            'samples'             => ['required_without:sample_id', 'array', 'min:1', 'max:' . WorksheetService::MAX_SAMPLES],
            'samples.*.id'        => ['required', 'integer'],
            'samples.*.parallels' => ['nullable', 'integer', 'min:1', 'max:' . WorksheetService::MAX_PARALLELS],
            'sample_id'           => ['required_without:samples', 'integer'],
            'rows'                => ['nullable', 'integer', 'min:1', 'max:' . WorksheetService::MAX_PARALLELS],
            'kind'                => ['nullable', 'in:main,control'],
            'confirm_low_balance' => ['nullable', 'boolean'],
        ]);
        $kind = WorksheetService::normalizeKind($data['kind'] ?? null);

        $template = WorksheetTemplate::findOrFail($data['template_id']);
        if (! $template->is_active) {
            throw ValidationException::withMessages(['template_id' => ['Bu shablon faol emas']]);
        }

        $items = $data['samples'] ?? [['id' => $data['sample_id'], 'parallels' => $data['rows'] ?? null]];
        $resolved = $this->service->resolveItems($template, $items, $request->user(), null, $kind);

        // Proba qoldig'i rejalangan sarfga yetmasa — ogohlantirish, foydalanuvchi tasdiqlasa davom etiladi
        $short = $this->shortages($template->toDefinition(), (int) $template->id, $resolved);
        if ($short && empty($data['confirm_low_balance'])) {
            return $this->lowBalanceResponse($short);
        }

        $worksheet = $this->service->create($template, $resolved, $request->user(), $data['worksheet_date'] ?? null, $kind);

        $codes = collect($resolved)->map(fn ($it) => $it['sample']->code)->all();
        $this->logActivity('created', $worksheet, ['samples' => $codes, 'template' => $template->code, 'kind' => $kind]
            + ($short ? ['low_balance_confirmed' => $short] : []),
            ($kind === 'control' ? 'Nazorat uchun ishchi varaq' : 'Ishchi varaq') . " yaratildi: № {$worksheet->number} (" . count($codes) . ' ta proba: ' . implode(', ', array_slice($codes, 0, 10))
            . (count($codes) > 10 ? ' …' : '') . ')');

        return response()->json($this->payload($worksheet), 201);
    }

    /** Partiyaga proba qo'shish: { samples: [{id, parallels?}] } */
    public function addSamples(Request $request, Worksheet $worksheet)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);
        $this->service->assertEditable($worksheet);

        $data = $request->validate([
            'samples'             => ['required', 'array', 'min:1', 'max:' . WorksheetService::MAX_SAMPLES],
            'samples.*.id'        => ['required', 'integer'],
            'samples.*.parallels' => ['nullable', 'integer', 'min:1', 'max:' . WorksheetService::MAX_PARALLELS],
            'confirm_low_balance' => ['nullable', 'boolean'],
        ]);

        $template = WorksheetTemplate::withTrashed()->findOrFail($worksheet->template_id);
        $resolved = $this->service->resolveItems($template, $data['samples'], $request->user(), $worksheet);

        $short = $this->shortages($worksheet->definition(), (int) $worksheet->template_id, $resolved);
        if ($short && empty($data['confirm_low_balance'])) {
            return $this->lowBalanceResponse($short);
        }

        $this->service->addSamples($worksheet, $resolved, $request->user());

        $codes = collect($resolved)->map(fn ($it) => $it['sample']->code)->all();
        $this->logActivity('updated', $worksheet, ['added_samples' => $codes] + ($short ? ['low_balance_confirmed' => $short] : []),
            "Ishchi varaqqa proba qo'shildi: № {$worksheet->number} (" . implode(', ', $codes) . ')');

        return response()->json($this->payload($worksheet->fresh()));
    }

    /** Partiyadan probani olib tashlash (o'lchov kiritilmagan bo'lsa) */
    public function removeSample(Request $request, Worksheet $worksheet, Sample $sample)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);
        $this->service->removeSample($worksheet, $sample, $request->user());

        $this->logActivity('updated', $worksheet, ['removed_sample' => $sample->code],
            "Ishchi varaqdan proba olib tashlandi: № {$worksheet->number} ({$sample->code})");

        return response()->json($this->payload($worksheet->fresh()));
    }

    /** «Takroriy tahlil»: { reason } — belgilash; DELETE — bekor qilish */
    public function markRetest(Request $request, Worksheet $worksheet, Sample $sample)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->service->setRetest($worksheet, $sample, $data['reason'], $request->user());
        $this->logActivity('updated', $worksheet, ['retest' => $sample->code, 'reason' => $data['reason']],
            "Takroriy tahlilga qaytarildi: {$sample->code} (varaq № {$worksheet->number}). Sabab: {$data['reason']}");

        return response()->json($this->payload($worksheet->fresh()));
    }

    public function clearRetest(Request $request, Worksheet $worksheet, Sample $sample)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);
        $this->service->setRetest($worksheet, $sample, null, $request->user());
        $this->logActivity('updated', $worksheet, ['retest_cleared' => $sample->code],
            "Takroriy tahlil belgisi olib tashlandi: {$sample->code} (varaq № {$worksheet->number})");

        return response()->json($this->payload($worksheet->fresh()));
    }

    public function update(Request $request, Worksheet $worksheet)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);
        $this->service->assertEditable($worksheet);

        $data = $request->validate([
            'worksheet_date'          => ['required', 'date'],
            'header_values'           => ['nullable', 'array'],
            'notes'                   => ['nullable', 'string', 'max:2000'],
            'rows'                    => ['required', 'array', 'min:1', 'max:' . WorksheetService::MAX_ROWS],
            'rows.*.id'               => ['nullable', 'integer'],
            'rows.*.sample_id'        => ['nullable', 'integer'],
            'rows.*.inputs'           => ['nullable', 'array'],
            'rows.*.excluded'         => ['nullable', 'boolean'],
            'rows.*.note'             => ['nullable', 'string', 'max:255'],
            'signoffs'                => ['nullable', 'array', 'max:60'],
            'signoffs.*.stage'        => ['required', 'string', 'max:40'],
            'signoffs.*.slot'         => ['nullable', 'integer', 'min:1', 'max:6'],
            'signoffs.*.performer_id' => ['nullable', 'exists:users,id'],
            'signoffs.*.performer_name' => ['nullable', 'string', 'max:191'],
            'signoffs.*.performed_at' => ['nullable', 'date'],
            'signoffs.*.performed_time' => ['nullable', 'date_format:H:i'],  // [P16] bosqich soati
        ]);

        $this->service->save($worksheet, $data, $request->user());
        $fresh = $worksheet->fresh();

        $this->logActivity('updated', $fresh, [
            'groups'   => collect($fresh->results['groups'] ?? [])->map(fn ($g) => $g['summary'] ?? null)->all(),
            'totals'   => $fresh->results['totals'] ?? null,
            'warnings' => $fresh->has_warnings,
        ], "Ishchi varaq ma'lumotlari saqlandi: № {$fresh->number}");

        return response()->json($this->payload($fresh));
    }

    /** Chop etildi belgisi (birinchi chop etish vaqti saqlanadi) */
    public function markPrinted(Request $request, Worksheet $worksheet)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);

        $changes = ['printed_at' => $worksheet->printed_at ?: now()];
        if ($worksheet->status === 'draft') {
            $changes['status'] = 'printed';
        }
        $worksheet->forceFill($changes)->save();
        $this->logActivity('updated', $worksheet, null, "Ishchi varaq chop etildi: № {$worksheet->number}");

        return response()->json(['status' => $worksheet->status, 'printed_at' => $worksheet->printed_at]);
    }

    public function complete(Request $request, Worksheet $worksheet)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);
        $this->service->assertEditable($worksheet);

        $worksheet->load('rows');
        $missing = $this->service->missingRequired($worksheet);
        if ($missing) {
            throw ValidationException::withMessages([
                'rows' => array_merge(["Majburiy kataklar to'ldirilmagan:"], array_slice($missing, 0, 15)),
            ]);
        }

        $result = $this->service->recalculate($worksheet);
        if (! empty($result['errors'])) {
            throw ValidationException::withMessages(['rows' => ["Hisobda xato bor — shablon formulalarini tekshiring"]]);
        }

        $worksheet->forceFill(['status' => 'completed', 'completed_at' => now(), 'updated_by' => $request->user()->id])->save();
        $this->logActivity('updated', $worksheet, null, "Ishchi varaq yakunlandi: № {$worksheet->number}");

        return response()->json($this->payload($worksheet->fresh()));
    }

    public function approve(Request $request, Worksheet $worksheet)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);

        $data = $request->validate([
            'push_results'    => ['nullable', 'boolean'],
            'accept_warnings' => ['nullable', 'boolean'],
        ]);

        if ($worksheet->status !== 'completed') {
            throw ValidationException::withMessages(['status' => ['Avval varaqni «Yakunlash» kerak']]);
        }

        $worksheet->load('rows');
        $result = $this->service->recalculate($worksheet);
        $outOfTolerance = $this->service->toleranceFailures($result);

        if (! empty($result['errors'])) {
            throw ValidationException::withMessages(['rows' => ['Hisobda xato bor — tasdiqlab bo\'lmaydi']]);
        }
        if ($outOfTolerance && empty($data['accept_warnings'])) {
            throw ValidationException::withMessages([
                'accept_warnings' => ["Parallellar farqi ruxsat etilgan chegaradan oshgan — tasdiqlash uchun buni alohida qabul qiling yoki probani «takroriy tahlil» deb belgilang"],
            ]);
        }

        $report = $this->service->approve($worksheet, $request->user(), (bool) ($data['push_results'] ?? true));

        $this->logActivity('updated', $worksheet, [
            'groups'          => collect($worksheet->fresh()->results['groups'] ?? [])->map(fn ($g) => $g['summary'] ?? null)->all(),
            'pushed'          => $report['pushed'],
            'skipped'         => $report['skipped'],
            'accept_warnings' => (bool) ($data['accept_warnings'] ?? false),
        ], "Ishchi varaq tasdiqlandi: № {$worksheet->number}");

        return response()->json($this->payload($worksheet->fresh()) + ['push_report' => $report]);
    }

    public function reopen(Request $request, Worksheet $worksheet)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        if ($worksheet->status !== 'approved') {
            throw ValidationException::withMessages(['status' => ['Varaq tasdiqlanmagan']]);
        }

        $worksheet->forceFill([
            'status' => 'in_progress', 'approved_by' => null, 'approved_at' => null,
            'updated_by' => $request->user()->id,
        ])->save();

        $this->logActivity('updated', $worksheet, ['reason' => $data['reason']],
            "Ishchi varaq qayta ochildi: № {$worksheet->number}. Sabab: {$data['reason']}");

        return response()->json($this->payload($worksheet->fresh()));
    }

    public function destroy(Request $request, Worksheet $worksheet)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);
        $this->service->assertEditable($worksheet);

        $number = $worksheet->number;
        $worksheet->delete();
        $this->logActivity('deleted', $worksheet, null, "Ishchi varaq o'chirildi: № {$number}");

        return response()->json(['message' => "Ishchi varaq o'chirildi"]);
    }

    // ------------------------------------------------------------------- skanlar

    public function uploadScans(Request $request, Worksheet $worksheet)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);

        $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
        ]);

        $created = [];
        foreach ($request->file('files') as $file) {
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $path = $file->storeAs("worksheets/{$worksheet->id}", Str::uuid() . '.' . $ext, 'local');

            $created[] = $worksheet->scans()->create([
                'path'          => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 191),
                'mime'          => $file->getMimeType() ?: 'application/octet-stream',
                'size'          => $file->getSize(),
                'sha256'        => hash_file('sha256', $file->getRealPath()),
                'uploaded_by'   => $request->user()->id,
            ]);
        }

        $this->logActivity('updated', $worksheet, ['scans' => collect($created)->pluck('original_name')],
            "Ishchi varaqqa skan yuklandi: № {$worksheet->number}");

        return response()->json(
            $worksheet->scans()->with('uploader:id,name')->get(),
            201
        );
    }

    public function showScan(Request $request, Worksheet $worksheet, WorksheetScan $scan)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);
        abort_if((int) $scan->worksheet_id !== (int) $worksheet->id, 404);
        abort_unless(Storage::disk('local')->exists($scan->path), 404, 'Fayl topilmadi');

        return Storage::disk('local')->response($scan->path, $scan->original_name, [
            'Content-Type'  => $scan->mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function deleteScan(Request $request, Worksheet $worksheet, WorksheetScan $scan)
    {
        $this->service->assertCanAccess($request->user(), $worksheet);
        $this->service->assertEditable($worksheet);
        abort_if((int) $scan->worksheet_id !== (int) $worksheet->id, 404);

        Storage::disk('local')->delete($scan->path);
        $name = $scan->original_name;
        $scan->delete();
        $this->logActivity('updated', $worksheet, ['scan_deleted' => $name], "Ishchi varaqdan skan o'chirildi: № {$worksheet->number}");

        return response()->json(['message' => "Skan o'chirildi"]);
    }

    // ----------------------------------------------------------------- yordamchi

    /** Rejalangan sarf (parallellar × standart naveska) proba qoldig'idan oshadigan probalar */
    private function shortages(array $definition, int $templateId, array $resolved): array
    {
        if (! class_exists(\App\Services\Samples\SampleLedger::class)) {
            return [];
        }

        return app(\App\Services\Samples\SampleLedger::class)->shortages($definition, $templateId, $resolved);
    }

    private function lowBalanceResponse(array $short)
    {
        $lines = array_map(fn ($s) => "{$s['code']}: kerak {$s['need']} {$s['unit']}, qoldiq {$s['available']} {$s['unit']}", $short);

        return response()->json([
            'message'     => "Probada yetarli miqdor qolmagan — " . implode('; ', $lines),
            'low_balance' => $short,
            'errors'      => ['low_balance' => $lines],
        ], 422);
    }

    private function payload(Worksheet $worksheet): array
    {
        $worksheet->load([
            'members' => fn ($q) => $q->orderBy('position')->orderBy('id'),
            'members.sample:id,code,sample_number,source_document,source_division,assigned_group_id,status,registered_at',
            'members.sample.assignedGroup:id,name_ru,name_uz,name_en',
            'members.sample.sampleElements:id,sample_id,element_id,result_value,result_unit,status',
            'members.sample.sampleElements.element:id,symbol,name_ru,name_uz,name_en',
            'members.retestUser:id,name',
            'sample:id,code,sample_number,source_document,source_division,assigned_group_id,status,registered_at',
            'sample.assignedGroup:id,name_ru,name_uz,name_en',
            'sample.sampleElements:id,sample_id,element_id,result_value,result_unit,status',
            'sample.sampleElements.element:id,symbol,name_ru,name_uz,name_en',
            'template:id,code,version,name_uz,name_ru,name_uz_cyrl,is_active,max_samples,default_rows',
            'rows',
            'rows.adder:id,name',
            'signoffs.performer:id,name',
            'scans.uploader:id,name',
            'creator:id,name',
            'approver:id,name',
        ]);

        $out = $worksheet->toArray();
        // [P28] «Varaq bo'yicha jami» nusxada yo'q eski/tasdiqlangan varaqlarda ham ekranda va chop etishda
        // ko'rinsin — faqat javobdagi nusxaga (bazadagi nusxa va natijalar o'zgarmaydi).
        // Qaysi jami — shablonda admin hozir belgilagani (olib tashlagan bo'lsa — ko'rsatilmaydi).
        $out['template_snapshot'] = DisplayTotals::forWorksheet($worksheet, (array) ($out['template_snapshot'] ?? []));

        return $out;
    }
}
