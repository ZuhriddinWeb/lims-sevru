<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Administrator — barcha ruxsatlar
        $admin = Role::updateOrCreate(
            ['name' => 'admin'],
            [
                'display_name_ru' => 'Администратор',
                'display_name_uz' => 'Administrator',
                'display_name_en' => 'Administrator',
                'description'     => 'Tizimning to\'liq boshqaruvi',
                'is_system'       => true,
            ]
        );
        $admin->syncPermissions(Permission::pluck('id')->all());

        // 2. Laboratoriya boshlig'i — barcha lab modullari + ko'rish
        $labHead = Role::updateOrCreate(
            ['name' => 'lab_head'],
            [
                'display_name_ru' => 'Начальник лаборатории',
                'display_name_uz' => 'Laboratoriya boshlig\'i',
                'display_name_en' => 'Lab head',
                'description'     => 'Laboratoriya jarayonlarini boshqaradi',
                'is_system'       => false,
            ]
        );
        $labHead->syncPermissions(
            Permission::whereIn('module', [
                'equipment', 'test_objects', 'samples', 'water_orders',
                'monitoring_points', 'catalog', 'logs', 'documents', 'reports', 'executive',
            ])->pluck('id')->all()
        );

        // 3. Operator (texnologik lab) — proba ro'yxatga olish
        $operator = Role::updateOrCreate(
            ['name' => 'operator'],
            [
                'display_name_ru' => 'Оператор (регистрация)',
                'display_name_uz' => 'Operator (ro\'yxatga olish)',
                'display_name_en' => 'Operator',
                'description'     => 'Probalarni ro\'yxatga oladi va uzatadi',
                'is_system'       => false,
            ]
        );
        $operator->syncPermissions(
            Permission::whereIn('name', [
                'samples.view', 'samples.view_all', 'samples.create', 'samples.update',
                'test_objects.view', 'test_objects.create', 'test_objects.update',
                'equipment.view',
                'documents.view', 'documents.create',
                'reports.view', 'reports.export',
            ])->pluck('id')->all()
        );

        // 4. Analitik (natija kirituvchi) — faqat o'z guruhi natijalari
        $analyst = Role::updateOrCreate(
            ['name' => 'analyst'],
            [
                'display_name_ru' => 'Аналитик',
                'display_name_uz' => 'Analitik',
                'display_name_en' => 'Analyst',
                'description'     => 'Tahlil o\'tkazadi va natijalarni kiritadi',
                'is_system'       => false,
            ]
        );
        $analyst->syncPermissions(
            Permission::whereIn('name', [
                'samples.view', 'samples.result',
                'water_orders.view', 'water_orders.result',
                'equipment.view',
                'documents.view',
                'reports.view',
            ])->pluck('id')->all()
        );

        // 5. Kuzatuvchi — faqat ko'rish
        $viewer = Role::updateOrCreate(
            ['name' => 'viewer'],
            [
                'display_name_ru' => 'Наблюдатель',
                'display_name_uz' => 'Kuzatuvchi',
                'display_name_en' => 'Viewer',
                'description'     => 'Faqat ko\'rish huquqi',
                'is_system'       => false,
            ]
        );
        $viewer->syncPermissions(
            Permission::where('ability', 'view')->pluck('id')->all()
        );
    }
}
