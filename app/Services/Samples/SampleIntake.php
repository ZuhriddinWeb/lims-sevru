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
    // [P33] received_by — namunani qabul qilib olgan laboratoriya xodimi (ro'yxatdan tanlanadi)
    public const FIELDS = ['customer_department_id', 'customer_code', 'received_quantity', 'quantity_unit', 'handed_over_by', 'received_by'];

    public static function rules(?Sample $ignore = null): array
    {
        return [
            'customer_department_id' => ['nullable', 'integer',
                Rule::exists('customer_departments', 'id')->whereNull('deleted_at')],
            // [P33] shifr cheklovsiz takrorlanadi (har kuni bir xil nuqta — bir xil shifr). Probani laboratoriya
            // raqami (PR-YYYYMM-NNNN) ajratib turadi. duplicateCode() endi faqat ogohlantirish uchun ishlatiladi.
            'customer_code'          => ['nullable', 'string', 'max:64'],
            'received_quantity'      => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'quantity_unit'          => ['nullable', Rule::in(QuantityUnit::UNITS)],
            'handed_over_by'         => ['nullable', 'string', 'max:191'],
            'received_by'            => ['nullable', 'integer', function ($attribute, $value, $fail) {
                if ($value && ! self::isReceiver((int) $value, request()->user())) {
                    $fail("Qabul qilib olgan xodim ro'yxatda yo'q");
                }
            }],
        ];
    }

    /**
     * [P33] «Kim qabul qilib oldi» ro'yxati: faol laboratoriya xodimlari (buyurtmachi hisoblari emas).
     * Xodim laboratoriyaga biriktirilgan bo'lsa — o'sha laboratoriya xodimlari (laboratoriyasiz admin ham).
     *
     * @return array<int, array{id:int, name:string, position:?string}>
     */
    public static function receivers(?\App\Models\User $for): array
    {
        $portal = app(CustomerPortal::class);
        $labId = $for?->laboratory_id ? (int) $for->laboratory_id : null;

        return \App\Models\User::query()
            ->with('role.permissions')
            ->where('is_active', true)
            ->when($labId, fn ($q) => $q->where(fn ($w) => $w->where('laboratory_id', $labId)->orWhereNull('laboratory_id')))
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'role_id', 'laboratory_id'])
            ->filter(fn ($u) => ! $portal->isPortalUser($u) && ($u->isAdmin() || $u->hasPermission('samples.view')))
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name, 'position' => $u->position])
            ->values()
            ->all();
    }

    public static function isReceiver(int $userId, ?\App\Models\User $for): bool
    {
        return in_array($userId, array_column(self::receivers($for), 'id'), true);
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
        if (array_key_exists('received_by', $attrs)) { // [P33]
            if (! \Illuminate\Support\Facades\Schema::hasColumn('samples', 'received_by')) {
                unset($attrs['received_by']);
            } elseif ($attrs['received_by'] !== null) {
                $attrs['received_by'] = (int) $attrs['received_by'] ?: null;
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
