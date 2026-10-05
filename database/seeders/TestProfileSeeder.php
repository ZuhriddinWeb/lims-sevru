<?php

namespace Database\Seeders;

use App\Models\Element;
use App\Models\Laboratory;
use App\Models\MonitoringPoint;
use App\Models\TestProfile;
use Illuminate\Database\Seeder;

class TestProfileSeeder extends Seeder
{
    public function run(): void
    {
        $el = fn (array $symbols) => Element::whereIn('symbol', $symbols)->pluck('id')->all();

        $tl    = Laboratory::where('code', 'TL')->first();
        $water = Laboratory::where('code', 'WATER')->first();

        $profiles = [
            [
                'code' => 'ORE_STD', 'object_type' => 'raw_material', 'is_default' => true,
                'ru' => 'Руда — стандартный анализ', 'uz' => 'Ruda — standart tahlil', 'en' => 'Ore — standard assay',
                'lab' => $tl?->id, 'elements' => ['Au', 'Ag', 'Cu', 'S', 'SiO2'],
            ],
            [
                'code' => 'ORE_FULL', 'object_type' => 'raw_material', 'is_default' => false,
                'ru' => 'Руда — расширенный анализ', 'uz' => 'Ruda — kengaytirilgan tahlil', 'en' => 'Ore — extended assay',
                'lab' => $tl?->id, 'elements' => ['Au', 'Ag', 'Cu', 'Pb', 'Zn', 'Fe', 'As', 'S', 'SiO2'],
            ],
            [
                'code' => 'PRODUCT_STD', 'object_type' => 'product', 'is_default' => true,
                'ru' => 'Продукция — контроль качества', 'uz' => 'Mahsulot — sifat nazorati', 'en' => 'Product — quality control',
                'lab' => $tl?->id, 'elements' => ['Au', 'Ag', 'Fe'],
            ],
            [
                'code' => 'WATER_DRINK', 'object_type' => 'water', 'is_default' => true,
                'ru' => 'Вода питьевая', 'uz' => 'Ichimlik suvi', 'en' => 'Drinking water',
                'lab' => $water?->id, 'elements' => ['pH', 'NO3', 'CN', 'Hg', 'Cd'],
            ],
            [
                'code' => 'WATER_TECH', 'object_type' => 'water', 'is_default' => false,
                'ru' => 'Вода техническая / сбросная', 'uz' => 'Texnik / oqova suv', 'en' => 'Technical / discharge water',
                'lab' => $water?->id, 'elements' => ['pH', 'CN', 'Cu', 'Fe', 'As'],
            ],
            [
                'code' => 'AIR_STD', 'object_type' => 'air', 'is_default' => true,
                'ru' => 'Воздух рабочей зоны', 'uz' => 'Ish zonasi havosi', 'en' => 'Workplace air',
                'lab' => $water?->id, 'elements' => ['SiO2', 'Hg', 'As'],
            ],
        ];

        foreach ($profiles as $i => $p) {
            $profile = TestProfile::updateOrCreate(['code' => $p['code']], [
                'name_ru'       => $p['ru'],
                'name_uz'       => $p['uz'],
                'name_en'       => $p['en'],
                'object_type'   => $p['object_type'],
                'laboratory_id' => $p['lab'],
                'is_default'    => $p['is_default'],
                'is_active'     => true,
                'sort_order'    => $i + 1,
            ]);
            $profile->elements()->sync($el($p['elements']));
        }

        // Monitoring nuqtalari uchun standart ko'rsatkichlar va me'yorlar
        $pointParams = [
            'WP-01' => [
                ['pH', 'pH', 6.5, 8.5], ['Цианиды', 'мг/л', null, 0.035],
                ['Медь', 'мг/л', null, 1.0], ['Мышьяк', 'мг/л', null, 0.05],
            ],
            'WP-02' => [
                ['pH', 'pH', 6.5, 8.5], ['Взвешенные вещества', 'мг/л', null, 30.0],
                ['Цианиды', 'мг/л', null, 0.05],
            ],
            'WP-03' => [
                ['pH', 'pH', 6.0, 9.0], ['Нитраты', 'мг/л', null, 45.0],
                ['Ртуть', 'мг/л', null, 0.0005], ['Кадмий', 'мг/л', null, 0.001],
            ],
            'AP-01' => [
                ['Пыль неорганическая', 'мг/м³', null, 0.5],
                ['Диоксид серы', 'мг/м³', null, 0.5],
            ],
            'AP-02' => [
                ['Пыль неорганическая', 'мг/м³', null, 0.5],
                ['Оксид углерода', 'мг/м³', null, 5.0],
            ],
            'RP-01' => [
                ['Мощность дозы', 'мкЗв/ч', null, 0.3],
            ],
        ];

        foreach ($pointParams as $code => $params) {
            $point = MonitoringPoint::where('code', $code)->first();
            if (! $point) {
                continue;
            }
            $point->parameters()->delete();
            foreach ($params as $i => $p) {
                $point->parameters()->create([
                    'parameter'  => $p[0],
                    'unit'       => $p[1],
                    'norm_min'   => $p[2],
                    'norm_max'   => $p[3],
                    'sort_order' => $i,
                ]);
            }
        }
    }
}
