<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Buyurtma so'rovlari (qoralama buyurtma) — Paket 24. Qayta ishga tushirish xavfsiz:
 *   php artisan db:seed --class=SampleRequestModuleSeeder --force
 *
 *  - ruxsatlar (faqat yo'q bo'lsa):
 *        portal.request             — buyurtmachi: qoralama tuzish va yuborish («Buyurtmachi» roliga)
 *        sample_requests.view       — laboratoriya: kelgan buyurtmalarni ko'rish
 *        sample_requests.receive    — namunani qabul qilish (proba yaratiladi) va rad etish
 *        sample_requests.create     — buyurtmachi nomidan so'rov yaratish
 *  - rollarga FAQAT ruxsat yangi yaratilganda beriladi (admin keyin o'zgartirsa — tegilmaydi)
 *  - menyu: «Buyurtmalarim» (portal) va «Buyurtmalar» (laboratoriya) — faqat yo'q bo'lsa
 */
class SampleRequestModuleSeeder extends Seeder
{
    private const PERMISSIONS = [
        'portal' => [
            'group'  => 'lab',
            'labels' => ['ru' => 'Портал заказчика', 'uz' => 'Buyurtmachi portali', 'en' => 'Customer portal'],
            'abilities' => [
                'request' => ['ru' => 'Черновик заявки и отправка (для заказчика)', 'uz' => 'Buyurtma qoralamasi va yuborish (buyurtmachi uchun)', 'en' => 'Draft & send requests (for customers)'],
            ],
        ],
        'sample_requests' => [
            'group'  => 'lab',
            'labels' => ['ru' => 'Заявки заказчиков', 'uz' => 'Buyurtmalar', 'en' => 'Customer requests'],
            'abilities' => [
                'view'    => ['ru' => 'Просмотр', 'uz' => "Ko'rish", 'en' => 'View'],
                'receive' => ['ru' => 'Приём проб и отклонение', 'uz' => 'Namunani qabul qilish va rad etish', 'en' => 'Receive samples & reject'],
                'create'  => ['ru' => 'Создание от имени заказчика', 'uz' => 'Buyurtmachi nomidan yaratish', 'en' => 'Create on behalf of customer'],
            ],
        ],
    ];

    private const GRANTS = [
        'lab_head' => ['sample_requests.view', 'sample_requests.receive', 'sample_requests.create'],
        'operator' => ['sample_requests.view', 'sample_requests.receive', 'sample_requests.create'],
        'analyst'  => ['sample_requests.view'],
        'viewer'   => ['sample_requests.view'],
        'customer' => ['portal.request'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $created = $this->permissions();
            $this->roles($created);
            $this->menu();
        });
    }

    private function permissions(): array
    {
        $sort = (int) Permission::max('sort_order') + 1;
        $created = [];
        foreach (self::PERMISSIONS as $module => $cfg) {
            foreach ($cfg['abilities'] as $ability => $al) {
                $p = Permission::firstOrCreate(['name' => "$module.$ability"], [
                    'module'     => $module,
                    'ability'    => $ability,
                    'group'      => $cfg['group'],
                    'label_ru'   => $cfg['labels']['ru'] . ' — ' . $al['ru'],
                    'label_uz'   => $cfg['labels']['uz'] . ' — ' . $al['uz'],
                    'label_en'   => $cfg['labels']['en'] . ' — ' . $al['en'],
                    'sort_order' => $sort++,
                ]);
                if ($p->wasRecentlyCreated) {
                    $created[] = "$module.$ability";
                }
            }
        }

        return $created;
    }

    private function roles(array $created): void
    {
        foreach (self::GRANTS as $roleName => $names) {
            $role = Role::where('name', $roleName)->first();
            $names = array_values(array_intersect($names, $created));
            if (! $role || ! $names) {
                continue;
            }
            $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', $names)->pluck('id')->all());
        }
    }

    private function menu(): void
    {
        MenuItem::firstOrCreate(['key' => 'portal_requests'], [
            'title_ru' => 'Мои заявки', 'title_uz' => 'Buyurtmalarim', 'title_en' => 'My requests',
            'icon' => 'clipboard-list', 'route' => 'portal-requests', 'permission' => 'portal.request',
            'parent_id' => null, 'sort_order' => 0, 'is_active' => true,
        ]);
        MenuItem::firstOrCreate(['key' => 'sample_requests'], [
            'title_ru' => 'Заявки', 'title_uz' => 'Buyurtmalar', 'title_en' => 'Requests',
            'icon' => 'clipboard-list', 'route' => 'sample-requests', 'permission' => 'sample_requests.view',
            'parent_id' => null, 'sort_order' => 1, 'is_active' => true,
        ]);
    }
}
