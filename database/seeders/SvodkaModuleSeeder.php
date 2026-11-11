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
 *  - [P31] ruxsat svodka.view (hech kimga berilmaydi) va menyu «Bo'limlar svodkasi» — xodim svodkani buyurtmachidek
 *    ko'radi (faqat ko'rish); admin «Rollar va ruxsat» da kerakli rolga beradi
 *  - [P32] ruxsat results.analysis — «Natijalar tahlili» (laboratoriya boshlig'iga, faqat yangi yaratilganda);
 *    eski «AI tahlil» menyu bandi (suv/havo kuzatuvlari) yangi sahifaga yo'naltiriladi — faqat admin uni
 *    o'zgartirmagan bo'lsa (yo'li hali «ai-analysis»); bo'lmasa yangi band yaratiladi
 *  - [P33] menyu «Shablonlarim» (buyurtmachi, portal.request); o'zbekcha nomlar «Svodka» → «Jamlanma hisobot»
 *    (faqat eski standart nom turgan bo'lsa)
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
                'label_uz'   => "Buyurtmachi jamlanma hisoboti — Chiqarish (natijalarni o'zgartirmasdan)",
                'label_en'   => 'Customer summary — Release (results cannot be changed)',
                'sort_order' => (int) Permission::max('sort_order') + 1,
            ]);
            if ($p->wasRecentlyCreated && ($head = Role::where('name', 'lab_head')->first())) {
                $head->permissions()->syncWithoutDetaching([$p->id]);
            }

            // [P31] svodkani ko'rish (faqat ko'rish, buyurtmachidek) — admin istalgan rolga beradi; standart: hech kimga
            Permission::firstOrCreate(['name' => 'svodka.view'], [
                'module'     => 'svodka',
                'ability'    => 'view',
                'group'      => 'lab',
                'label_ru'   => 'Сводка заказчика — Просмотр (как заказчик, с названиями точек)',
                'label_uz'   => "Buyurtmachi jamlanma hisoboti — Ko'rish (buyurtmachidek, nuqta nomlari bilan)",
                'label_en'   => 'Customer summary — View (as the customer, with point names)',
                'sort_order' => (int) Permission::max('sort_order') + 1,
            ]);
            MenuItem::firstOrCreate(['key' => 'svodka_view'], [
                'title_ru' => 'Сводки подразделений', 'title_uz' => "Bo'limlar jamlanma hisoboti", 'title_en' => 'Department summaries',
                'icon' => 'table', 'route' => 'portal-svodka', 'permission' => 'svodka.view',
                'parent_id' => null, 'sort_order' => 2, 'is_active' => true,
            ]);

            // [P32] Natijalar tahlili — proba natijalari dinamikasi (o'z tarixiga nisbatan)
            $ra = Permission::firstOrCreate(['name' => 'results.analysis'], [
                'module'     => 'svodka',
                'ability'    => 'analysis',
                'group'      => 'lab',
                'label_ru'   => 'Анализ результатов (ИИ) — Просмотр (динамика по точкам, с названиями точек)',
                'label_uz'   => "Natijalar tahlili (AI) — Ko'rish (nuqtalar bo'yicha dinamika, nuqta nomlari bilan)",
                'label_en'   => 'Result analysis (AI) — View (trends by point, with point names)',
                'sort_order' => (int) Permission::max('sort_order') + 1,
            ]);
            if ($ra->wasRecentlyCreated && ($head = Role::where('name', 'lab_head')->first())) {
                $head->permissions()->syncWithoutDetaching([$ra->id]);
            }
            $titles = ['title_ru' => 'Анализ результатов (ИИ)', 'title_uz' => 'Natijalar tahlili (AI)', 'title_en' => 'Result analysis (AI)'];
            $ai = MenuItem::where('key', 'ai_analysis')->first();
            if ($ai && $ai->route === 'ai-analysis') {
                // eski standart nom o'zgartirilmagan bo'lsa — yangi nom; admin o'z nomini qo'ygan bo'lsa — saqlanadi
                $keepTitle = ! in_array($ai->title_uz, ['AI tahlil', null, ''], true);
                $ai->update(['route' => 'result-analysis', 'permission' => 'results.analysis', 'icon' => $ai->icon ?: 'sparkles']
                    + ($keepTitle ? [] : $titles));
            } elseif (! $ai && ! MenuItem::where('route', 'result-analysis')->exists()) {
                MenuItem::create(['key' => 'ai_analysis'] + $titles + [
                    'icon' => 'sparkles', 'route' => 'result-analysis', 'permission' => 'results.analysis',
                    'parent_id' => null, 'sort_order' => 7, 'is_active' => true,
                ]);
            }

            MenuItem::firstOrCreate(['key' => 'portal_svodka'], [
                'title_ru' => 'Сводка', 'title_uz' => 'Jamlanma hisobot', 'title_en' => 'Summary',
                'icon' => 'table', 'route' => 'portal-svodka', 'permission' => 'portal.view',
                'parent_id' => null, 'sort_order' => 1, 'is_active' => true,
            ]);
            MenuItem::firstOrCreate(['key' => 'svodka_releases'], [
                'title_ru' => 'Сводки заказчиков', 'title_uz' => 'Jamlanma hisobotlar', 'title_en' => 'Customer summaries',
                'icon' => 'clipboard-check', 'route' => 'svodka-releases', 'permission' => 'svodka.release',
                'parent_id' => null, 'sort_order' => 2, 'is_active' => true,
            ]);

            // [P33] Buyurtmachi: «Shablonlarim» — bo'linma shablonlari (Buyurtmalarim dan keyin)
            MenuItem::firstOrCreate(['key' => 'portal_templates'], [
                'title_ru' => 'Мои шаблоны', 'title_uz' => 'Shablonlarim', 'title_en' => 'My templates',
                'icon' => 'layout-template', 'route' => 'portal-templates', 'permission' => 'portal.request',
                'parent_id' => null, 'sort_order' => 0, 'is_active' => true,
            ]);

            // [P33] O'zbekcha «Svodka» → «Jamlanma hisobot». Faqat eski standart nom turgan bo'lsa almashtiriladi —
            // admin o'zi qo'ygan nomga tegilmaydi. Rus va ingliz nomlari o'zgarmaydi.
            $rename = [
                'portal_svodka'   => ['Svodka', 'Jamlanma hisobot'],
                'svodka_releases' => ['Svodkalar', 'Jamlanma hisobotlar'],
                'svodka_view'     => ["Bo'limlar svodkasi", "Bo'limlar jamlanma hisoboti"],
            ];
            foreach ($rename as $key => [$old, $new]) {
                MenuItem::where('key', $key)->where('title_uz', $old)->update(['title_uz' => $new]);
            }
            foreach (['svodka.release', 'svodka.view'] as $perm) {
                $row = Permission::where('name', $perm)->first();
                if ($row && str_starts_with((string) $row->label_uz, 'Buyurtmachi svodkasi')) {
                    $row->update(['label_uz' => 'Buyurtmachi jamlanma hisoboti' . substr($row->label_uz, strlen('Buyurtmachi svodkasi'))]);
                }
            }
        });
    }
}
