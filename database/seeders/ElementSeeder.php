<?php

namespace Database\Seeders;

use App\Models\Element;
use Illuminate\Database\Seeder;

/**
 * Aniqlanadigan ko'rsatkichlar/elementlar — ЦЗЛ jadvalidan.
 * Suv, bo'tana, quruq namuna va reagentlar bo'yicha 48 ta ko'rsatkich.
 * Format: [belgi, nom_ru, nom_uz, nom_en, o'lchov_birligi]
 */
class ElementSeeder extends Seeder
{
    public function run(): void
    {
        $elements = [
            ['SO₄', 'Сульфат (SO₄)', 'Sulfat (SO₄)', 'Sulfate (SO₄)', 'mg/l'],
            ['Cl', 'Хлор (Cl)', 'Xlor (Cl)', 'Chlorine (Cl)', 'mg/l'],
            ['NH₄', 'Аммоний (NH₄)', 'Ammoniy (NH₄)', 'Ammonium (NH₄)', 'mg/l'],
            ['CO₃', 'Карбонат (CO₃)', 'Karbonat (CO₃)', 'Carbonate (CO₃)', 'mg/l'],
            ['HCO₃', 'Гидрокарбонат (HCO₃)', 'Gidrokarbonat (HCO₃)', 'Bicarbonate (HCO₃)', 'mg/l'],
            ['pH', 'Водородный показатель (pH)', 'Vodorod ko\'rsatkichi (pH)', 'pH value', 'pH'],
            ['CN⁻', 'Цианид (CN⁻)', 'Sianid (CN⁻)', 'Cyanide (CN⁻)', 'mg/l'],
            ['NaCN', 'Цианид натрия (NaCN)', 'Natriy sianid (NaCN)', 'Sodium cyanide (NaCN)', 'mg/l'],
            ['Ca', 'Кальций (Ca)', 'Kalsiy (Ca)', 'Calcium (Ca)', 'mg/l'],
            ['Mg', 'Магний (Mg)', 'Magniy (Mg)', 'Magnesium (Mg)', 'mg/l'],
            ['', 'Общая жёсткость', 'Umumiy qattiqlik', 'Total hardness', 'mg-ekv/l'],
            ['', 'Щёлочность', 'Ishqoriylik', 'Alkalinity', 'mg/l'],
            ['Fe', 'Железо общее (в воде)', 'Umumiy temir (suvda)', 'Total iron (water)', 'mg/l'],
            ['', 'Сухой остаток солей', 'Quruq tuz qoldig\'i', 'Dry salt residue', 'mg/l'],
            ['Au', 'Растворённое золото (Au)', 'Erigan oltin (Au)', 'Dissolved gold (Au)', 'mg/l'],
            ['Ag', 'Растворённое серебро (Ag)', 'Erigan kumush (Ag)', 'Dissolved silver (Ag)', 'mg/l'],
            ['CNS', 'Тиоцианат (CNS)', 'Tiosianat (CNS)', 'Thiocyanate (CNS)', 'mg/l'],
            ['As³⁺', 'Мышьяк (As³⁺)', 'Mishyak (As³⁺)', 'Arsenic (As³⁺)', 'mg/l'],
            ['As⁵⁺', 'Мышьяк (As⁵⁺)', 'Mishyak (As⁵⁺)', 'Arsenic (As⁵⁺)', 'mg/l'],
            ['Fe²⁺', 'Железо (Fe²⁺)', 'Temir (Fe²⁺)', 'Iron (Fe²⁺)', 'mg/l'],
            ['Fe³⁺', 'Железо (Fe³⁺)', 'Temir (Fe³⁺)', 'Iron (Fe³⁺)', 'mg/l'],
            ['Cu', 'Медь (Cu)', 'Mis (Cu)', 'Copper (Cu)', 'mg/l'],
            ['', 'Лигносульфонат натрия', 'Natriy lignosulfonat', 'Sodium lignosulfonate', 'g/l'],
            ['Au', 'Экстракция золота', 'Oltin ekstraksiyasi', 'Gold extraction', 'mg/l'],
            ['Cl', 'Хлор (в кислой пробе)', 'Xlor (kislotali namunada)', 'Chlorine (acidic sample)', 'mg/l'],
            ['Sb', 'Сурьма (Sb)', 'Surma (Sb)', 'Antimony (Sb)', 'mg/l'],
            ['S', 'Сера сульфидная (S)', 'Sulfid oltingugurti (S)', 'Sulfide sulfur (S)', '%'],
            ['S', 'Сера общая (S)', 'Umumiy oltingugurt (S)', 'Total sulfur (S)', '%'],
            ['C', 'Углерод органический (C)', 'Organik uglerod (C)', 'Organic carbon (C)', '%'],
            ['C', 'Углерод общий (C)', 'Umumiy uglerod (C)', 'Total carbon (C)', '%'],
            ['Fe', 'Железо общее (Fe)', 'Umumiy temir (Fe)', 'Total iron (Fe)', '%'],
            ['Ag', 'Серебро (Ag)', 'Kumush (Ag)', 'Silver (Ag)', 'g/t'],
            ['As', 'Мышьяк общий (As)', 'Umumiy mishyak (As)', 'Total arsenic (As)', '%'],
            ['S', 'Сера общая (гравиметрия)', 'Umumiy oltingugurt (gravimetrik)', 'Total sulfur (gravimetric)', '%'],
            ['Ca', 'Ион Ca из угля', 'Ko\'mirdan Ca ioni', 'Ca ion from carbon', 'g/kg'],
            ['NaOH', 'Гидроксид натрия (NaOH)', 'Natriy gidroksid (NaOH)', 'Sodium hydroxide (NaOH)', '%'],
            ['', 'ВКК', 'BKK', 'BKK', '%'],
            ['', 'Уголь', 'Ko\'mir', 'Carbon', '%'],
            ['HCl', 'Соляная кислота (HCl)', 'Xlorid kislota (HCl)', 'Hydrochloric acid (HCl)', '%'],
            ['', 'Остаток ВКК', 'BKK qoldig\'i', 'BKK residue', '%'],
            ['CuSO₄', 'Остаток сульфата меди', 'Mis sulfat qoldig\'i', 'Copper sulfate residue', '%'],
            ['', 'Полиакриламид', 'Poliakrilamid', 'Polyacrylamide', '%'],
            ['CuSO₄', 'Сульфат меди (CuSO₄)', 'Mis sulfat (CuSO₄)', 'Copper sulfate (CuSO₄)', '%'],
            ['Na₂CO₃', 'Карбонат натрия (Na₂CO₃)', 'Natriy karbonat (Na₂CO₃)', 'Sodium carbonate (Na₂CO₃)', '%'],
            ['CaO', 'Оксид кальция (CaO)', 'Kalsiy oksid (CaO)', 'Calcium oxide (CaO)', '%'],
            ['K₂SO₄', 'Сульфат калия (K₂SO₄)', 'Kaliy sulfat (K₂SO₄)', 'Potassium sulfate (K₂SO₄)', '%'],
            ['(NH₄)₂SO₄', 'Сульфат аммония', 'Ammoniy sulfat', 'Ammonium sulfate', '%'],
            ['CaCO₃', 'Карбонат кальция (CaCO₃)', 'Kalsiy karbonat (CaCO₃)', 'Calcium carbonate (CaCO₃)', '%'],
        ];

        foreach ($elements as $i => $e) {
            Element::updateOrCreate(
                ['name_uz' => $e[2]],
                [
                    'symbol'       => $e[0] ?: null,
                    'name_ru'      => $e[1],
                    'name_en'      => $e[3],
                    'default_unit' => $e[4] ?: null,
                    'is_active'    => true,
                    'sort_order'   => $i + 1,
                ]
            );
        }
    }
}
