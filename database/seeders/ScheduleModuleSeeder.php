<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [Paket 36] Grafik bo'yicha namunalar. Qayta ishga tushirish xavfsiz (faqat yo'q narsani yaratadi):
 *   php artisan db:seed --class=ScheduleModuleSeeder --force   (lims:upgrade o'zi chaqiradi)
 *
 *  - schedules.receive — «Grafik bo'yicha qabul»: kunning partiyalari, «Keldi» (lab_head, operator — faqat yangi yaratilganda)
 *  - schedules.manage  — bo'limlarga grafik rejimini yoqish, kechikish daqiqasi (lab_head)
 *  - menyu: «Grafik bo'yicha qabul» (laboratoriya) va «Grafigim» (buyurtmachi; faqat grafik yoqilgan bo'linmaga ko'rinadi)
 */
class ScheduleModuleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $perms = [
                'schedules.receive' => [['lab_head', 'operator'], 'receive',
                    "Grafik bo'yicha namunalar — Qabul (kunning partiyalari, «Keldi»)",
                    'Пробы по графику — Приём (партии дня, «Пришли»)', 'Scheduled samples — Receive (daily batches, «Arrived»)'],
                'schedules.manage'  => [['lab_head'], 'manage',
                    "Grafik bo'yicha namunalar — Sozlash (bo'limlarga yoqish, kechikish)",
                    'Пробы по графику — Настройка (включение для подразделений, опоздание)', 'Scheduled samples — Settings (enable per department, lateness)'],
            ];
            foreach ($perms as $name => [$roles, $ability, $uz, $ru, $en]) {
                $p = Permission::firstOrCreate(['name' => $name], [
                    'module' => 'schedules', 'ability' => $ability, 'group' => 'lab',
                    'label_uz' => $uz, 'label_ru' => $ru, 'label_en' => $en,
                    'sort_order' => (int) Permission::max('sort_order') + 1,
                ]);
                if ($p->wasRecentlyCreated) {
                    foreach (Role::whereIn('name', $roles)->get() as $role) {
                        $role->permissions()->syncWithoutDetaching([$p->id]);
                    }
                }
            }

            MenuItem::firstOrCreate(['key' => 'lab_schedule'], [
                'title_uz' => "Grafik bo'yicha qabul", 'title_ru' => 'Приём по графику', 'title_en' => 'Scheduled intake',
                'icon' => 'calendar-clock', 'route' => 'lab-schedule', 'permission' => 'schedules.receive',
                'parent_id' => null, 'sort_order' => 2, 'is_active' => true,
            ]);
            MenuItem::firstOrCreate(['key' => 'portal_schedule'], [
                'title_uz' => 'Grafigim', 'title_ru' => 'Мой график', 'title_en' => 'My schedule',
                'icon' => 'calendar-clock', 'route' => 'portal-schedule', 'permission' => 'portal.view',
                'parent_id' => null, 'sort_order' => 0, 'is_active' => true,
            ]);
        });
    }
}
