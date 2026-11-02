<?php

namespace App\Services\Worksheets;

use App\Models\Sample;
use App\Models\SampleElement;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetRow;
use App\Models\WorksheetSample;
use App\Models\WorksheetSignoff;
use App\Models\WorksheetTemplate;
use App\Models\WorksheetTemplateColumn;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Ishchi varaq (partiya) bilan bog'liq asosiy amallar:
 *   yaratish (raqamlash + shablon nusxasi + probalar va parallellar),
 *   partiyaga proba qo'shish / olib tashlash, «takroriy tahlil» belgisi,
 *   saqlash (server tomonda qayta hisoblash), tasdiqlash (natijani har bir probaga o'tkazish).
 *
 * Qoidalar (ISO 17025 — yozuvlar izlanuvchanligi):
 *   - o'lchov kiritilgan qator o'chirilmaydi, faqat «hisobga olinmaydi» deb belgilanadi (sababi bilan);
 *   - chop etilgandan keyin qo'shilgan parallel «qo'shimcha» deb belgilanadi (kim qo'shgani bilan);
 *   - imzolar varaqqa bir marta qo'yiladi va partiyadagi barcha probalarga tegishli.
 *
 * Varaq turi (kind):
 *   main    — asosiy tahlil: natijasi tasdiqlanganda probaga o'tadi;
 *   control — nazorat uchun: natija probaga O'TMAYDI, faqat asosiy natija bilan solishtiriladi.
 *             Nazorat uchun natijasi chiqqan (yakunlangan) probalarni ham tanlash mumkin.
 *             Asosiy va nazorat varaqlari bir-birini «band» qilmaydi.
 */
class WorksheetService
{
    public const MAX_PARALLELS = 6;
    public const MAX_ROWS = 60;
    public const MAX_SAMPLES = 30;

    /** worksheets.kind ustuni bormi (yangi migratsiya ishga tushirilmagan bo'lsa ham eski rejim ishlasin) */
    public static function hasKind(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('worksheets', 'kind');
    }

    /** [P16] worksheet_signoffs.performed_time ustuni bormi (migratsiya bajarilganmi) */
    public static function hasSignoffTime(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('worksheet_signoffs', 'performed_time');
    }

    public static function normalizeKind(?string $kind): string
    {
        return $kind === 'control' ? 'control' : 'main';
    }

    // ------------------------------------------------------------ ruxsat doirasi

    /** Foydalanuvchi barcha guruhlar varaqlarini ko'radimi? */
    public function seesAll(User $user): bool
    {
        return $user->isAdmin()
            || $user->hasPermission('samples.view_all')
            || $user->hasPermission('worksheets.approve')
            || ! $user->laboratory_group_id;
    }

    public function scopeVisible($query, User $user)
    {
        if ($this->seesAll($user)) {
            return $query;
        }

        $group = $user->laboratory_group_id;

        return $query->where(function ($q) use ($user, $group) {
            $q->whereIn('id', function ($sub) use ($group) {
                $sub->select('worksheet_samples.worksheet_id')
                    ->from('worksheet_samples')
                    ->join('samples', 'samples.id', '=', 'worksheet_samples.sample_id')
                    ->where('samples.assigned_group_id', $group);
            })->orWhere('created_by', $user->id);
        });
    }

    public function assertCanAccessSample(User $user, Sample $sample): void
    {
        if ($this->seesAll($user)) {
            return;
        }
        // Probani o'zi ro'yxatga olgan foydalanuvchi — proba boshqa guruhga uzatilgan bo'lsa ham
        if ((int) $sample->registered_by === (int) $user->id) {
            return;
        }
        if ((int) $sample->assigned_group_id !== (int) $user->laboratory_group_id) {
            abort(403, "Proba {$sample->code} sizning guruhingizga biriktirilmagan");
        }
    }

    public function assertCanAccess(User $user, Worksheet $worksheet): void
    {
        if ($this->seesAll($user) || (int) $worksheet->created_by === (int) $user->id) {
            return;
        }
        $inGroup = DB::table('worksheet_samples')
            ->join('samples', 'samples.id', '=', 'worksheet_samples.sample_id')
            ->where('worksheet_samples.worksheet_id', $worksheet->id)
            ->where('samples.assigned_group_id', $user->laboratory_group_id)
            ->exists();
        if (! $inGroup) {
            abort(403, 'Bu ishchi varaq sizning guruhingizga tegishli emas');
        }
    }

    public function assertEditable(Worksheet $worksheet): void
    {
        if ($worksheet->isLocked()) {
            abort(423, "Ishchi varaq tasdiqlangan — o'zgartirish uchun avval qayta oching");
        }
    }

    // ------------------------------------------------------------ nomzod probalar

    /** Shablon bilan bir xil elementni aniqlaydigan shablonlar (o'zi ham) */
    public function relatedTemplateIds(WorksheetTemplate $template): array
    {
        $elements = $template->resultElementIds();
        $ids = $elements
            ? WorksheetTemplateColumn::query()->where('scope', 'summary')->whereIn('element_id', $elements)
                ->pluck('template_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $ids[] = (int) $template->id;

        return array_values(array_unique($ids));
    }

    /**
     * Probaning shu elementlari boshqa (tasdiqlanmagan) varaqda band bo'lsa — subquery.
     * whereNotIn('id', fn) ko'rinishida ishlatiladi (SQL Server parametr chegarasiga tushmaslik uchun).
     */
    private function busySubquery(array $templateIds, ?int $exceptWorksheetId = null, string $kind = 'main'): \Closure
    {
        return function ($sub) use ($templateIds, $exceptWorksheetId, $kind) {
            $sub->select('worksheet_samples.sample_id')
                ->from('worksheet_samples')
                ->join('worksheets', 'worksheets.id', '=', 'worksheet_samples.worksheet_id')
                ->whereNull('worksheets.deleted_at')
                ->where('worksheets.status', '!=', 'approved')
                ->where('worksheet_samples.status', 'active')
                ->whereIn('worksheets.template_id', $templateIds);
            if (self::hasKind()) {
                $sub->where('worksheets.kind', $kind); // asosiy va nazorat varaqlari bir-birini band qilmaydi
            }
            if ($exceptWorksheetId) {
                $sub->where('worksheets.id', '!=', $exceptWorksheetId);
            }
        };
    }

    /**
     * Shablonga mos, hali natijasi chiqmagan va boshqa varaqda band bo'lmagan probalar.
     * Nazorat varag'i uchun (kind=control): natijasi chiqqan probalar ham (arxivlanganlardan tashqari).
     *
     * @param  array{search?:string,date_from?:string,date_to?:string,group_id?:int,except_worksheet?:int,kind?:string}  $filters
     */
    public function candidates(WorksheetTemplate $template, User $user, array $filters = []): Collection
    {
        $elements = $template->resultElementIds();
        $related = $this->relatedTemplateIds($template);
        $control = self::normalizeKind($filters['kind'] ?? null) === 'control';

        $query = Sample::query()
            ->when(! $control, fn ($q) => $q->whereNotIn('status', ['completed', 'archived']))
            ->when($control, fn ($q) => $q->where('status', '!=', 'archived'))
            ->whereNotIn('id', $this->busySubquery($related, $filters['except_worksheet'] ?? null, $control ? 'control' : 'main'))
            ->with([
                'sampleElements:id,sample_id,element_id,result_value,status',
                'sampleElements.element:id,symbol',
                'assignedGroup:id,name_uz,name_ru,name_en',
            ]);

        if ($elements) {
            // whereHas o'rniga oddiy subquery — relation'da orderBy bo'lsa ham SQL Server'da xavfsiz
            $query->whereIn('id', function ($sub) use ($elements, $control) {
                $sub->select('sample_id')->from('sample_elements')->whereIn('element_id', $elements);
                if (! $control) {
                    $sub->whereNull('result_value');
                }
            });
        }

        if (! $this->seesAll($user)) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_group_id', $user->laboratory_group_id)
                  ->orWhere('registered_by', $user->id);
            });
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%$search%")->orWhere('sample_number', 'like', "%$search%");
            });
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('registered_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('registered_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['group_id'])) {
            $query->where('assigned_group_id', (int) $filters['group_id']);
        }

        $samples = $control
            ? $query->orderByDesc('registered_at')->orderByDesc('id')->limit(300)->get() // nazorat — avval yangilari
            : $query->orderBy('registered_at')->orderBy('id')->limit(300)->get();

        // Proba qoldig'i (miqdor hisobi moduli o'rnatilgan bo'lsa)
        $qty = $samples->isNotEmpty() && class_exists(\App\Services\Samples\SampleLedger::class)
            ? app(\App\Services\Samples\SampleLedger::class)->candidateInfo($samples, $template->toDefinition(), (int) $template->id)
            : [];

        // Avval «takroriy tahlil»ga qaytarilgan probalar — belgi va sababi bilan
        $retests = $samples->isEmpty() ? collect() : DB::table('worksheet_samples')
            ->join('worksheets', 'worksheets.id', '=', 'worksheet_samples.worksheet_id')
            ->whereNull('worksheets.deleted_at')
            ->where('worksheet_samples.status', 'retest')
            ->whereIn('worksheet_samples.sample_id', $samples->pluck('id')->all())
            ->orderBy('worksheet_samples.retest_at')
            ->get(['worksheet_samples.sample_id', 'worksheet_samples.retest_reason', 'worksheets.number'])
            ->keyBy('sample_id');

        return $samples->map(function (Sample $s) use ($elements, $retests, $control, $qty) {
            $pending = $s->sampleElements
                ->filter(fn ($se) => ($control || $se->result_value === null) && (! $elements || in_array((int) $se->element_id, $elements, true)))
                ->map(fn ($se) => $se->element?->symbol)->filter()->values();
            $rt = $control ? null : $retests->get($s->id);

            return [
                'id'              => $s->id,
                'code'            => $s->code,
                'sample_number'   => $s->sample_number,
                'status'          => $s->status,
                'registered_at'   => $s->registered_at?->toDateTimeString(),
                'source_division' => $s->source_division,
                'assigned_group'  => $s->assignedGroup ? [
                    'id' => $s->assignedGroup->id, 'name_uz' => $s->assignedGroup->name_uz,
                    'name_ru' => $s->assignedGroup->name_ru, 'name_en' => $s->assignedGroup->name_en,
                ] : null,
                'elements'        => $pending,
                'retest'          => $rt ? ['reason' => $rt->retest_reason, 'worksheet' => $rt->number] : null,
                'quantity'        => $qty[(int) $s->id] ?? null,
                'results'         => $control ? $s->sampleElements
                    ->filter(fn ($se) => $se->result_value !== null && (! $elements || in_array((int) $se->element_id, $elements, true)))
                    ->map(fn ($se) => ['symbol' => $se->element?->symbol, 'value' => (float) $se->result_value])->values() : null,
            ];
        })->values();
    }

    /**
     * Yaratish/qo'shishdan oldin probalarni tekshirish.
     *
     * @param  array<int, array{id:int, parallels?:int}>  $items
     * @return array<int, array{sample: Sample, parallels: int}>
     */
    public function resolveItems(WorksheetTemplate $template, array $items, User $user, ?Worksheet $into = null, ?string $kind = null): array
    {
        $kind = self::normalizeKind($into ? $into->kind : $kind);
        $default = max(1, min(self::MAX_PARALLELS, (int) $template->default_rows ?: 2));
        $elements = $template->resultElementIds();
        $related = $this->relatedTemplateIds($template);

        $ids = [];
        foreach ($items as $it) {
            $ids[] = (int) ($it['id'] ?? 0);
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['samples' => ['Bitta proba ikki marta tanlangan']]);
        }

        $samples = Sample::query()->with('sampleElements:id,sample_id,element_id,result_value')
            ->whereIn('id', $ids)->get()->keyBy('id');
        $busy = DB::table('worksheet_samples')
            ->join('worksheets', 'worksheets.id', '=', 'worksheet_samples.worksheet_id')
            ->whereNull('worksheets.deleted_at')->where('worksheets.status', '!=', 'approved')
            ->where('worksheet_samples.status', 'active')
            ->whereIn('worksheets.template_id', $related)
            ->whereIn('worksheet_samples.sample_id', $ids ?: [0])
            ->when(self::hasKind(), fn ($q) => $q->where('worksheets.kind', $kind))
            ->when($into, fn ($q) => $q->where('worksheets.id', '!=', $into->id))
            ->get(['worksheet_samples.sample_id', 'worksheets.number'])
            ->keyBy('sample_id');

        $out = [];
        $errors = [];
        foreach ($items as $i => $it) {
            $sample = $samples->get((int) ($it['id'] ?? 0));
            if (! $sample) {
                $errors["samples.$i.id"] = ['Proba topilmadi'];
                continue;
            }
            $this->assertCanAccessSample($user, $sample);

            if ($into && $into->members()->where('sample_id', $sample->id)->exists()) {
                $errors["samples.$i.id"] = ["{$sample->code}: bu proba varaqda allaqachon bor"];
                continue;
            }
            if ($b = $busy->get($sample->id)) {
                $errors["samples.$i.id"] = ["{$sample->code}: № {$b->number} varaqda turibdi"];
                continue;
            }
            if ($elements && ! $sample->sampleElements->contains(fn ($se) => in_array((int) $se->element_id, $elements, true))) {
                $errors["samples.$i.id"] = ["{$sample->code}: probada bu shablon aniqlaydigan element buyurtma qilinmagan"];
                continue;
            }

            $par = isset($it['parallels']) && $it['parallels'] !== '' && $it['parallels'] !== null ? (int) $it['parallels'] : $default;
            if ($par < 1 || $par > self::MAX_PARALLELS) {
                $errors["samples.$i.parallels"] = ["Parallellar soni 1–" . self::MAX_PARALLELS . " oralig'ida bo'lsin"];
                continue;
            }
            $out[] = ['sample' => $sample, 'parallels' => $par];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    private function assertBatchSize(WorksheetTemplate $template, int $samples, int $rows): void
    {
        $max = $template->max_samples ? min(self::MAX_SAMPLES, (int) $template->max_samples) : self::MAX_SAMPLES;
        if ($samples > $max) {
            throw ValidationException::withMessages(['samples' => ["Bu shablonda bir varaqqa ko'pi bilan {$max} ta proba"]]);
        }
        if ($rows > self::MAX_ROWS) {
            throw ValidationException::withMessages(['samples' => ["Varaqda ko'pi bilan " . self::MAX_ROWS . " ta qator bo'lishi mumkin"]]);
        }
    }

    // -------------------------------------------------------------------- yaratish

    /**
     * @param  array<int, array{sample: Sample, parallels: int}>  $items  resolveItems() natijasi
     */
    public function create(WorksheetTemplate $template, array $items, User $user, ?string $date = null, string $kind = 'main'): Worksheet
    {
        $kind = self::normalizeKind($kind);
        if (! $items) {
            throw ValidationException::withMessages(['samples' => ['Kamida bitta proba tanlang']]);
        }
        $this->assertBatchSize($template, count($items), array_sum(array_column($items, 'parallels')));

        $parsed = $date ? Carbon::parse($date) : now();
        $date = $parsed->toDateString();
        $year = (int) $parsed->year;
        $definition = $template->toDefinition();

        // Bir vaqtda ikki kishi yaratsa — noyob indeks to'qnashuvi bo'ladi, qayta urinamiz
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return DB::transaction(function () use ($template, $items, $user, $date, $year, $definition, $kind) {
                    [$series, $seq, $number] = $this->nextNumber($template, $year);

                    $worksheet = Worksheet::create((self::hasKind() ? ['kind' => $kind] : []) + [
                        'template_id'       => $template->id,
                        'sample_id'         => $items[0]['sample']->id,
                        'series'            => $series,
                        'year'              => $year,
                        'seq'               => $seq,
                        'number'            => $number,
                        'template_snapshot' => $definition,
                        'worksheet_date'    => $date,
                        'status'            => 'draft',
                        'header_values'     => $this->defaultHeader($definition),
                        'created_by'        => $user->id,
                        'updated_by'        => $user->id,
                    ]);

                    $this->appendSamples($worksheet, $items, $definition, 0, 0, false, $user);
                    $this->recalculate($worksheet->fresh('rows'));

                    return $worksheet;
                });
            } catch (QueryException $e) {
                if ($attempt === 5 || ! $this->isUniqueViolation($e)) {
                    throw $e;
                }
                usleep(random_int(20, 120) * 1000);
            }
        }

        throw new \RuntimeException('Ishchi varaq raqamini olib bo\'lmadi');
    }

    private function appendSamples(Worksheet $worksheet, array $items, array $definition, int $position, int $rowNo, bool $extra, User $user): void
    {
        $defaults = $this->defaultInputs($definition);
        foreach ($items as $it) {
            $position++;
            WorksheetSample::create([
                'worksheet_id' => $worksheet->id,
                'sample_id'    => $it['sample']->id,
                'position'     => $position,
                'parallels'    => $it['parallels'],
                'status'       => 'active',
            ]);
            for ($p = 1; $p <= $it['parallels']; $p++) {
                $rowNo++;
                $worksheet->rows()->create([
                    'row_no'      => $rowNo,
                    'sample_id'   => $it['sample']->id,
                    'parallel_no' => $p,
                    'inputs'      => $defaults,
                    'computed'    => [],
                    'excluded'    => false,
                    'is_extra'    => $extra,
                    'added_by'    => $extra ? $user->id : null,
                ]);
            }
        }
    }

    /** Mavjud varaqqa proba qo'shish (tasdiqlanmagan bo'lsa) */
    public function addSamples(Worksheet $worksheet, array $items, User $user): Worksheet
    {
        $this->assertEditable($worksheet);
        $template = WorksheetTemplate::withTrashed()->findOrFail($worksheet->template_id);

        DB::transaction(function () use ($worksheet, $template, $items, $user) {
            $members = $worksheet->members()->count();
            $rows = WorksheetRow::where('worksheet_id', $worksheet->id)->count();
            $this->assertBatchSize($template, $members + count($items), $rows + array_sum(array_column($items, 'parallels')));

            $position = (int) WorksheetSample::where('worksheet_id', $worksheet->id)->max('position');
            $rowNo = (int) WorksheetRow::where('worksheet_id', $worksheet->id)->max('row_no');
            $this->appendSamples($worksheet, $items, $worksheet->definition(), $position, $rowNo, false, $user);
            $worksheet->forceFill(['updated_by' => $user->id])->save();
            $this->recalculate($worksheet->fresh('rows'));
        });

        return $worksheet->fresh();
    }

    /** Probani partiyadan olib tashlash — faqat unga hali o'lchov kiritilmagan bo'lsa */
    public function removeSample(Worksheet $worksheet, Sample $sample, User $user): Worksheet
    {
        $this->assertEditable($worksheet);
        $member = $worksheet->members()->where('sample_id', $sample->id)->first();
        abort_unless($member, 404, 'Bu proba varaqda yo\'q');

        if ($worksheet->members()->count() <= 1) {
            throw ValidationException::withMessages(['sample' => ["Varaqdagi yagona probani olib bo'lmaydi — varaqni o'chiring"]]);
        }
        $defaults = $this->defaultInputs($worksheet->definition());
        $rows = WorksheetRow::where('worksheet_id', $worksheet->id)->where('sample_id', $sample->id)->get();
        foreach ($rows as $r) {
            if ($this->hasMeasurement($r->inputs ?? [], $defaults)) {
                throw ValidationException::withMessages(['sample' => [
                    "{$sample->code}: o'lchov kiritilgan — probani olib bo'lmaydi. Kerak bo'lsa «takroriy tahlil» deb belgilang",
                ]]);
            }
        }

        DB::transaction(function () use ($worksheet, $member, $sample, $user) {
            WorksheetRow::where('worksheet_id', $worksheet->id)->where('sample_id', $sample->id)->delete();
            $member->delete();

            // Tartib raqamlarini qayta joylash
            $pos = 0;
            foreach ($worksheet->members()->orderBy('position')->orderBy('id')->get() as $m) {
                $m->forceFill(['position' => ++$pos])->save();
            }
            $this->renumberRows($worksheet);

            $first = $worksheet->members()->orderBy('position')->value('sample_id');
            $worksheet->forceFill(['sample_id' => $first, 'updated_by' => $user->id])->save();
            $this->recalculate($worksheet->fresh('rows'));
        });

        return $worksheet->fresh();
    }

    /** «Takroriy tahlil» — natija probaga o'tmaydi, proba yangi partiya uchun navbatga qaytadi */
    public function setRetest(Worksheet $worksheet, Sample $sample, ?string $reason, User $user): Worksheet
    {
        $this->assertEditable($worksheet);
        $member = $worksheet->members()->where('sample_id', $sample->id)->first();
        abort_unless($member, 404, 'Bu proba varaqda yo\'q');

        $member->forceFill($reason !== null ? [
            'status'        => 'retest',
            'retest_reason' => mb_substr($reason, 0, 500),
            'retest_by'     => $user->id,
            'retest_at'     => now(),
        ] : [
            'status'        => 'active',
            'retest_reason' => null,
            'retest_by'     => null,
            'retest_at'     => null,
        ])->save();

        $worksheet->forceFill(['updated_by' => $user->id])->save();
        $this->recalculate($worksheet->fresh('rows'));

        return $worksheet->fresh();
    }

    private function renumberRows(Worksheet $worksheet): void
    {
        $positions = WorksheetSample::where('worksheet_id', $worksheet->id)->pluck('position', 'sample_id');
        $rows = WorksheetRow::where('worksheet_id', $worksheet->id)->orderBy('row_no')->orderBy('id')->get()
            ->sortBy(fn ($r) => sprintf('%05d-%05d-%09d', $positions[$r->sample_id] ?? 99999, $r->row_no, $r->id))
            ->values();
        $par = [];
        foreach ($rows as $i => $r) {
            $par[$r->sample_id] = ($par[$r->sample_id] ?? 0) + 1;
            if ($r->row_no !== $i + 1 || (int) $r->parallel_no !== $par[$r->sample_id]) {
                $r->forceFill(['row_no' => $i + 1, 'parallel_no' => $par[$r->sample_id]])->save();
            }
        }
    }

    /** @return array{0:string,1:int,2:string} [seriya, tartib raqam, ko'rsatiladigan raqam] */
    public function nextNumber(WorksheetTemplate $template, int $year): array
    {
        $series = $template->series();

        // O'chirilganlar ham hisobga olinadi — raqam qayta berilmaydi
        $max = Worksheet::withTrashed()->where('series', $series)->where('year', $year)->max('seq');

        if ($max) {
            $seq = (int) $max + 1;
        } else {
            $hadBefore = Worksheet::withTrashed()->where('series', $series)->exists();
            $seq = $hadBefore ? 1 : max(1, (int) $template->number_start);
        }

        return [$series, $seq, $this->formatNumber($seq, $template)];
    }

    public function formatNumber(int $seq, WorksheetTemplate $template): string
    {
        $suffix = trim((string) $template->number_suffix);
        if ($suffix === '') {
            return $template->code . '-' . $seq;
        }

        // 501k, 279n — bitta harf qo'shib yoziladi; 458 k/m, 400 k/ch — bo'sh joy bilan
        return mb_strlen($suffix) === 1 ? $seq . $suffix : $seq . ' ' . $suffix;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $msg = $e->getMessage();

        return $code === '23000' || $code === '23505' || str_contains($msg, 'UNIQUE') || str_contains($msg, 'Duplicate')
            || str_contains($msg, '2627') || str_contains($msg, '2601');
    }

    public function defaultInputs(array $definition): array
    {
        $out = [];
        foreach ($definition['columns'] ?? [] as $c) {
            if (($c['scope'] ?? 'row') === 'row' && ($c['type'] ?? 'input') !== 'computed'
                && isset($c['default_value']) && $c['default_value'] !== '') {
                $out[$c['key']] = (string) $c['default_value'];
            }
        }

        return $out;
    }

    private function defaultHeader(array $definition): array
    {
        $out = [];
        foreach ($definition['params'] ?? [] as $p) {
            $out[$p['key']] = isset($p['default']) && $p['default'] !== '' ? (string) $p['default'] : null;
        }

        return $out;
    }

    /** Qatorga haqiqiy o'lchov kiritilganmi (standart qiymatlardan tashqari) */
    public function hasMeasurement(array $inputs, array $defaults): bool
    {
        foreach ($inputs as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (! array_key_exists($k, $defaults) || (string) $defaults[$k] !== (string) $v) {
                return true;
            }
        }

        return false;
    }

    // --------------------------------------------------------------------- saqlash

    /**
     * Kiritilgan ma'lumotlarni saqlaydi va server tomonda qayta hisoblaydi.
     * Qatorlar id bo'yicha yangilanadi: id'siz qator — yangi (qo'shimcha) parallel,
     * ro'yxatda kelmagan qator — o'chiriladi (faqat o'lchovi bo'lmasa).
     *
     * @param  array  $data  validatsiyadan o'tgan so'rov
     */
    public function save(Worksheet $worksheet, array $data, User $user): Worksheet
    {
        $definition = $worksheet->definition();
        $inputKeys = [];
        $numericKeys = [];
        foreach ($definition['columns'] ?? [] as $c) {
            if (($c['scope'] ?? 'row') !== 'row' || ($c['type'] ?? 'input') === 'computed') {
                continue;
            }
            $inputKeys[$c['key']] = true;
            if (($c['type'] ?? 'input') === 'input') {
                $numericKeys[$c['key']] = true;
            }
        }
        $paramKeys = [];
        foreach ($definition['params'] ?? [] as $p) {
            $paramKeys[$p['key']] = true;
        }
        $defaults = $this->defaultInputs($definition);

        $members = $worksheet->members()->with('sample:id,code')->get()->keyBy('sample_id');
        $existing = WorksheetRow::where('worksheet_id', $worksheet->id)->get()->keyBy('id');
        $payloadRows = array_values($data['rows'] ?? []);

        // Eski sahifa (qatorlarda sample_id yo'q) — bitta probali varaqda avvalgidek ishlaydi
        $legacy = ! collect($payloadRows)->contains(fn ($r) => is_array($r) && array_key_exists('sample_id', $r));
        if ($legacy && $members->count() > 1) {
            throw ValidationException::withMessages(['rows' => ['Sahifa eskirgan — brauzerda Ctrl+F5 bosib qayta oching']]);
        }
        $soleSample = $legacy ? (int) ($members->keys()->first() ?? $worksheet->sample_id) : null;

        $errors = [];
        $rows = [];
        foreach ($payloadRows as $i => $row) {
            $id = ! $legacy && isset($row['id']) && $row['id'] !== '' && $row['id'] !== null ? (int) $row['id'] : null;
            $ex = $id !== null ? $existing->get($id) : null;
            if ($id !== null && ! $ex) {
                $errors["rows.$i.id"] = ['Qator topilmadi — sahifani yangilang'];
                continue;
            }
            $sid = $legacy ? $soleSample : (int) ($row['sample_id'] ?? 0);
            if ($ex && $sid && (int) $ex->sample_id !== $sid) {
                $errors["rows.$i.sample_id"] = ["Parallelni boshqa probaga ko'chirib bo'lmaydi"];
                continue;
            }
            if ($ex) {
                $sid = (int) $ex->sample_id;
            }
            if (! $members->has($sid)) {
                $errors["rows.$i.sample_id"] = ["Bu proba varaqda yo'q"];
                continue;
            }

            $clean = [];
            foreach (($row['inputs'] ?? []) as $key => $raw) {
                if (! isset($inputKeys[$key]) || $raw === null || $raw === '') {
                    continue;
                }
                $raw = is_scalar($raw) ? trim((string) $raw) : '';
                if ($raw === '') {
                    continue;
                }
                if (isset($numericKeys[$key])) {
                    if (WorksheetCalculator::parseNumber($raw) === false) {
                        $errors["rows.$i.inputs.$key"] = ['Son emas: «' . $raw . '»'];
                        continue;
                    }
                    $raw = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], $raw);
                }
                $clean[$key] = mb_substr($raw, 0, 100);
            }

            $excluded = (bool) ($row['excluded'] ?? false);
            $note = isset($row['note']) ? trim(mb_substr((string) $row['note'], 0, 255)) : '';
            $note = $note === '' ? null : $note;

            // O'lchovli parallel endi hisobdan chiqarilyapti — sababi majburiy
            $wasExcluded = $ex ? (bool) $ex->excluded : false;
            if (! $legacy && $excluded && ! $wasExcluded && $this->hasMeasurement($clean, $defaults)
                && ($note === null || mb_strlen($note) < 3)) {
                $errors["rows.$i.note"] = ['Parallel nima uchun hisobga olinmasligini yozing'];
            }

            $rows[] = ['id' => $ex?->id, 'sample_id' => $sid, 'inputs' => $clean, 'excluded' => $excluded, 'note' => $note, 'order' => $i];
        }

        // Ro'yxatda kelmagan qatorlar o'chiriladi — o'lchovli qator o'chirilmaydi
        $keep = array_filter(array_column($rows, 'id'));
        $toDelete = $legacy ? $existing : $existing->filter(fn ($r) => ! in_array($r->id, $keep, true));
        if (! $legacy) {
            foreach ($toDelete as $r) {
                if ($this->hasMeasurement($r->inputs ?? [], $defaults)) {
                    $code = $members->get($r->sample_id)?->sample?->code ?? '';
                    $errors['rows'][] = "{$code}, {$r->parallel_no}-parallel: o'lchov kiritilgan qatorni o'chirib bo'lmaydi — «hisobga olinmaydi» deb belgilang";
                }
            }
        }

        // Har bir probada kamida bitta parallel qolsin
        $present = array_count_values(array_column($rows, 'sample_id'));
        foreach ($members as $sid => $m) {
            if (empty($present[$sid])) {
                $errors['rows'][] = ($m->sample?->code ?? "#{$sid}") . ': kamida bitta parallel bo\'lishi kerak';
            }
        }
        if (count($rows) > self::MAX_ROWS) {
            $errors['rows'][] = "Varaqda ko'pi bilan " . self::MAX_ROWS . ' ta qator bo\'lishi mumkin';
        }

        $header = [];
        foreach (($data['header_values'] ?? []) as $key => $raw) {
            if (! isset($paramKeys[$key])) {
                continue;
            }
            $raw = $raw === null ? null : trim((string) $raw);
            if ($raw !== null && $raw !== '' && WorksheetCalculator::parseNumber($raw) === false) {
                $errors["header_values.$key"] = ['Son emas: «' . $raw . '»'];
                continue;
            }
            $header[$key] = $raw === null || $raw === '' ? null : str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], $raw);
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // Bosqich imzolari — faqat shablonda bor bosqichlar; varaqqa bir marta (butun partiya uchun)
        $stages = [];
        foreach ($definition['stages'] ?? [] as $s) {
            $stages[$s['key']] = max(0, (int) ($s['signers'] ?? 1));
        }
        $signoffs = [];
        foreach (($data['signoffs'] ?? []) as $s) {
            $stage = (string) ($s['stage'] ?? '');
            $slot = (int) ($s['slot'] ?? 1);
            if (! isset($stages[$stage]) || $slot < 1 || $slot > max(1, $stages[$stage])) {
                continue;
            }
            $performerId = ! empty($s['performer_id']) ? (int) $s['performer_id'] : null;
            $performerName = trim((string) ($s['performer_name'] ?? ''));
            $performedAt = $s['performed_at'] ?? null;
            if (! $performerId && $performerName === '' && ! $performedAt) {
                continue;
            }
            $signoffs[$stage . '#' . $slot] = [
                'stage'          => $stage,
                'slot'           => $slot,
                'performer_id'   => $performerId,
                'performer_name' => $performerName !== '' ? mb_substr($performerName, 0, 191) : null,
                'performed_at'   => $performedAt ?: null,
                'recorded_by'    => $user->id,
            ];
            // [P16] Bosqich bajarilgan soat «HH:MM» (smenalar hisobi uchun) — ustun bo'lsa
            if (self::hasSignoffTime()) {
                $time = trim((string) ($s['performed_time'] ?? ''));
                $signoffs[$stage . '#' . $slot]['performed_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $time) ? substr($time, 0, 5) : null;
            }
        }

        // Tartib: probalar varaqdagi tartibida, proba ichida — kelgan tartibda
        $positions = $members->map(fn ($m) => (int) $m->position)->all();
        usort($rows, fn ($a, $b) => [$positions[$a['sample_id']] ?? 9999, $a['order']] <=> [$positions[$b['sample_id']] ?? 9999, $b['order']]);

        // Qo'shimcha parallel: varaq chop etilgandan keyin yoki probaga o'lchov kiritilgandan keyin qo'shilgani
        $hadData = [];
        foreach ($existing as $r) {
            if ($this->hasMeasurement($r->inputs ?? [], $defaults)) {
                $hadData[$r->sample_id] = true;
            }
        }
        $wasPrinted = $worksheet->printed_at !== null;

        DB::transaction(function () use ($worksheet, $data, $rows, $header, $signoffs, $user, $legacy, $existing, $toDelete, $hadData, $wasPrinted, $defaults) {
            foreach ($toDelete as $r) {
                $r->delete();
            }

            $par = [];
            foreach ($rows as $n => $r) {
                $par[$r['sample_id']] = ($par[$r['sample_id']] ?? 0) + 1;
                $attrs = [
                    'row_no'      => $n + 1,
                    'sample_id'   => $r['sample_id'],
                    'parallel_no' => $par[$r['sample_id']],
                    'inputs'      => $r['inputs'],
                    'excluded'    => $r['excluded'],
                    'note'        => $r['note'],
                ];
                if ($r['id']) {
                    $existing[$r['id']]->forceFill($attrs)->save();
                } else {
                    $extra = ! $legacy && ($wasPrinted || ! empty($hadData[$r['sample_id']]));
                    $worksheet->rows()->create($attrs + [
                        'computed' => [],
                        'is_extra' => $extra,
                        'added_by' => $legacy ? null : $user->id,
                    ]);
                }
            }

            WorksheetSignoff::where('worksheet_id', $worksheet->id)->delete();
            foreach ($signoffs as $s) {
                $worksheet->signoffs()->create($s);
            }

            $hasData = collect($rows)->contains(fn ($r) => $this->hasMeasurement($r['inputs'], $defaults));

            $status = $worksheet->status;
            if (in_array($status, ['draft', 'printed'], true) && $hasData) {
                $status = 'in_progress';
            }

            $worksheet->fill([
                'worksheet_date' => $data['worksheet_date'] ?? $worksheet->worksheet_date,
                'header_values'  => $header,
                'notes'          => $data['notes'] ?? null,
                'status'         => $status,
                'updated_by'     => $user->id,
            ])->save();

            $this->recalculate($worksheet->fresh('rows'));
        });

        return $worksheet->fresh();
    }

    /**
     * Server tomonda rasmiy hisob — natijani qatorlar va varaqqa yozadi.
     * results.groups — har bir proba bo'yicha (kalit: sample_id).
     */
    public function recalculate(Worksheet $worksheet): array
    {
        $retest = WorksheetSample::where('worksheet_id', $worksheet->id)->where('status', 'retest')
            ->pluck('sample_id')->map(fn ($id) => (string) $id)->all();

        $rows = $worksheet->rows->map(fn ($r) => [
            'values'   => $r->inputs ?? [],
            'excluded' => (bool) $r->excluded,
            'group'    => (int) ($r->sample_id ?: $worksheet->sample_id),
        ])->all();

        $result = WorksheetCalculator::calculate($worksheet->definition(), $worksheet->header_values ?? [], $rows);

        foreach ($worksheet->rows->values() as $i => $row) {
            $row->computed = $result['rows'][$i]['computed'] ?? [];
            $row->save();
        }

        // Ogohlantirish: formula xatosi, noto'g'ri kiritma yoki faol probada ±% buzilishi.
        // «Takroriy tahlil»dagi probaning farqi — kutilgan holat, ogohlantirish emas.
        $warn = ! empty($result['errors']);
        $groups = [];
        foreach ($result['groups'] as $g) {
            $key = (string) $g['key'];
            $groups[$key] = [
                'summary'      => (array) $g['summary'],
                'tolerance'    => (array) $g['tolerance'],
                'has_warnings' => (bool) $g['has_warnings'],
            ];
            if ($g['has_warnings'] && ! in_array($key, $retest, true)) {
                $warn = true;
            }
        }

        $worksheet->results = [
            'summary'       => (array) $result['summary'],
            'tolerance'     => (array) $result['tolerance'],
            'groups'        => $groups,
            'totals'        => (array) $result['totals'],
            'errors'        => $result['errors'],
            'rows'          => array_map(fn ($r) => [
                'invalid'          => (array) $r['invalid'],
                'out_of_tolerance' => $r['out_of_tolerance'],
            ], $result['rows']),
            'calculated_at' => now()->toDateTimeString(),
        ];
        $worksheet->has_warnings = $warn;
        $worksheet->save();

        $result['retest'] = $retest;

        return $result;
    }

    /** Faol (takroriy tahlilda bo'lmagan) probalardagi ±% buzilishlari: [sample_id => [ustun, ...]] */
    public function toleranceFailures(array $result): array
    {
        $out = [];
        foreach ($result['groups'] ?? [] as $g) {
            if (in_array((string) $g['key'], $result['retest'] ?? [], true)) {
                continue;
            }
            foreach ((array) $g['tolerance'] as $key => $t) {
                if (($t['ok'] ?? null) === false) {
                    $out[(string) $g['key']][] = $key;
                }
            }
        }

        return $out;
    }

    // -------------------------------------------------------------- yakunlash

    /** Majburiy kataklar to'ldirilganmi (hisobdan chiqarilgan qatorlar va takroriy tahlildagi probalar bundan mustasno) */
    public function missingRequired(Worksheet $worksheet): array
    {
        $required = [];
        foreach ($worksheet->definition()['columns'] ?? [] as $c) {
            if (($c['scope'] ?? 'row') === 'row' && ($c['type'] ?? 'input') === 'input' && ! empty($c['required'])) {
                $required[$c['key']] = $c['label_uz'] ?? $c['key'];
            }
        }

        $members = $worksheet->members()->with('sample:id,code')->get()->keyBy('sample_id');
        $multi = $members->count() > 1;

        $missing = [];
        foreach ($worksheet->rows as $row) {
            if ($row->excluded) {
                continue;
            }
            $m = $members->get($row->sample_id);
            if ($m && $m->isRetest()) {
                continue;
            }
            $where = $multi
                ? ($m?->sample?->code ?? '') . ', ' . ($row->parallel_no ?: $row->row_no) . '-parallel'
                : "{$row->row_no}-qator";
            foreach ($required as $key => $label) {
                $v = $row->inputs[$key] ?? null;
                if ($v === null || $v === '') {
                    $missing[] = "{$where}: {$label}";
                }
            }
        }

        return $missing;
    }

    // ------------------------------------------------------------- tasdiqlash

    /**
     * [P31] Natija yoziladigan proba elementi.
     *   1) shablon ustunidagi aniq element (element_id) probada bo'lsa — o'sha;
     *   2) bo'lmasa — probadagi shu belgili (masalan, «Au») elementlar ichidan YAGONASI
     *      (katalogda bir xil belgili ikki element bo'lsa: shablonda biri, probada boshqasi);
     *   3) bir nechta yoki hech biri — yozilmaydi, sababi qaytariladi (taxmin qilinmaydi).
     *
     * @return array{0: ?SampleElement, 1: ?string}  [element, izoh/sabab]
     */
    public static function resolveSampleElement(int $sampleId, int $elementId, ?string $symbol = null): array
    {
        $exact = SampleElement::where('sample_id', $sampleId)->where('element_id', $elementId)->first();
        if ($exact) {
            return [$exact, null];
        }
        $symbol = $symbol ?: \App\Models\Element::whereKey($elementId)->value('symbol');
        if (! $symbol) {
            return [null, "probada bu element ko'rsatilmagan"];
        }
        $same = SampleElement::query()->where('sample_id', $sampleId)
            ->whereIn('element_id', fn ($q) => $q->select('id')->from('elements')->where('symbol', $symbol))
            ->get();
        if ($same->count() === 1) {
            return [$same->first(), "probadagi «{$symbol}» elementiga"];
        }
        if ($same->count() > 1) {
            return [null, "probada «{$symbol}» bir nechta (" . $same->count() . " ta) — qaysi biriga yozish noaniq, qo'lda kiriting"];
        }

        return [null, "probada bu element ko'rsatilmagan"];
    }

    /**
     * Varaqni tasdiqlaydi; ixtiyoriy ravishda har bir faol probaning yakuniy natijasini o'ziga yozadi.
     *
     * @return array{pushed: array, skipped: array}
     */
    public function approve(Worksheet $worksheet, User $user, bool $pushResults): array
    {
        $report = ['pushed' => [], 'skipped' => []];

        // Nazorat varag'i — natija probaga yozilmaydi (faqat asosiy natija bilan solishtiriladi)
        if (self::hasKind() && $worksheet->isControl()) {
            $pushResults = false;
            $report['skipped'][] = "Nazorat varag'i — natijalar probaga o'tkazilmaydi (asosiy natija bilan solishtirish uchun)";
        }

        DB::transaction(function () use ($worksheet, $user, $pushResults, &$report) {
            $result = $this->recalculate($worksheet->fresh('rows'));
            $groups = [];
            foreach ($result['groups'] as $g) {
                $groups[(string) $g['key']] = (array) $g['summary'];
            }

            $members = $worksheet->members()->with('sample')->orderBy('position')->get();
            $multi = $members->count() > 1;
            $snapshot = $worksheet->definition();
            $method = trim('Ishchi varaq № ' . $worksheet->number . ' (' . ($snapshot['code'] ?? '') . ')');

            foreach ($members as $m) {
                $sample = $m->sample;
                $prefix = $multi ? (($sample?->code ?? '#' . $m->sample_id) . ': ') : '';

                if ($m->isRetest()) {
                    $report['skipped'][] = "{$prefix}takroriy tahlilga qaytarilgan — natija o'tkazilmadi";
                    continue;
                }
                if (! $pushResults || ! $sample) {
                    continue;
                }

                $summary = $groups[(string) $m->sample_id] ?? [];
                $pushedHere = false;
                foreach ($snapshot['columns'] ?? [] as $c) {
                    if (($c['scope'] ?? 'row') !== 'summary' || empty($c['element_id'])) {
                        continue;
                    }
                    $label = ($c['element_symbol'] ?? null) ?: $c['label_uz'];
                    $value = $summary[$c['key']] ?? null;
                    if ($value === null) {
                        $report['skipped'][] = "{$prefix}{$label}: natija hisoblanmagan";
                        continue;
                    }

                    // [P31] aniq element bo'lmasa — probadagi bir xil belgili YAGONA elementga (katalogda Au ikki xil bo'lsa)
                    [$se, $why] = self::resolveSampleElement((int) $sample->id, (int) $c['element_id'], $c['element_symbol'] ?? null);
                    if (! $se) {
                        $report['skipped'][] = "{$prefix}{$label}: {$why}";
                        continue;
                    }

                    $se->forceFill([
                        'result_value'      => $value,
                        'result_unit'       => $c['unit'] ?: $se->result_unit,
                        'status'            => 'done',
                        'result_entered_by' => $user->id,
                        'result_entered_at' => now(),
                        'method'            => mb_substr($method, 0, 191),
                        'notes'             => "Ishchi varaqdan avtomatik: {$worksheet->number}",
                    ])->save();

                    $report['pushed'][] = "{$prefix}{$label} = {$value}" . ($c['unit'] ? " {$c['unit']}" : '') . ($why ? " ({$why})" : '');
                    $pushedHere = true;
                }

                if ($pushedHere) {
                    $m->forceFill(['result_pushed_at' => now()])->save();
                    if ($sample->status === 'assigned') {
                        $sample->status = 'in_progress';
                        $sample->save();
                    }
                    if (method_exists($sample, 'refreshStatus')) {
                        $sample->unsetRelation('sampleElements');
                        $sample->refreshStatus();
                    }
                }
            }

            $worksheet->forceFill([
                'status'            => 'approved',
                'approved_by'       => $user->id,
                'approved_at'       => now(),
                'completed_at'      => $worksheet->completed_at ?: now(),
                'results_pushed_at' => $report['pushed'] ? now() : $worksheet->results_pushed_at,
                'updated_by'        => $user->id,
            ])->save();
        });

        return $report;
    }
}
