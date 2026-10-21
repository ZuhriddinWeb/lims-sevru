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
                // Bitta bo'limning faol (arxivlanmagan) probalarida shifr takrorlanmasin
                $dup = Sample::query()
                    ->where('customer_department_id', (int) $dept)
                    ->where('customer_code', $value)
                    ->where('status', '!=', 'archived')
                    ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))
                    ->value('code');
                if ($dup) {
                    $fail("Bu bo'limning «{$value}» shifrli probasi allaqachon ro'yxatda bor: {$dup}");
                }
            }],
            'received_quantity'      => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'quantity_unit'          => ['nullable', Rule::in(QuantityUnit::UNITS)],
            'handed_over_by'         => ['nullable', 'string', 'max:191'],
        ];
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
