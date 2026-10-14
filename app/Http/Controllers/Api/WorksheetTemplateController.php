<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\WorksheetTemplate;
use App\Models\WorksheetTemplateColumn;
use App\Services\Worksheets\TemplateValidator;
use App\Services\Worksheets\WorksheetCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ishchi varaq shablonlari — universal konstruktor (admin ustun va formula qo'shadi).
 */
class WorksheetTemplateController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
    {
        $query = WorksheetTemplate::query()
            ->with(['testProfile:id,code,name_ru,name_uz,name_en', 'columns:id,template_id,scope,element_id'])
            ->withCount(['columns', 'worksheets']);

        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%$search%")
                  ->orWhere('name_uz', 'like', "%$search%")
                  ->orWhere('name_ru', 'like', "%$search%")
                  ->orWhere('name_uz_cyrl', 'like', "%$search%");
            });
        }

        return $query->orderByDesc('is_active')->orderBy('code')->get()->map(function (WorksheetTemplate $t) {
            $t->result_element_ids = $t->columns
                ->where('scope', 'summary')->pluck('element_id')->filter()->unique()->values();
            $t->unsetRelation('columns');

            return $t;
        });
    }

    public function show(WorksheetTemplate $worksheetTemplate)
    {
        return $worksheetTemplate->load(['columns.element:id,symbol,name_ru,name_uz,name_en', 'testProfile:id,code,name_ru,name_uz,name_en'])
            ->loadCount('worksheets');
    }

    public function store(Request $request)
    {
        [$attrs, $columns] = $this->validated($request);

        $template = DB::transaction(function () use ($attrs, $columns) {
            $template = WorksheetTemplate::create($attrs + ['created_by' => auth()->id(), 'updated_by' => auth()->id()]);
            $this->syncColumns($template, $columns);

            return $template;
        });

        $this->logActivity('created', $template, ['code' => $template->code], "Ishchi varaq shabloni qo'shildi: {$template->code}");

        return response()->json($this->show($template->fresh()), 201);
    }

    public function update(Request $request, WorksheetTemplate $worksheetTemplate)
    {
        [$attrs, $columns] = $this->validated($request);

        DB::transaction(function () use ($worksheetTemplate, $attrs, $columns) {
            $worksheetTemplate->update($attrs + ['updated_by' => auth()->id()]);
            $this->syncColumns($worksheetTemplate, $columns);
        });

        $this->logActivity('updated', $worksheetTemplate, ['code' => $worksheetTemplate->code],
            "Ishchi varaq shabloni o'zgartirildi: {$worksheetTemplate->code} (avval yaratilgan varaqlar o'z nusxasida qoladi)");

        return response()->json($this->show($worksheetTemplate->fresh()));
    }

    /** Yangi versiya uchun nusxa (faolsiz holatda) */
    public function duplicate(WorksheetTemplate $worksheetTemplate)
    {
        $worksheetTemplate->load('columns');

        $copy = DB::transaction(function () use ($worksheetTemplate) {
            $copy = $worksheetTemplate->replicate(['created_by', 'updated_by']);
            $copy->name_uz = $worksheetTemplate->name_uz . ' (nusxa)';
            $copy->version = null;
            $copy->valid_from = null;
            $copy->is_active = false;
            $copy->created_by = auth()->id();
            $copy->updated_by = auth()->id();
            $copy->save();

            foreach ($worksheetTemplate->columns as $c) {
                $copy->columns()->create($c->replicate(['template_id'])->toArray());
            }

            return $copy;
        });

        $this->logActivity('created', $copy, ['from' => $worksheetTemplate->id], "Shablon nusxalandi: {$worksheetTemplate->code}");

        return response()->json($this->show($copy->fresh()), 201);
    }

    public function destroy(WorksheetTemplate $worksheetTemplate)
    {
        $code = $worksheetTemplate->code;
        $worksheetTemplate->delete();
        $this->logActivity('deleted', $worksheetTemplate, null, "Ishchi varaq shabloni o'chirildi: {$code}");

        return response()->json(['message' => "Shablon o'chirildi (yaratilgan varaqlar saqlanib qoladi)"]);
    }

    /** Konstruktordagi "Sinab ko'rish" — serverdagi rasmiy hisob bilan solishtirish uchun */
    public function preview(Request $request)
    {
        [$attrs, $columns] = $this->validated($request, false);
        $definition = $this->definitionFrom($attrs, $columns);
        $errors = TemplateValidator::validate($definition);

        $result = null;
        if (! $errors) {
            $result = WorksheetCalculator::calculate(
                $definition,
                (array) $request->input('preview.header', []),
                array_map(fn ($r) => [
                    'values'   => (array) ($r['values'] ?? []),
                    'excluded' => (bool) ($r['excluded'] ?? false),
                ], (array) $request->input('preview.rows', []))
            );
        }

        return response()->json(['errors' => $errors, 'result' => $result]);
    }

    // ----------------------------------------------------------------- yordamchi

    private function validated(Request $request, bool $strict = true): array
    {
        $data = $request->validate([
            'code'            => ['required', 'string', 'max:50'],
            'version'         => ['nullable', 'string', 'max:20'],
            'valid_from'      => ['nullable', 'date'],
            'name_uz'         => ['required', 'string', 'max:255'],
            'name_ru'         => ['nullable', 'string', 'max:255'],
            'name_uz_cyrl'    => ['nullable', 'string', 'max:255'],
            'test_profile_id' => ['nullable', 'exists:test_profiles,id'],
            'number_suffix'   => ['nullable', 'string', 'max:20'],
            'number_start'    => ['nullable', 'integer', 'min:1', 'max:9999999'],
            'default_rows'    => ['required', 'integer', 'min:1', 'max:6'],
            'max_samples'     => ['nullable', 'integer', 'min:1', 'max:30'],
            'print_locale'    => ['required', 'in:uz,uz_cyrl,ru'],
            'orientation'     => ['required', 'in:landscape,portrait'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'is_active'       => ['boolean'],

            'stages'                 => ['nullable', 'array', 'max:12'],
            'stages.*.key'           => ['required', 'string', 'max:40'],
            'stages.*.label_uz'      => ['required', 'string', 'max:191'],
            'stages.*.label_ru'      => ['nullable', 'string', 'max:191'],
            'stages.*.label_uz_cyrl' => ['nullable', 'string', 'max:191'],
            'stages.*.signers'       => ['nullable', 'integer', 'min:0', 'max:6'],

            'params'                 => ['nullable', 'array', 'max:20'],
            'params.*.key'           => ['required', 'string', 'max:40'],
            'params.*.label_uz'      => ['required', 'string', 'max:191'],
            'params.*.label_ru'      => ['nullable', 'string', 'max:191'],
            'params.*.label_uz_cyrl' => ['nullable', 'string', 'max:191'],
            'params.*.unit'          => ['nullable', 'string', 'max:30'],
            'params.*.default'       => ['nullable', 'string', 'max:50'],
            'params.*.decimals'      => ['nullable', 'integer', 'min:0', 'max:10'],

            'columns'                    => ['required', 'array', 'min:1', 'max:60'],
            'columns.*.scope'            => ['required', 'in:row,summary,total'],
            'columns.*.key'              => ['required', 'string', 'max:40'],
            'columns.*.type'             => ['required', 'in:input,computed,text'],
            'columns.*.stage'            => ['nullable', 'string', 'max:40'],
            'columns.*.label_uz'         => ['required', 'string', 'max:255'],
            'columns.*.label_ru'         => ['nullable', 'string', 'max:255'],
            'columns.*.label_uz_cyrl'    => ['nullable', 'string', 'max:255'],
            'columns.*.unit'             => ['nullable', 'string', 'max:30'],
            'columns.*.decimals'         => ['nullable', 'integer', 'min:0', 'max:10'],
            'columns.*.rounding'         => ['nullable', 'in:round,trunc,none'],
            'columns.*.formula'          => ['nullable', 'string', 'max:500'],
            'columns.*.default_value'    => ['nullable', 'string', 'max:50'],
            'columns.*.required'         => ['nullable', 'boolean'],
            'columns.*.element_id'       => ['nullable', 'exists:elements,id'],
            'columns.*.tolerance_pct'    => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'columns.*.tolerance_source' => ['nullable', 'string', 'max:40'],
            'columns.*.tolerance_mode'   => ['nullable', 'in:mean,range'],
            'columns.*.width'            => ['nullable', 'integer', 'min:20', 'max:400'],
            'columns.*.consumes_sample'  => ['nullable', 'boolean'],
        ]);

        $attrs = [
            'code'            => $data['code'],
            'version'         => $data['version'] ?? null,
            'valid_from'      => $data['valid_from'] ?? null,
            'name_uz'         => $data['name_uz'],
            'name_ru'         => $data['name_ru'] ?? null,
            'name_uz_cyrl'    => $data['name_uz_cyrl'] ?? null,
            'test_profile_id' => $data['test_profile_id'] ?? null,
            'number_suffix'   => $data['number_suffix'] ?? null,
            'number_start'    => $data['number_start'] ?? 1,
            'default_rows'    => $data['default_rows'],
            'max_samples'     => $data['max_samples'] ?? null,
            'print_locale'    => $data['print_locale'],
            'orientation'     => $data['orientation'],
            'notes'           => $data['notes'] ?? null,
            'is_active'       => (bool) ($data['is_active'] ?? true),
            'stages'          => array_values(array_map(fn ($s) => [
                'key'           => $s['key'],
                'label_uz'      => $s['label_uz'],
                'label_ru'      => $s['label_ru'] ?? null,
                'label_uz_cyrl' => $s['label_uz_cyrl'] ?? null,
                'signers'       => (int) ($s['signers'] ?? 1),
            ], $data['stages'] ?? [])),
            'params'          => array_values(array_map(fn ($p) => [
                'key'           => $p['key'],
                'label_uz'      => $p['label_uz'],
                'label_ru'      => $p['label_ru'] ?? null,
                'label_uz_cyrl' => $p['label_uz_cyrl'] ?? null,
                'unit'          => $p['unit'] ?? null,
                'default'       => $p['default'] ?? null,
                'decimals'      => isset($p['decimals']) ? (int) $p['decimals'] : null,
            ], $data['params'] ?? [])),
        ];

        $columns = array_values(array_map(function ($c) {
            $summary = $c['scope'] === 'summary';
            $computedScope = in_array($c['scope'], ['summary', 'total'], true); // jami ham doim hisoblanadi

            return [
                'scope'            => $c['scope'],
                'key'              => $c['key'],
                'type'             => $computedScope ? 'computed' : $c['type'],
                'stage'            => ($c['stage'] ?? null) ?: null,
                'label_uz'         => $c['label_uz'],
                'label_ru'         => $c['label_ru'] ?? null,
                'label_uz_cyrl'    => $c['label_uz_cyrl'] ?? null,
                'unit'             => $c['unit'] ?? null,
                'decimals'         => isset($c['decimals']) ? (int) $c['decimals'] : null,
                'rounding'         => $c['rounding'] ?? 'round',
                'formula'          => ($computedScope || $c['type'] === 'computed') ? ($c['formula'] ?? null) : null,
                'default_value'    => (! $computedScope && $c['type'] !== 'computed') ? ($c['default_value'] ?? null) : null,
                'required'         => (! $computedScope && $c['type'] === 'input') ? (bool) ($c['required'] ?? false) : false,
                'element_id'       => $summary ? ($c['element_id'] ?? null) : null,
                'tolerance_pct'    => $summary && isset($c['tolerance_pct']) && $c['tolerance_pct'] !== '' ? (string) $c['tolerance_pct'] : null,
                'tolerance_source' => $summary ? ($c['tolerance_source'] ?? null) : null,
                'tolerance_mode'   => $c['tolerance_mode'] ?? 'mean',
                'width'            => $c['width'] ?? null,
                // Proba sarfi (naveska) — faqat qatordagi kiritish ustuni bo'lishi mumkin
                'consumes_sample'  => $c['scope'] === 'row' && $c['type'] === 'input' && ! empty($c['consumes_sample']),
            ];
        }, $data['columns']));

        if ($strict) {
            $errors = TemplateValidator::validate($this->definitionFrom($attrs, $columns));
            if ($errors) {
                $bag = [];
                foreach ($errors as $e) {
                    $bag[$e['field']][] = $e['message'];
                }
                throw ValidationException::withMessages($bag);
            }
        }

        return [$attrs, $columns];
    }

    private function definitionFrom(array $attrs, array $columns): array
    {
        return ['stages' => $attrs['stages'], 'params' => $attrs['params'], 'columns' => $columns];
    }

    private function syncColumns(WorksheetTemplate $template, array $columns): void
    {
        WorksheetTemplateColumn::where('template_id', $template->id)->delete();
        foreach ($columns as $i => $c) {
            $template->columns()->create($c + ['sort_order' => $i]);
        }
    }
}
