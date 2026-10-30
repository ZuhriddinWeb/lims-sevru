<?php

namespace App\Services\Samples;

use App\Models\Sample;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Probani qabul qilishdagi qo'shimcha maydonlar (SampleController'ga ulanadi):
 *   customer_department_id — buyurtmachi bo'lim
 *   customer_code          — bo'lim bergan shifr
 *   received_quantity      — qabul qilinganda o'lchangan miqdor
 *   quantity_unit          — birligi (g, kg, mg, ml, l)
 *
 * SampleController'da 4 joyga bittadan qator qo'shiladi (OQISH.txt ga qarang):
 *   store/update validatsiyasiga  ...SampleIntake::rules()
 *   yaratish/yangilashdan keyin    SampleIntake::apply($sample, $data)
 *   index filtrlari                SampleIntake::filter($query, $request)
 */
class SampleIntake
{
    // [P24] handed_over_by — namunani topshirgan shaxs (reyestr: «topshirgan FIO»)
    public const FIELDS = ['customer_department_id', 'customer_code', 'received_quantity', 'quantity_unit', 'handed_over_by'];

    public static function rules(?Sample $ignore = null): array
    {
        return [
            'customer_department_id' => ['nullable', 'integer',
                Rule::exists('customer_departments', 'id')->whereNull('deleted_at')],
            'customer_code'          => ['nullable', 'string', 'max:64', function ($attribute, $value, $fail) use ($ignore) {
                $value = trim((string) $value);
                $dept = request()->input('customer_department_id');
                if ($value === '' || ! $dept) {
                    return;
                }
                // [P26] Bo'lim shifri har oy qaytadan boshlanishi mumkin (908 — oktyabrda ham, noyabrda ham).
                // Takror faqat SHU OYDA taqiqlanadi: bitta bo'limning shu oydagi faol (arxivlanmagan) probalari ichida.
                // Oy — probaning ro'yxatga olingan oyi (period_month): yangi proba uchun joriy oy,
                // tahrirlashda — probaning o'z oyi.
                $period = $ignore?->period_month ?: now()->format('Y-m');
                // [P30] guruh tanlangan bo'lsa — takror faqat shu guruh ichida taqiqlanadi
                $group = request()->has('assigned_group_id') ? request()->input('assigned_group_id') : $ignore?->assigned_group_id;
                $group = $group ? (int) $group : null;
                $dup = self::duplicateCode((int) $dept, $value, $period, $group, $ignore?->id);
                if ($dup) {
                    $fail(self::duplicateMessage($value, $period, $dup, $group));
                }
            }],
            'received_quantity'      => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'quantity_unit'          => ['nullable', Rule::in(QuantityUnit::UNITS)],
            'handed_over_by'         => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * [P26] Shu bo'limning shu oydagi faol probasida bunday shifr bormi.
     * [P30] Guruh berilsa — faqat shu guruhga biriktirilgan probalar ichida (bir xil namunalar turli guruhlarga
     * bir xil shifr bilan yuboriladi: Bo'tana va Bo'tana quruq faza). Guruhsiz — avvalgidek, barcha probalar ichida.
     *
     * @return string|null topilgan probaning laboratoriya raqami
     */
    public static function duplicateCode(int $deptId, string $code, string $period, ?int $groupId = null, ?int $ignoreId = null): ?string
    {
        return Sample::query()
            ->where('customer_department_id', $deptId)
            ->where('customer_code', $code)
            ->where('period_month', $period)
            ->where('status', '!=', 'archived')
            ->when($groupId, fn ($q) => $q->where('assigned_group_id', $groupId))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->value('code');
    }

    public static function duplicateMessage(string $code, string $period, string $dup, ?int $groupId = null): string
    {
        if ($groupId) {
            $name = \Illuminate\Support\Facades\DB::table('laboratory_groups')->where('id', $groupId)->value('name_uz');

            return "Bu bo'limning «{$code}» shifrli probasi {$period} oyida «" . ($name ?: "#$groupId") . "» guruhida allaqachon ro'yxatda bor: {$dup}";
        }

        return "Bu bo'limning «{$code}» shifrli probasi {$period} oyida allaqachon ro'yxatda bor: {$dup}";
    }

    /** Qabul maydonlarini probaga yozadi (faqat so'rovda kelganlarini) */
    public static function apply(Sample $sample, array $data): Sample
    {
        $attrs = [];
        foreach (self::FIELDS as $key) {
            if (array_key_exists($key, $data)) {
                $attrs[$key] = $data[$key] === '' ? null : $data[$key];
            }
        }
        if (! $attrs) {
            return $sample;
        }

        if (array_key_exists('customer_department_id', $attrs) && $attrs['customer_department_id'] !== null) {
            $attrs['customer_department_id'] = (int) $attrs['customer_department_id'];
        }
        if (array_key_exists('handed_over_by', $attrs)) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('samples', 'handed_over_by')) {
                unset($attrs['handed_over_by']); // migratsiya hali bajarilmagan — boshqa maydonlar saqlansin
            } elseif ($attrs['handed_over_by'] !== null) {
                $attrs['handed_over_by'] = trim((string) $attrs['handed_over_by']) ?: null;
            }
        }
        if (! $attrs) {
            return $sample;
        }
        if (array_key_exists('customer_code', $attrs) && $attrs['customer_code'] !== null) {
            $attrs['customer_code'] = trim((string) $attrs['customer_code']) ?: null;
        }
        if (array_key_exists('received_quantity', $attrs) && $attrs['received_quantity'] !== null) {
            $attrs['received_quantity'] = (float) $attrs['received_quantity'];
            if (empty($attrs['quantity_unit']) && empty($sample->quantity_unit)) {
                $attrs['quantity_unit'] = 'g';
            }
        }

        $sample->forceFill($attrs)->save();

        return $sample;
    }

    /** Probalar ro'yxati: bo'lim nomi + bo'lim/shifr bo'yicha filtr */
    public static function filter(Builder $query, Request $request): Builder
    {
        $query->with('customerDepartment:id,code,name');

        if ($request->filled('customer_department_id')) {
            $query->where('customer_department_id', (int) $request->input('customer_department_id'));
        }
        if ($code = trim((string) $request->input('customer_code'))) {
            $query->where('customer_code', 'like', "%{$code}%");
        }

        return $query;
    }
}
