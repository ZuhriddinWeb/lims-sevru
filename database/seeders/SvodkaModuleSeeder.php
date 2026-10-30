<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Buyurtmachi svodkasi — Paket 29. Qayta ishga tushirish xavfsiz:
 *   php artisan db:seed --class=SvodkaModuleSeeder --force
 *
 *  - ruxsat (faqat yo'q bo'lsa): svodka.release — svodkani chiqarish (laboratoriya boshlig'iga, faqat yangi yaratilganda)
 *  - menyu (faqat yo'q bo'lsa): «Svodka» (buyurtmachi, portal.view) va «Svodkalar» (laboratoriya, svodka.release)
 *  - svodka rejimi har bo'lim uchun alohida yoqiladi: «Svodkalar» → bo'lim → Sozlama (customer_departments.manage)
 */
class SvodkaModuleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $p = Permission::firstOrCreate(['name' => 'svodka.release'], [
                'module'     => 'svodka',
                'ability'    => 'release',
                'group'      => 'lab',
                'label_ru'   => 'Сводка заказчика — Выпуск (без изменения результатов)',
                'label_uz'   => "Buyurtmachi svodkasi — Chiqarish (natijalarni o'zgartirmasdan)",
                'label_en'   => 'Customer summary — Release (results cannot be changed)',
                'sort_order' => (int) Permission::max('sort_order') + 1,
            ]);
            if ($p->wasRecentlyCreated && ($head = Role::where('name', 'lab_head')->first())) {
                $head->permissions()->syncWithoutDetaching([$p->id]);
            }

            MenuItem::firstOrCreate(['key' => 'portal_svodka'], [
                'title_ru' => 'Сводка', 'title_uz' => 'Svodka', 'title_en' => 'Summary',
                'icon' => 'table', 'route' => 'portal-svodka', 'permission' => 'portal.view',
                'parent_id' => null, 'sort_order' => 1, 'is_active' => true,
            ]);
            MenuItem::firstOrCreate(['key' => 'svodka_releases'], [
                'title_ru' => 'Сводки заказчиков', 'title_uz' => 'Svodkalar', 'title_en' => 'Customer summaries',
                'icon' => 'clipboard-check', 'route' => 'svodka-releases', 'permission' => 'svodka.release',
                'parent_id' => null, 'sort_order' => 2, 'is_active' => true,
            ]);
        });
    }
}
