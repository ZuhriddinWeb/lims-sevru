<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * [Paket 44] Markaziy portalga uzatish — ruxsat va menyu. Qayta ishga tushirish xavfsiz (faqat yo'q narsani yaratadi):
 *   php artisan db:seed --class=CentralLinkSeeder --force   (lims:upgrade o'zi chaqiradi)
 *
 *  - central.manage — Administratsiya → «Markaziy portal» (faqat admin; rolga berilmaydi, admin hammasini ko'radi)
 */
class CentralLinkSeeder extends Seeder
{
    public function run(): void
    {
        Permission::firstOrCreate(['name' => 'central.manage'], [
            'module' => 'central', 'ability' => 'manage', 'group' => 'admin',
            'label_uz' => 'Markaziy portal — uzatish sozlamalari',
            'label_ru' => 'Центральный портал — настройки передачи',
            'label_en' => 'Central portal — sharing settings',
            'sort_order' => (int) Permission::max('sort_order') + 1,
        ]);

        MenuItem::firstOrCreate(['key' => 'central_link'], [
            'title_uz' => 'Markaziy portal', 'title_ru' => 'Центральный портал', 'title_en' => 'Central portal',
            'icon' => 'radio-tower', 'route' => 'central-link', 'permission' => 'central.manage',
            'parent_id' => MenuItem::where('key', 'administration')->value('id'), 'sort_order' => 6, 'is_active' => true,
        ]);
    }
}
