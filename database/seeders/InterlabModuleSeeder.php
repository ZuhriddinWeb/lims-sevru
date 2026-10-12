<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Laboratoriyalararo tekshiruv modulini o'rnatadi (qayta ishga tushirish xavfsiz):
 *   php artisan db:seed --class=InterlabModuleSeeder --force
 *
 *  - ruxsatlar: interlab.view / send / process / manage — faqat yo'q bo'lsa yaratiladi
 *  - rollarga beriladi FAQAT ruxsat yangi yaratilganda (admin keyin o'zgartirgan bo'lsa — tegilmaydi)
 *  - menyu: «Laboratoriyalararo tekshiruv» va Administratsiya → «Laboratoriyalar tarmog'i»
 *    (faqat yo'q bo'lsa — admin o'zgartirgan nom/tartib saqlanadi)
 */
class InterlabModuleSeeder extends Seeder
{
    private const ABILITIES = [
        'view'    => ['ru' => 'Просмотр', 'uz' => "Ko'rish", 'en' => 'View'],
        'send'    => ['ru' => 'Отправка на проверку', 'uz' => "Tekshiruvga jo'natish", 'en' => 'Send for check'],
        'process' => ['ru' => 'Выполнение входящих', 'uz' => 'Kiruvchini bajarish', 'en' => 'Process incoming'],
        'manage'  => ['ru' => 'Настройка сети лабораторий', 'uz' => "Laboratoriyalar tarmog'ini sozlash", 'en' => 'Manage lab network'],
    ];

    private const GRANTS = [
        'lab_head' => ['view', 'send', 'process', 'manage'],
        'analyst'  => ['view', 'process'],
        'operator' => ['view', 'send'],
        'viewer'   => ['view'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $created = $this->permissions();
            $this->roles($created);
            $this->menu();
        });
    }

    /** @return string[] yangi yaratilgan ruxsatlar (ability) */
    private function permissions(): array
    {
        $labels = ['ru' => 'Межлабораторная проверка', 'uz' => 'Laboratoriyalararo tekshiruv', 'en' => 'Inter-lab check'];
        $sort = (int) Permission::max('sort_order') + 1;
        $created = [];

        foreach (self::ABILITIES as $ability => $al) {
            $permission = Permission::firstOrCreate(['name' => "interlab.$ability"], [
                'module'     => 'interlab',
                'ability'    => $ability,
                'group'      => $ability === 'manage' ? 'admin' : 'lab',
                'label_ru'   => $labels['ru'] . ' — ' . $al['ru'],
                'label_uz'   => $labels['uz'] . ' — ' . $al['uz'],
                'label_en'   => $labels['en'] . ' — ' . $al['en'],
                'sort_order' => $sort++,
            ]);
            if ($permission->wasRecentlyCreated) {
                $created[] = $ability;
            }
        }

        return $created;
    }

    private function roles(array $created): void
    {
        if (! $created) {
            return; // ruxsatlar avvaldan bor — rollardagi sozlamaga tegmaymiz
        }
        foreach (self::GRANTS as $roleName => $abilities) {
            $role = Role::where('name', $roleName)->first();
            $names = array_map(fn ($a) => "interlab.$a", array_intersect($abilities, $created));
            if (! $role || ! $names) {
                continue;
            }
            $ids = Permission::whereIn('name', $names)->pluck('id')->all();
            $role->permissions()->syncWithoutDetaching($ids);
        }
        // admin — barcha ruxsatlarga avtomatik ega
    }

    private function menu(): void
    {
        MenuItem::firstOrCreate(['key' => 'interlab'], [
            'title_ru' => 'Межлабораторная проверка', 'title_uz' => 'Laboratoriyalararo tekshiruv', 'title_en' => 'Inter-lab check',
            'icon' => 'arrow-left-right', 'route' => 'interlab', 'permission' => 'interlab.view',
            'parent_id' => null, 'sort_order' => 3, 'is_active' => true,
        ]);

        $admin = MenuItem::where('key', 'administration')->first();
        MenuItem::firstOrCreate(['key' => 'interlab_network'], [
            'title_ru' => 'Сеть лабораторий', 'title_uz' => "Laboratoriyalar tarmog'i", 'title_en' => 'Lab network',
            'icon' => 'network', 'route' => 'interlab-network', 'permission' => 'interlab.manage',
            'parent_id' => $admin?->id, 'sort_order' => 6, 'is_active' => true,
        ]);
    }
}
