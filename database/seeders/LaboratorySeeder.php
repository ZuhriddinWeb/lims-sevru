<?php

namespace Database\Seeders;

use App\Models\Laboratory;
use App\Models\LaboratoryGroup;
use Illuminate\Database\Seeder;

class LaboratorySeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'code' => 'TL', 'sort' => 1,
                'ru' => 'Технологическая лаборатория по золоту',
                'uz' => 'Oltin bo\'yicha texnologik laboratoriya',
                'en' => 'Gold technology laboratory',
                'groups' => [
                    ['GRAV', 'Группа по гравитации', 'Gravitatsiya guruhi', 'Gravity group', 'customer'],
                    ['CYAN', 'Группа по цианированию', 'Sianlash guruhi', 'Cyanidation group', 'customer'],
                ],
            ],
            [
                'code' => 'GEO', 'sort' => 2,
                'ru' => 'Геологическая лаборатория',
                'uz' => 'Geologik laboratoriya',
                'en' => 'Geological laboratory',
                'groups' => [
                    ['GEO_MAIN', 'Геологическая группа', 'Geologik guruh', 'Geological group', 'customer'],
                ],
            ],
            [
                'code' => 'AN', 'sort' => 3,
                'ru' => 'Аналитическая лаборатория',
                'uz' => 'Analitik laboratoriya',
                'en' => 'Analytical laboratory',
                'groups' => [
                    ['SPECTR', 'Спектральная группа', 'Spektral guruh', 'Spectral group', 'executor'],
                    ['CHEM', 'Химическая группа', 'Kimyoviy guruh', 'Chemical group', 'executor'],
                ],
            ],
            [
                'code' => 'WATER', 'sort' => 4,
                'ru' => 'Лаборатория по решению водных проблем',
                'uz' => 'Suv muammolarini hal qilish laboratoriyasi',
                'en' => 'Water problems laboratory',
                'groups' => [
                    ['WATER_RES', 'Группа водных ресурсов', 'Suv resurslari guruhi', 'Water resources group', 'executor'],
                    ['AIR', 'Группа по воздуху', 'Havo guruhi', 'Air group', 'executor'],
                    ['RAD', 'Группа радиационного контроля', 'Radiatsiya nazorati guruhi', 'Radiation control group', 'executor'],
                ],
            ],
        ];

        foreach ($data as $lab) {
            $laboratory = Laboratory::updateOrCreate(
                ['code' => $lab['code']],
                [
                    'name_ru' => $lab['ru'], 'name_uz' => $lab['uz'], 'name_en' => $lab['en'],
                    'is_active' => true, 'sort_order' => $lab['sort'],
                ]
            );

            foreach ($lab['groups'] as $i => $g) {
                LaboratoryGroup::updateOrCreate(
                    ['code' => $g[0]],
                    [
                        'laboratory_id' => $laboratory->id,
                        'name_ru' => $g[1], 'name_uz' => $g[2], 'name_en' => $g[3],
                        'kind' => $g[4], 'is_active' => true, 'sort_order' => $i + 1,
                    ]
                );
            }
        }
    }
}
