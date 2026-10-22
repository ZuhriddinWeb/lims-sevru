<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Buyurtmachi bo'limlar + buyurtmachi portali + proba miqdori hisobi (qayta ishga tushirish xavfsiz):
 *   php artisan db:seed --class=SampleIntakeModuleSeeder --force
 *
 *  - ruxsatlar (faqat yo'q bo'lsa yaratiladi):
 *        customer_departments.view / .manage — bo'limlar katalogi
 *        sample_quantity.manage               — proba miqdori: qabul miqdorini tuzatish, sarf/qaytarish yozish
 *        portal.view                          — buyurtmachi portali (FAQAT «Buyurtmachi» roliga)
 *  - rollarga beriladi FAQAT ruxsat yangi yaratilganda (keyin admin o'zgartirgan bo'lsa — tegilmaydi)
 *  - «Buyurtmachi» (customer) roli — yo'q bo'lsa yaratiladi
 *        portal.settings                      — [P27] buyurtmachi nimani ko'rishini sozlash (hech bir rolga berilmaydi)
 *  - menyu: «Mening probalarim» (portal), «Proba qoldiqlari», Kataloglar → «Buyurtmachi bo'limlar»,
 *    [P27] Boshqaruv → «Portal sozlamalari»
 *    (faqat yo'q bo'lsa — admin o'zgartirgan nom/tartib saqlanadi)
 */
class SampleIntakeModuleSeeder extends Seeder
{
    private const PERMISSIONS = [
        'customer_departments' => [
            'group'  => 'lab',
            'labels' => ['ru' => 'Подразделения-заказчики', 'uz' => "Buyurtmachi bo'limlar", 'en' => 'Customer departments'],
            'abilities' => [
                'view'   => ['ru' => 'Просмотр', 'uz' => "Ko'rish", 'en' => 'View'],
                'manage' => ['ru' => 'Управление и пользователи портала', 'uz' => 'Boshqarish va portal foydalanuvchilari', 'en' => 'Manage & portal users'],
            ],
        ],
        'sample_quantity' => [
            'group'  => 'lab',
            'labels' => ['ru' => 'Количество пробы', 'uz' => 'Proba miqdori', 'en' => 'Sample quantity'],
            'abilities' => [
                'manage' => ['ru' => 'Учёт (приём, расход, возврат)', 'uz' => 'Hisob (qabul, sarf, qaytarish)', 'en' => 'Accounting (receipt, usage, return)'],
            ],
        ],
        'portal' => [
            'group'  => 'lab',
            'labels' => ['ru' => 'Портал заказчика', 'uz' => 'Buyurtmachi portali', 'en' => 'Customer portal'],
            'abilities' => [
                'view' => ['ru' => 'Только свои пробы (для заказчика)', 'uz' => "Faqat o'z probalari (buyurtmachi uchun)", 'en' => 'Own samples only (for customers)'],
                // [P27] laboratoriya tomoni: buyurtmachi nimani ko'rishini belgilash. Hech bir rolga berilmaydi — admin o'zi beradi
                'settings' => ['ru' => 'Настройки: что видит заказчик (для лаборатории)', 'uz' => "Sozlamalar: buyurtmachi nimani ko'radi (laboratoriya uchun)", 'en' => 'Settings: what customers see (for lab staff)'],
            ],
        ],
    ];

    private const GRANTS = [
        'lab_head' => ['customer_departments.view', 'customer_departments.manage', 'sample_quantity.manage'],
        'operator' => ['customer_departments.view', 'sample_quantity.manage'],
        'viewer'   => ['customer_departments.view'],
        'customer' => ['portal.view'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $created = $this->permissions();
            $this->customerRole();
            $this->roles($created);
            $this->menu();
        });
    }

    /** @return string[] yangi yaratilgan ruxsat nomlari */
    private function permissions(): array
    {
        $sort = (int) Permission::max('sort_order') + 1;
        $created = [];

        foreach (self::PERMISSIONS as $module => $cfg) {
            foreach ($cfg['abilities'] as $ability => $al) {
                $permission = Permission::firstOrCreate(['name' => "$module.$ability"], [
                    'module'     => $module,
                    'ability'    => $ability,
                    'group'      => $cfg['group'],
                    'label_ru'   => $cfg['labels']['ru'] . ' — ' . $al['ru'],
                    'label_uz'   => $cfg['labels']['uz'] . ' — ' . $al['uz'],
                    'label_en'   => $cfg['labels']['en'] . ' — ' . $al['en'],
                    'sort_order' => $sort++,
                ]);
                if ($permission->wasRecentlyCreated) {
                    $created[] = "$module.$ability";
                }
            }
        }

        return $created;
    }

    private function customerRole(): void
    {
        $role = Role::firstOrCreate(['name' => 'customer'], [
            'display_name_ru' => 'Заказчик (подразделение)',
            'display_name_uz' => "Buyurtmachi (bo'lim)",
            'display_name_en' => 'Customer (department)',
            'description'     => "Zavod bo'limi vakili: faqat o'z bo'limi probalarini ko'radi",
            'is_system'       => false,
        ]);

        // Yangi yaratilgan rolga portal ruxsati darhol beriladi
        if ($role->wasRecentlyCreated) {
            $role->permissions()->syncWithoutDetaching(Permission::where('name', 'portal.view')->pluck('id')->all());
        }
    }

    private function roles(array $created): void
    {
        if (! $created) {
            return; // ruxsatlar avvaldan bor — rollardagi sozlamaga tegmaymiz
        }
        foreach (self::GRANTS as $roleName => $names) {
            $role = Role::where('name', $roleName)->first();
            $names = array_values(array_intersect($names, $created));
            if (! $role || ! $names) {
                continue;
            }
            $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', $names)->pluck('id')->all());
        }
        // admin — barcha ruxsatlarga avtomatik ega (portalda «ko'rib chiqish» rejimida ochiladi)
    }

    private function menu(): void
    {
        MenuItem::firstOrCreate(['key' => 'portal'], [
            'title_ru' => 'Мои пробы', 'title_uz' => 'Mening probalarim', 'title_en' => 'My samples',
            'icon' => 'inbox', 'route' => 'portal', 'permission' => 'portal.view',
            'parent_id' => null, 'sort_order' => 0, 'is_active' => true,
        ]);

        MenuItem::firstOrCreate(['key' => 'sample_balances'], [
            'title_ru' => 'Остатки проб', 'title_uz' => 'Proba qoldiqlari', 'title_en' => 'Sample balances',
            'icon' => 'scale', 'route' => 'sample-balances', 'permission' => 'samples.view',
            'parent_id' => null, 'sort_order' => 2, 'is_active' => true,
        ]);

        $catalog = MenuItem::where('key', 'catalog')->first();
        MenuItem::firstOrCreate(['key' => 'customer_departments'], [
            'title_ru' => 'Подразделения-заказчики', 'title_uz' => "Buyurtmachi bo'limlar", 'title_en' => 'Customer departments',
            'icon' => 'factory', 'route' => 'customer-departments', 'permission' => 'customer_departments.view',
            'parent_id' => $catalog?->id, 'sort_order' => 5, 'is_active' => true,
        ]);

        // [P27] Boshqaruv → «Portal sozlamalari» (faqat yo'q bo'lsa)
        $admin = MenuItem::where('key', 'administration')->first();
        MenuItem::firstOrCreate(['key' => 'portal_settings'], [
            'title_ru' => 'Настройки портала', 'title_uz' => 'Portal sozlamalari', 'title_en' => 'Portal settings',
            'icon' => 'eye-off', 'route' => 'portal-settings', 'permission' => 'portal.settings',
            'parent_id' => $admin?->id, 'sort_order' => 7, 'is_active' => true,
        ]);
    }
}
