<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 36 / 39] Grafik bo'yicha namunalar va buyurtmalar sozlamasi. Qayta ishga tushirish xavfsiz (faqat yo'q narsani yaratadi):
 *   php artisan db:seed --class=ScheduleModuleSeeder --force   (lims:upgrade o'zi chaqiradi)
 *
 *  - schedules.manage             — bo'linmalarga grafik rejimini yoqish (lab_head)
 *  - sample_requests.settings     — [P39] «Buyurtma sozlamalari»: muddatlar, yopish sabablari (lab_head)
 *  - sample_requests.late_entry   — [P39] chegaradan (sukut 72 soat) eski qabulni kiritish (lab_head)
 *  - sample_requests.control      — [P39] «Buyurtmalar nazorati» hisoboti (lab_head, viewer)
 *  - menyu: «Grafigim» (buyurtmachi; faqat grafik yoqilgan bo'linmaga), «Buyurtma sozlamalari», «Buyurtmalar nazorati»
 *  - yopish sabablari — jadval bo'sh bo'lsa, boshlang'ich ro'yxat
 * [P39] «Grafik bo'yicha qabul» sahifasi olib tashlandi — grafik buyurtmalari oddiy «Buyurtmalar» da.
 */
class ScheduleModuleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $perms = [
                'schedules.manage' => [['lab_head'], 'schedules', 'manage',
                    "Grafik bo'yicha namunalar — Sozlash (bo'limlarga yoqish, kechikish)",
                    'Пробы по графику — Настройка (включение для подразделений, опоздание)', 'Scheduled samples — Settings (enable per department, lateness)'],
                'sample_requests.settings' => [['lab_head'], 'sample_requests', 'settings',
                    'Buyurtmalar — Sozlamalar (muddatlar, yopish sabablari)',
                    'Заявки — Настройки (сроки, причины закрытия)', 'Requests — Settings (deadlines, close reasons)'],
                'sample_requests.late_entry' => [['lab_head'], 'sample_requests', 'late_entry',
                    'Buyurtmalar — Kechiktirib kiritish (chegaradan eski qabul)',
                    'Заявки — Поздний ввод (приём старше порога)', 'Requests — Late entry (older than the limit)'],
                'sample_requests.control' => [['lab_head', 'viewer'], 'sample_requests', 'control',
                    'Buyurtmalar — Nazorat hisoboti',
                    'Заявки — Отчёт контроля', 'Requests — Control report'],
            ];
            foreach ($perms as $name => [$roles, $module, $ability, $uz, $ru, $en]) {
                $p = Permission::firstOrCreate(['name' => $name], [
                    'module' => $module, 'ability' => $ability, 'group' => 'lab',
                    'label_uz' => $uz, 'label_ru' => $ru, 'label_en' => $en,
                    'sort_order' => (int) Permission::max('sort_order') + 1,
                ]);
                if ($p->wasRecentlyCreated) {
                    foreach (Role::whereIn('name', $roles)->get() as $role) {
                        $role->permissions()->syncWithoutDetaching([$p->id]);
                    }
                }
            }

            MenuItem::firstOrCreate(['key' => 'portal_schedule'], [
                'title_uz' => 'Grafigim', 'title_ru' => 'Мой график', 'title_en' => 'My schedule',
                'icon' => 'calendar-clock', 'route' => 'portal-schedule', 'permission' => 'portal.view',
                'parent_id' => null, 'sort_order' => 0, 'is_active' => true,
            ]);
            MenuItem::firstOrCreate(['key' => 'request_control'], [
                'title_uz' => 'Buyurtmalar nazorati', 'title_ru' => 'Контроль заявок', 'title_en' => 'Request control',
                'icon' => 'clipboard-check', 'route' => 'request-control', 'permission' => 'sample_requests.control',
                'parent_id' => null, 'sort_order' => 3, 'is_active' => true,
            ]);
            MenuItem::firstOrCreate(['key' => 'request_settings'], [
                'title_uz' => 'Buyurtma sozlamalari', 'title_ru' => 'Настройки заявок', 'title_en' => 'Request settings',
                'icon' => 'settings-2', 'route' => 'request-settings', 'permission' => 'sample_requests.settings',
                'parent_id' => MenuItem::where('key', 'catalog')->value('id'), 'sort_order' => 95, 'is_active' => true,
            ]);

            if (Schema::hasTable('close_reasons') && ! DB::table('close_reasons')->exists()) {
                $now = now();
                $list = [
                    ["Texnologik jarayon to'xtagan", 'Технологический процесс остановлен', 'Process stopped', false],
                    ["Namuna olish nuqtasi ishlamagan", 'Точка отбора не работала', 'Sampling point not operating', false],
                    ["Uskuna ta'mirda", 'Оборудование в ремонте', 'Equipment under repair', false],
                    ["Namuna oluvchi xodim yo'q", 'Нет пробоотборщика', 'No sampler available', false],
                    ["Namuna boshqa buyurtma bilan topshirilgan", 'Проба сдана по другой заявке', 'Delivered under another request', false],
                    ['Boshqa (izoh bilan)', 'Другое (с комментарием)', 'Other (with a note)', true],
                ];
                foreach ($list as $i => [$uz, $ru, $en, $note]) {
                    DB::table('close_reasons')->insert([
                        'name_uz' => $uz, 'name_ru' => $ru, 'name_en' => $en, 'needs_note' => $note,
                        'sort_order' => ($i + 1) * 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }
}
