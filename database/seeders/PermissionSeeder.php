<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Modullar va ularning amallari (menular bo'yicha CRUD ruxsatlari)
        $modules = [
            'equipment' => [
                'group'  => 'lab',
                'labels' => ['ru' => 'Оборудование', 'uz' => 'Uskunalar', 'en' => 'Equipment'],
                'abilities' => ['view', 'create', 'update', 'delete'],
            ],
            'test_objects' => [
                'group'  => 'lab',
                'labels' => ['ru' => 'Объекты испытания', 'uz' => 'Sinov obyektlari', 'en' => 'Test objects'],
                'abilities' => ['view', 'create', 'update', 'delete'],
            ],
            'samples' => [
                'group'  => 'lab',
                'labels' => ['ru' => 'Пробы', 'uz' => 'Probalar', 'en' => 'Samples'],
                'abilities' => ['view', 'view_all', 'create', 'update', 'delete', 'result'],
            ],
            'water_orders' => [
                'group'  => 'lab',
                'labels' => ['ru' => 'Водная лаборатория', 'uz' => 'Suv laboratoriyasi', 'en' => 'Water lab'],
                'abilities' => ['view', 'view_all', 'create', 'update', 'delete', 'result'],
            ],
            'monitoring_points' => [
                'group'  => 'lab',
                'labels' => ['ru' => 'Точки мониторинга', 'uz' => 'Monitoring nuqtalari', 'en' => 'Monitoring points'],
                'abilities' => ['view', 'create', 'update', 'delete'],
            ],
            'documents' => [
                'group'  => 'lab',
                'labels' => ['ru' => 'Документы', 'uz' => 'Hujjatlar', 'en' => 'Documents'],
                'abilities' => ['view', 'create', 'delete'],
            ],
            'reports' => [
                'group'  => 'lab',
                'labels' => ['ru' => 'Отчёты', 'uz' => 'Hisobotlar', 'en' => 'Reports'],
                'abilities' => ['view', 'export'],
            ],
            'catalog' => [
                'group'  => 'admin',
                'labels' => ['ru' => 'Справочники', 'uz' => 'Kataloglar', 'en' => 'Catalog'],
                'abilities' => ['view', 'manage'],
            ],
            'users' => [
                'group'  => 'admin',
                'labels' => ['ru' => 'Пользователи', 'uz' => 'Foydalanuvchilar', 'en' => 'Users'],
                'abilities' => ['view', 'create', 'update', 'delete'],
            ],
            'roles' => [
                'group'  => 'admin',
                'labels' => ['ru' => 'Роли и права', 'uz' => 'Rollar va ruxsatlar', 'en' => 'Roles & permissions'],
                'abilities' => ['view', 'create', 'update', 'delete'],
            ],
            'menu' => [
                'group'  => 'admin',
                'labels' => ['ru' => 'Меню', 'uz' => 'Menyu', 'en' => 'Menu'],
                'abilities' => ['manage'],
            ],
            'logs' => [
                'group'  => 'admin',
                'labels' => ['ru' => 'Журнал действий', 'uz' => 'Audit jurnali', 'en' => 'Activity logs'],
                'abilities' => ['view'],
            ],
        ];

        $abilityLabels = [
            'view'   => ['ru' => 'Просмотр', 'uz' => 'Ko\'rish', 'en' => 'View'],
            'create' => ['ru' => 'Создание', 'uz' => 'Qo\'shish', 'en' => 'Create'],
            'update' => ['ru' => 'Изменение', 'uz' => 'Tahrirlash', 'en' => 'Update'],
            'delete' => ['ru' => 'Удаление', 'uz' => 'O\'chirish', 'en' => 'Delete'],
            'view_all' => ['ru' => 'Просмотр всех групп', 'uz' => 'Barcha guruhlarni ko\'rish', 'en' => 'View all groups'],
            'export' => ['ru' => 'Выгрузка', 'uz' => 'Yuklab olish', 'en' => 'Export'],
            'result' => ['ru' => 'Ввод результатов', 'uz' => 'Natija kiritish', 'en' => 'Enter results'],
            'manage' => ['ru' => 'Управление', 'uz' => 'Boshqarish', 'en' => 'Manage'],
        ];

        $sort = 0;
        foreach ($modules as $module => $cfg) {
            foreach ($cfg['abilities'] as $ability) {
                Permission::updateOrCreate(
                    ['name' => "$module.$ability"],
                    [
                        'module'     => $module,
                        'ability'    => $ability,
                        'group'      => $cfg['group'],
                        'label_ru'   => $cfg['labels']['ru'] . ' — ' . $abilityLabels[$ability]['ru'],
                        'label_uz'   => $cfg['labels']['uz'] . ' — ' . $abilityLabels[$ability]['uz'],
                        'label_en'   => $cfg['labels']['en'] . ' — ' . $abilityLabels[$ability]['en'],
                        'sort_order' => $sort++,
                    ]
                );
            }
        }
    }
}
