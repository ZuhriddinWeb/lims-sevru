<?php

namespace Database\Seeders;

use App\Models\Element;
use App\Models\Laboratory;
use App\Models\TestProfile;
use Illuminate\Database\Seeder;

/**
 * Sinov profillari — namuna turiga qarab tez-tez birga aniqlanadigan
 * ko'rsatkichlar to'plami. ЦЗЛ jadvalidagi ustunlar asosida.
 */
class TestProfileSeeder extends Seeder
{
    public function run(): void
    {
        $lab = Laboratory::where('code', 'ZML')->first();

        // Elementlarni name_uz bo'yicha topish
        $id = fn (string $nameUz) => Element::where('name_uz', $nameUz)->value('id');

        $byNames = function (array $names) use ($id) {
            return collect($names)->map($id)->filter()->values()->all();
        };

        $profiles = [
            [
                'code' => 'WATER_STD', 'object_type' => 'water', 'is_default' => true,
                'ru' => 'Вода — стандартный анализ', 'uz' => 'Suv — standart tahlil', 'en' => 'Water — standard',
                'elements' => ['Sulfat (SO₄)', 'Xlor (Cl)', 'Ammoniy (NH₄)', "Vodorod ko'rsatkichi (pH)",
                               'Sianid (CN⁻)', 'Natriy sianid (NaCN)', 'Kalsiy (Ca)', 'Magniy (Mg)'],
            ],
            [
                'code' => 'PULP_LIQ', 'object_type' => 'raw_material', 'is_default' => true,
                'ru' => 'Пульпа — жидкая фаза', 'uz' => "Bo'tana — suyuq faza", 'en' => 'Pulp — liquid phase',
                'elements' => ['Natriy sianid (NaCN)', "Vodorod ko'rsatkichi (pH)", 'Erigan oltin (Au)',
                               'Erigan kumush (Ag)', 'Mishyak (As³⁺)', 'Mishyak (As⁵⁺)', 'Temir (Fe²⁺)', 'Temir (Fe³⁺)'],
            ],
            [
                'code' => 'PULP_DRY', 'object_type' => 'product', 'is_default' => false,
                'ru' => 'Пульпа — сухая фаза', 'uz' => "Bo'tana — quruq faza", 'en' => 'Pulp — dry phase',
                'elements' => ['Sulfid oltingugurti (S)', 'Umumiy oltingugurt (S)', 'Organik uglerod (C)', 'Umumiy uglerod (C)'],
            ],
            [
                'code' => 'DRY_POWDER', 'object_type' => 'product', 'is_default' => true,
                'ru' => 'Сухой порошок', 'uz' => 'Quruq poroshok', 'en' => 'Dry powder',
                'elements' => ['Umumiy temir (Fe)', 'Kumush (Ag)', 'Umumiy mishyak (As)', 'Umumiy oltingugurt (gravimetrik)'],
            ],
        ];

        foreach ($profiles as $i => $p) {
            $profile = TestProfile::updateOrCreate(
                ['code' => $p['code']],
                [
                    'name_ru' => $p['ru'], 'name_uz' => $p['uz'], 'name_en' => $p['en'],
                    'object_type' => $p['object_type'], 'laboratory_id' => $lab?->id,
                    'is_default' => $p['is_default'], 'is_active' => true, 'sort_order' => $i + 1,
                ]
            );
            $profile->elements()->sync($byNames($p['elements']));
        }
    }
}
