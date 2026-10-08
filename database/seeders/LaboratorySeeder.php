<?php

namespace Database\Seeders;

use App\Models\Laboratory;
use App\Models\LaboratoryGroup;
use Illuminate\Database\Seeder;

/**
 * Zavod markaziy laboratoriyasi (ЦЗЛ) va uning guruhlari.
 * Ma'lumotlar manbai: ЦЗЛ ish jarayoni jadvali.
 */
class LaboratorySeeder extends Seeder
{
    public function run(): void
    {
        // Yagona laboratoriya — Zavod markaziy laboratoriyasi
        $lab = Laboratory::updateOrCreate(
            ['code' => 'ZML'],
            [
                'name_ru'    => 'Центральная заводская лаборатория',
                'name_uz'    => 'Zavod markaziy laboratoriyasi',
                'name_en'    => 'Central plant laboratory',
                'description'=> 'ЦЗЛ — рахбар: Мирзоева Одинахон Рахматовна',
                'is_active'  => true,
                'sort_order' => 1,
            ]
        );

        // 4 guruh
        $groups = [
            ['NTG',  'Группа анализа проб',               'Namuna tahlil guruhi',         'Sample analysis group',        'executor', 1],
            ['FNG',  'Группа фазового контроля',          'Fazali nazorat guruhi',        'Phase control group',          'executor', 2],
            ['MBG',  'Группа микробиологии',              'Mikrobiologiya guruhi',        'Microbiology group',           'executor', 3],
            ['TTG',  'Группа технологических исследований','Texnologik tadqiqotlar guruhi','Technological research group', 'both',     4],
        ];

        foreach ($groups as $g) {
            LaboratoryGroup::updateOrCreate(
                ['code' => $g[0]],
                [
                    'laboratory_id' => $lab->id,
                    'name_ru'       => $g[1],
                    'name_uz'       => $g[2],
                    'name_en'       => $g[3],
                    'kind'          => $g[4],
                    'is_active'     => true,
                    'sort_order'    => $g[5],
                ]
            );
        }
    }
}
