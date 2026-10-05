<?php

namespace Database\Seeders;

use App\Models\Laboratory;
use App\Models\LaboratoryGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::where('name', 'admin')->first();
        $labHead = Role::where('name', 'lab_head')->first();
        $operator = Role::where('name', 'operator')->first();
        $analyst = Role::where('name', 'analyst')->first();

        $tl = Laboratory::where('code', 'TL')->first();
        $an = Laboratory::where('code', 'AN')->first();
        $water = Laboratory::where('code', 'WATER')->first();

        $grav = LaboratoryGroup::where('code', 'GRAV')->first();
        $spectr = LaboratoryGroup::where('code', 'SPECTR')->first();
        $chem = LaboratoryGroup::where('code', 'CHEM')->first();
        $waterRes = LaboratoryGroup::where('code', 'WATER_RES')->first();

        $users = [
            ['Администратор системы', 'admin',    'admin123',    $admin?->id,    null,          null,             'Системный администратор'],
            ['Алижон Рахимов',        'alijon',   'lims12345',   $labHead?->id,  $tl?->id,      $grav?->id,       'Начальник ЦНИЛ'],
            ['Оператор ТЛ',           'operator', 'lims12345',   $operator?->id, $tl?->id,      $grav?->id,       'Лаборант-регистратор'],
            ['Аналитик (спектр)',     'spectr',   'lims12345',   $analyst?->id,  $an?->id,      $spectr?->id,     'Инженер-аналитик'],
            ['Аналитик (химия)',      'chem',     'lims12345',   $analyst?->id,  $an?->id,      $chem?->id,       'Инженер-химик'],
            ['Водная лаборатория',    'water',    'lims12345',   $analyst?->id,  $water?->id,   $waterRes?->id,   'Инженер-эколог'],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(['username' => $u[1]], [
                'name' => $u[0],
                'password' => $u[2],
                'role_id' => $u[3],
                'laboratory_id' => $u[4],
                'laboratory_group_id' => $u[5],
                'position' => $u[6],
                'locale' => 'ru',
                'theme' => 'light',
                'is_active' => true,
            ]);
        }
    }
}
