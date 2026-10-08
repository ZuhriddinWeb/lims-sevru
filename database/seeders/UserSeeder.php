<?php

namespace Database\Seeders;

use App\Models\Laboratory;
use App\Models\LaboratoryGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * ЦЗЛ xodimlari — Excel jadvalidagi javobgar shaxslar.
 * Har xodim guruhiga biriktiriladi. Loginlar avtomatik yaratilgan,
 * birinchi kirishdan keyin parollarni almashtirish tavsiya etiladi.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin    = Role::where('name', 'admin')->first();
        $labHead  = Role::where('name', 'lab_head')->first();
        $analyst  = Role::where('name', 'analyst')->first();

        $lab = Laboratory::where('code', 'ZML')->first();

        $g = fn (string $code) => LaboratoryGroup::where('code', $code)->value('id');

        // [FIO, login, parol, rol_id, guruh_kodi, lavozim]
        $users = [
            // Administrator
            ['Администратор системы', 'admin', 'admin123', $admin?->id, null,
             'Системный администратор'],

            // Laboratoriya rahbari
            ['Мирзоева Одинахон Рахматовна', 'mirzoyeva', 'lims12345', $labHead?->id, null,
             'Начальник ЦЗЛ'],

            // Namuna tahlil guruhi (NTG)
            ['Шамсиев Шухрат Санакулович',      'shamsiyev',   'lims12345', $analyst?->id, 'NTG', 'Инженер-аналитик'],
            ['Холмуродова Дилшода Мухиддиновна','xolmurodova', 'lims12345', $analyst?->id, 'NTG', 'Инженер-аналитик'],
            ['Ишназарова Дилдора Нуриддин кизи','ishnazarova', 'lims12345', $analyst?->id, 'NTG', 'Лаборант'],

            // Mikrobiologiya guruhi (MBG)
            ['Уринов Азиз Абдумансур угли',     'urinov',      'lims12345', $analyst?->id, 'MBG', 'Инженер-микробиолог'],
            ['Хоматова Мехринигор Эркин кизи',  'xomatova',    'lims12345', $analyst?->id, 'MBG', 'Лаборант-микробиолог'],

            // Texnologik tadqiqotlar guruhi (TTG)
            ['Кобилов Уктам Ахмадович',         'qobilov',     'lims12345', $analyst?->id, 'TTG', 'Инженер-технолог'],
            ['Хайдаров Мариф Нурмурод угли',    'haydarov',    'lims12345', $analyst?->id, 'TTG', 'Инженер-технолог'],
            ['Сайдуллаев Музаффар Саиджалол угли','saydullayev','lims12345',$analyst?->id, 'TTG', 'Лаборант'],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['username' => $u[1]],
                [
                    'name'                => $u[0],
                    'password'            => $u[2],
                    'role_id'             => $u[3],
                    'laboratory_id'       => $u[4] === null ? ($u[1] === 'admin' ? null : $lab?->id) : $lab?->id,
                    'laboratory_group_id' => $u[4] ? $g($u[4]) : null,
                    'position'            => $u[5],
                    'locale'              => 'ru',
                    'theme'               => 'light',
                    'is_active'           => true,
                ]
            );
        }
    }
}
