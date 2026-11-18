<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [Paket 37] Grafik jadvallari (Excel ko'rinishida). Qayta ishga tushirish xavfsiz (faqat yo'q narsani yaratadi):
 *   php artisan db:seed --class=ScheduleSheetModuleSeeder --force   (lims:upgrade o'zi chaqiradi)
 *
 *  - schedule_sheets.view   — jadvallarni ko'rish va Excel (lab_head, operator, analyst, viewer — rahbariyat)
 *  - schedule_sheets.enter  — oq kataklarga qiymat kiritish (lab_head, operator, analyst)
 *  - schedule_sheets.manage — jadval shablonlari: ustunlar va formulalar (faqat admin; kerak bo'lsa rolga bering)
 *  - menyu: «Grafik jadvallari» (laboratoriya), «Grafik jadvali konstruktori» (Kataloglar ichida), «Grafik jadvali» (buyurtmachi)
 */
class ScheduleSheetModuleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $perms = [
                'schedule_sheets.view'   => [['lab_head', 'operator', 'analyst', 'viewer'], 'view',
                    "Grafik jadvallari — Ko'rish va Excel", 'Таблицы по графику — Просмотр и Excel', 'Schedule sheets — View and Excel'],
                'schedule_sheets.enter'  => [['lab_head', 'operator', 'analyst'], 'enter',
                    'Grafik jadvallari — Qiymat kiritish', 'Таблицы по графику — Ввод значений', 'Schedule sheets — Enter values'],
                'schedule_sheets.manage' => [[], 'manage',
                    'Grafik jadvallari — Shablon va formulalar', 'Таблицы по графику — Шаблоны и формулы', 'Schedule sheets — Templates and formulas'],
            ];
            foreach ($perms as $name => [$roles, $ability, $uz, $ru, $en]) {
                $p = Permission::firstOrCreate(['name' => $name], [
                    'module' => 'schedule_sheets', 'ability' => $ability, 'group' => 'lab',
                    'label_uz' => $uz, 'label_ru' => $ru, 'label_en' => $en,
                    'sort_order' => (int) Permission::max('sort_order') + 1,
                ]);
                if ($p->wasRecentlyCreated && $roles) {
                    foreach (Role::whereIn('name', $roles)->get() as $role) {
                        $role->permissions()->syncWithoutDetaching([$p->id]);
                    }
                }
            }

            MenuItem::firstOrCreate(['key' => 'schedule_sheets'], [
                'title_uz' => 'Grafik jadvallari', 'title_ru' => 'Таблицы по графику', 'title_en' => 'Schedule sheets',
                'icon' => 'table', 'route' => 'schedule-sheets', 'permission' => 'schedule_sheets.view',
                'parent_id' => null, 'sort_order' => 3, 'is_active' => true,
            ]);
            $catalog = MenuItem::where('key', 'catalog')->first();
            MenuItem::firstOrCreate(['key' => 'schedule_sheet_templates'], [
                'title_uz' => 'Grafik jadvali konstruktori', 'title_ru' => 'Конструктор таблиц графика', 'title_en' => 'Schedule sheet builder',
                'icon' => 'table-properties', 'route' => 'schedule-sheet-templates', 'permission' => 'schedule_sheets.manage',
                'parent_id' => $catalog?->id, 'sort_order' => 90, 'is_active' => true,
            ]);
            MenuItem::firstOrCreate(['key' => 'portal_schedule_sheets'], [
                'title_uz' => 'Grafik jadvali', 'title_ru' => 'Таблица графика', 'title_en' => 'Schedule sheet',
                'icon' => 'table', 'route' => 'portal-schedule-sheets', 'permission' => 'portal.view',
                'parent_id' => null, 'sort_order' => 0, 'is_active' => true,
            ]);
        });
    }
}
