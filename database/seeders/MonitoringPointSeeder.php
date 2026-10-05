<?php

namespace Database\Seeders;

use App\Models\LaboratoryGroup;
use App\Models\MonitoringPoint;
use Illuminate\Database\Seeder;

class MonitoringPointSeeder extends Seeder
{
    public function run(): void
    {
        $water = LaboratoryGroup::where('code', 'WATER_RES')->first();
        $air   = LaboratoryGroup::where('code', 'AIR')->first();
        $rad   = LaboratoryGroup::where('code', 'RAD')->first();

        $points = [
            ['WP-01', 'Скважина №1 (хвостохранилище)', 'water',     'Промплощадка, север',  $water?->id],
            ['WP-02', 'Сбросной канал',                'water',     'Карьер, восточный борт', $water?->id],
            ['WP-03', 'Питьевой водозабор',            'water',     'Посёлок',              $water?->id],
            ['AP-01', 'Пост наблюдения воздуха №1',    'air',       'ЗИФ, промзона',        $air?->id],
            ['AP-02', 'Пост наблюдения воздуха №2',    'air',       'Карьер',               $air?->id],
            ['RP-01', 'Точка радиационного контроля',  'radiation', 'Склад руды',           $rad?->id],
        ];

        foreach ($points as $p) {
            MonitoringPoint::updateOrCreate(['code' => $p[0]], [
                'name' => $p[1], 'type' => $p[2], 'location' => $p[3],
                'laboratory_group_id' => $p[4], 'is_active' => true,
            ]);
        }
    }
}
