<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['dashboard',        'Панель',             'Bosh sahifa',          'Dashboard',        'layout-dashboard', 'dashboard',        null,                      1],
            ['samples',          'Пробы',              'Probalar',             'Samples',          'test-tube',        'samples',          'samples.view',            2],
            ['test_objects',     'Объекты испытания',  'Sinov obyektlari',     'Test objects',     'package',          'test-objects',     'test_objects.view',       3],
            ['equipment',        'Оборудование',       'Uskunalar',            'Equipment',        'microscope',       'equipment',        'equipment.view',          4],
            ['water_orders',     'Водная лаборатория', 'Suv laboratoriyasi',   'Water lab',        'droplets',         'water-orders',     'water_orders.view',       5],
            ['monitoring_points','Точки мониторинга',  'Monitoring nuqtalari', 'Monitoring points','map-pin',          'monitoring-points','monitoring_points.view',  6],
            ['ai_analysis',      'ИИ-анализ',          'AI tahlil',            'AI analysis',      'sparkles',         'ai-analysis',      'water_orders.view',       7],
        ];

        foreach ($items as $i) {
            MenuItem::updateOrCreate(['key' => $i[0]], [
                'title_ru' => $i[1], 'title_uz' => $i[2], 'title_en' => $i[3],
                'icon' => $i[4], 'route' => $i[5], 'permission' => $i[6],
                'parent_id' => null, 'sort_order' => $i[7], 'is_active' => true,
            ]);
        }

        // Spravochniklar (bolalari bilan)
        $catalog = MenuItem::updateOrCreate(['key' => 'catalog'], [
            'title_ru' => 'Справочники', 'title_uz' => 'Kataloglar', 'title_en' => 'Catalog',
            'icon' => 'book-marked', 'route' => null, 'permission' => 'catalog.view',
            'parent_id' => null, 'sort_order' => 20, 'is_active' => true,
        ]);

        $catalogChildren = [
            ['laboratories',     'Лаборатории', 'Laboratoriyalar', 'Laboratories', 'building-2', 'laboratories',     'catalog.view', 1],
            ['laboratory_groups','Группы',      'Guruhlar',        'Groups',       'users-round', 'laboratory-groups','catalog.view', 2],
            ['elements',         'Элементы',    'Elementlar',      'Elements',     'atom',       'elements',         'catalog.view', 3],
            ['test_profiles',    'Профили испытаний', 'Sinov profillari', 'Test profiles', 'clipboard-list', 'test-profiles', 'catalog.view', 4],
        ];
        foreach ($catalogChildren as $c) {
            MenuItem::updateOrCreate(['key' => $c[0]], [
                'title_ru' => $c[1], 'title_uz' => $c[2], 'title_en' => $c[3],
                'icon' => $c[4], 'route' => $c[5], 'permission' => $c[6],
                'parent_id' => $catalog->id, 'sort_order' => $c[7], 'is_active' => true,
            ]);
        }

        // Administratsiya
        $admin = MenuItem::updateOrCreate(['key' => 'administration'], [
            'title_ru' => 'Администрирование', 'title_uz' => 'Administratsiya', 'title_en' => 'Administration',
            'icon' => 'shield', 'route' => null, 'permission' => null,
            'parent_id' => null, 'sort_order' => 30, 'is_active' => true,
        ]);

        $adminChildren = [
            ['users',      'Пользователи',     'Foydalanuvchilar', 'Users',          'users',       'users',      'users.view', 1],
            ['roles',      'Роли и права',     'Rollar va ruxsat', 'Roles & rights', 'key-round',   'roles',      'roles.view', 2],
            ['menu_admin', 'Меню',             'Menyu',            'Menu',           'list-tree',   'menu-items', 'menu.manage', 3],
            ['logs',       'Журнал действий',  'Audit jurnali',    'Activity log',   'scroll-text', 'activity-logs','logs.view', 4],
        ];
        foreach ($adminChildren as $c) {
            MenuItem::updateOrCreate(['key' => $c[0]], [
                'title_ru' => $c[1], 'title_uz' => $c[2], 'title_en' => $c[3],
                'icon' => $c[4], 'route' => $c[5], 'permission' => $c[6],
                'parent_id' => $admin->id, 'sort_order' => $c[7], 'is_active' => true,
            ]);
        }
    }
}
