<?php

namespace Database\Seeders;

use App\Models\Element;
use Illuminate\Database\Seeder;

class ElementSeeder extends Seeder
{
    public function run(): void
    {
        $elements = [
            ['Au', 'Золото', 'Oltin', 'Gold', 'г/т'],
            ['Ag', 'Серебро', 'Kumush', 'Silver', 'г/т'],
            ['Cu', 'Медь', 'Mis', 'Copper', '%'],
            ['Pb', 'Свинец', 'Qo\'rg\'oshin', 'Lead', '%'],
            ['Zn', 'Цинк', 'Rux', 'Zinc', '%'],
            ['Fe', 'Железо', 'Temir', 'Iron', '%'],
            ['As', 'Мышьяк', 'Margimush', 'Arsenic', '%'],
            ['S', 'Сера', 'Oltingugurt', 'Sulfur', '%'],
            ['SiO2', 'Диоксид кремния', 'Kremniy dioksidi', 'Silicon dioxide', '%'],
            ['CN', 'Цианид', 'Sianid', 'Cyanide', 'мг/л'],
            ['pH', 'Водородный показатель', 'Vodorod ko\'rsatkichi', 'pH value', 'pH'],
            ['NO3', 'Нитраты', 'Nitratlar', 'Nitrates', 'мг/л'],
            ['Hg', 'Ртуть', 'Simob', 'Mercury', 'мг/л'],
            ['Cd', 'Кадмий', 'Kadmiy', 'Cadmium', 'мг/л'],
        ];

        foreach ($elements as $i => $e) {
            Element::updateOrCreate(
                ['symbol' => $e[0]],
                [
                    'name_ru' => $e[1], 'name_uz' => $e[2], 'name_en' => $e[3],
                    'default_unit' => $e[4], 'is_active' => true, 'sort_order' => $i + 1,
                ]
            );
        }
    }
}
