<?php

namespace App\Services\Worksheets;

/**
 * Qog'oz ishchi varaqlaridan olingan boshlang'ich shablonlar (ЦЗЛ, probir tahlili).
 *
 *   627-2025  Ko'mir maydasi        (kirill)  — 458 k/m varaq asosida
 *   382-2026  Ko'mir                (lotin)   — 501k varaq asosida
 *   384-2026  Bo'tana               (lotin)   — 279n varaq asosida
 *   385-2026  Katod cho'kmasi       (lotin)   — 400 k/ch varaq asosida
 *   276-2021  Katod cho'kmasi, eski (kirill)  — 400 ч/сл varaq asosida
 *
 * Formulalar qog'ozdagi raqamlar bilan tekshirilgan (tests_engine/ ga qarang).
 * Seeder faqat yo'q shablonlarni qo'shadi — admin tahrirlagani ustidan yozmaydi.
 */
final class DefaultTemplates
{
    private static function stage(string $key, int $signers): array
    {
        $labels = [
            'namuna'       => ["Namuna oldi / Kvartovka", 'Отбор пробы / Квартовка', 'Намуна олди / Квартовка'],
            'shixtovka'    => ['Shixtovka qildi', 'Шихтовка', 'Шихтовка қилди'],
            'eritish'      => ['Qaynatib eritdi', 'Плавка', 'Қайнатиб эритди'],
            'kupelyatsiya' => ['Kupelyatsiya qildi', 'Купелирование', 'Купеляция қилди'],
            'olchash'      => ["O'lchadi", 'Взвешивание', 'Ўлчади'],
            'hisoblash'    => ['Natijani hisobladi', 'Расчёт результата', 'Натижани ҳисоблади'],
        ][$key];

        return [
            'key' => $key, 'label_uz' => $labels[0], 'label_ru' => $labels[1],
            'label_uz_cyrl' => $labels[2], 'signers' => $signers,
        ];
    }

    private static function col(string $key, string $type, ?string $stage, array $labels, array $opts = []): array
    {
        return array_merge([
            'scope'            => 'row',
            'key'              => $key,
            'type'             => $type,
            'stage'            => $stage,
            'label_uz'         => $labels[0],
            'label_ru'         => $labels[1] ?? null,
            'label_uz_cyrl'    => $labels[2] ?? null,
            'unit'             => null,
            'decimals'         => null,
            'rounding'         => 'round',
            'formula'          => null,
            'default_value'    => null,
            'required'         => false,
            'element_symbol'   => null,
            'tolerance_pct'    => null,
            'tolerance_source' => null,
            'tolerance_mode'   => 'mean',
            'width'            => null,
        ], $opts);
    }

    private static function sum(string $key, array $labels, string $formula, array $opts = []): array
    {
        return self::col($key, 'computed', null, $labels, array_merge(['scope' => 'summary', 'formula' => $formula], $opts));
    }

    public static function all(): array
    {
        return [
            self::komirMaydasi(),
            self::komir(),
            self::botana(),
            self::katod385(),
            self::katod276(),
        ];
    }

    /** 627-2025 — Ko'mir maydasi (kirill forma) */
    public static function komirMaydasi(): array
    {
        return [
            'code' => '627-2025', 'version' => '2025',
            'name_uz' => "Ko'mir maydasi", 'name_ru' => 'Угольная мелочь', 'name_uz_cyrl' => 'Кўмир майдаси',
            'number_suffix' => 'k/m', 'number_start' => 1, 'default_rows' => 2,
            'print_locale' => 'uz_cyrl', 'orientation' => 'landscape',
            'notes' => "(x/p) ustuni: xolostoy proba ayirilib, 2 xonagacha kesiladi (qog'ozdagi 0,168 → 0,16). "
                . "Yakuniy natija birligi va elementi tasdiqlangach kiritilsin.",
            'stages' => [
                self::stage('shixtovka', 1), self::stage('eritish', 1), self::stage('kupelyatsiya', 1),
                self::stage('olchash', 1), self::stage('hisoblash', 1),
            ],
            'params' => [
                ['key' => 'xolostoy', 'label_uz' => 'Xolostoy proba (x/p)', 'label_ru' => 'Холостая проба (х/п)',
                 'label_uz_cyrl' => 'Холостой проба (х/п)', 'unit' => 'mg', 'default' => '0', 'decimals' => 3],
            ],
            'columns' => [
                self::col('mass', 'input', 'shixtovka', ['Namuna miqdori n=1,0 g', 'Навеска n=1,0 г', 'Намуна миқдори n=1,0 г.'],
                    ['unit' => 'g', 'default_value' => '1.0', 'required' => true]),
                self::col('pb3o4', 'input', 'shixtovka', ['Pb₃O₄ m=40 g; Ag C=10 g/l 7,0 ml', 'Pb₃O₄ m=40 г; Ag C=10 г/л 7,0 мл', 'Pb₃O₄ m=40 г; Ag C=10 г/л 7,0 мл']),
                self::col('verkbley', 'input', 'eritish', ["Verkbley og'irligi", 'Вес веркблея', 'Веркблей оғирлиги'], ['unit' => 'g']),
                self::col('korolek', 'input', 'kupelyatsiya', ['Korolyok m Au+Ag, mg', 'Королёк m Au+Ag, мг', 'Королек m Au+Ag, мг'], ['unit' => 'mg']),
                self::col('kortochka', 'input', 'olchash', ['Kortochka Au, mg', 'Корточка Au, мг', 'Корточка Au, мг'], ['unit' => 'mg', 'required' => true]),
                self::col('kortochka_xp', 'computed', 'hisoblash', ['Kortochka (x/p) Au, mg', 'Корточка (х/п) Au, мг', 'Корточка (х/п) Au, мг'],
                    ['unit' => 'mg', 'formula' => 'kortochka - xolostoy', 'decimals' => 2, 'rounding' => 'trunc']),
                self::col('au_sum', 'input', 'hisoblash', ["Au yig'indisi, mg", 'Сумма Au, мг', 'Au йиғиндиси, мг'], ['unit' => 'mg']),
                self::col('au_result', 'input', 'hisoblash', ['Au natijasi, %', 'Результат Au, %', 'Au натижаси, %']),
                self::sum('ortacha', ["O'rtacha qiymati", 'Среднее значение', 'Ўртача қиймати'], 'avg(kortochka_xp)',
                    ['decimals' => 2, 'rounding' => 'trunc']),
                // Varaq bo'yicha jami — qog'ozda korotochka ustuni tagiga yoziladi (458 k/m: 2,262)
                self::col('jami_kortochka', 'computed', null,
                    ["Korotochkalar yig'indisi, mg", 'Сумма корточек, мг', 'Корточкалар йиғиндиси, мг'],
                    ['scope' => 'total', 'formula' => 'sum(kortochka)', 'decimals' => 3, 'unit' => 'mg']),
            ],
        ];
    }

    /** 382-2026 — Ko'mir */
    public static function komir(): array
    {
        return [
            'code' => '382-2026', 'version' => '2026',
            'name_uz' => "Ko'mir", 'name_ru' => 'Уголь', 'name_uz_cyrl' => 'Кўмир',
            'number_suffix' => 'k', 'number_start' => 1, 'default_rows' => 3,
            'print_locale' => 'uz', 'orientation' => 'landscape',
            'notes' => null,
            'stages' => [
                self::stage('eritish', 1), self::stage('kupelyatsiya', 1), self::stage('hisoblash', 1),
            ],
            'params' => [],
            'columns' => [
                self::col('mass', 'input', 'eritish', ["Namuna og'irligi, g", 'Навеска, г', 'Намуна оғирлиги, г'],
                    ['unit' => 'g', 'default_value' => '1.0', 'required' => true]),
                self::col('ag', 'input', 'eritish', ['Ag, mg', 'Ag, мг', 'Ag, мг'], ['unit' => 'mg']),
                self::col('korolek', 'input', 'kupelyatsiya', ['Korolyokning vazni, mg', 'Масса королька, мг', 'Королёкнинг вазни, мг'], ['unit' => 'mg']),
                self::col('kortochka', 'input', 'kupelyatsiya', ['Kortochkaning vazni, mg', 'Масса корточки, мг', 'Корточканинг вазни, мг'], ['unit' => 'mg', 'required' => true]),
                self::col('au_gkg', 'computed', 'hisoblash', ['Au miqdori, g/kg', 'Содержание Au, г/кг', 'Au миқдори, г/кг'],
                    ['unit' => 'g/kg', 'formula' => 'kortochka / mass', 'decimals' => 2]),
                self::col('izoh', 'text', null, ['Izoh', 'Примечание', 'Изоҳ']),
                self::sum('avg_au', ["O'rtacha Au, g/kg", 'Среднее Au, г/кг', 'Ўртача Au, г/кг'], 'avg(au_gkg)',
                    ['decimals' => 2, 'unit' => 'g/kg', 'element_symbol' => 'Au']),
            ],
        ];
    }

    /** 384-2026 — Bo'tana */
    public static function botana(): array
    {
        return [
            'code' => '384-2026', 'version' => '2026',
            'name_uz' => "Bo'tana", 'name_ru' => 'Пульпа', 'name_uz_cyrl' => 'Бўтана',
            'number_suffix' => 'n', 'number_start' => 1, 'default_rows' => 3,
            'print_locale' => 'uz', 'orientation' => 'landscape',
            'notes' => null,
            'stages' => [
                self::stage('shixtovka', 1), self::stage('eritish', 1),
                self::stage('kupelyatsiya', 1), self::stage('olchash', 1),
            ],
            'params' => [],
            'columns' => [
                self::col('mass', 'input', 'shixtovka', ['Olingan namuna, g', 'Навеска, г', 'Олинган намуна, г'],
                    ['unit' => 'g', 'default_value' => '50', 'required' => true]),
                self::col('ag', 'input', 'shixtovka', ['Ag miqdori, mg', 'Ag, мг', 'Ag миқдори, мг'], ['unit' => 'mg', 'default_value' => '5']),
                self::col('verkbley', 'input', 'eritish', ["Verkbley og'irligi", 'Вес веркблея', 'Веркблей оғирлиги'], ['unit' => 'g']),
                self::col('korolek', 'input', 'kupelyatsiya', ['Korolyok, mg', 'Королёк, мг', 'Королёк, мг'], ['unit' => 'mg']),
                self::col('kortochka', 'input', 'olchash', ['Kortochka, mg', 'Корточка, мг', 'Корточка, мг'], ['unit' => 'mg', 'required' => true]),
                self::col('au_gt', 'computed', 'olchash', ['Au, g/t', 'Au, г/т', 'Au, г/т'],
                    ['unit' => 'g/t', 'formula' => 'kortochka / mass * 1000', 'decimals' => 2]),
                self::col('izoh', 'text', null, ['Izoh', 'Примечание', 'Изоҳ']),
                self::sum('avg_au', ["O'rtacha Au, g/t", 'Среднее Au, г/т', 'Ўртача Au, г/т'], 'avg(au_gt)',
                    ['decimals' => 2, 'unit' => 'g/t', 'element_symbol' => 'Au']),
            ],
        ];
    }

    private static function katodColumns(string $pbLabel, string $pbLabelRu): array
    {
        return [
            self::col('mass', 'input', 'namuna', ['K/ch olingan miqdori n=0,1000 g', 'Навеска к/о n=0,1000 г', 'К/ч олинган миқдори n=0,1000 г'],
                ['unit' => 'g', 'default_value' => '0.1000', 'required' => true]),
            self::col('ag_added', 'input', 'eritish', [$pbLabel, $pbLabelRu, $pbLabelRu], ['unit' => 'mg']),
            self::col('korolek', 'input', 'kupelyatsiya', ['Korolyok m Au+Ag, mg', 'Королёк m Au+Ag, мг', 'Королек m Au+Ag, мг'], ['unit' => 'mg', 'required' => true]),
            self::col('kortochka', 'input', 'olchash', ['Kortochka Au, mg', 'Корточка Au, мг', 'Корточка Au, мг'], ['unit' => 'mg', 'required' => true]),
            self::col('kortochka_xp', 'input', 'olchash', ['Kortochka (x/p) Au, mg', 'Корточка (х/п) Au, мг', 'Корточка (х/п) Au, мг'], ['unit' => 'mg']),
            self::col('au_sum', 'input', 'hisoblash', ["Au yig'indisi, mg", 'Сумма Au, мг', 'Au йиғиндиси, мг'], ['unit' => 'mg']),
            self::col('au_pct', 'computed', 'hisoblash', ['Natija Au, %', 'Результат Au, %', 'Au натижаси, %'],
                ['unit' => '%', 'formula' => 'kortochka / (mass * 1000) * 100', 'decimals' => 3]),
            self::col('ag_pct', 'computed', 'hisoblash', ['Ag natijasi, %', 'Результат Ag, %', 'Ag натижаси, %'],
                ['unit' => '%', 'formula' => '(korolek - kortochka - ag_added) / (mass * 1000) * 100', 'decimals' => 2, 'rounding' => 'trunc']),
            self::sum('avg_au', ["O'rtacha Au, % (ruxsat ±3,5%)", 'Среднее Au, % (допуск ±3,5%)', 'Ўртача Au, % (рухсат ±3,5%)'], 'avg(au_pct)',
                ['decimals' => 2, 'unit' => '%', 'element_symbol' => 'Au',
                 'tolerance_pct' => '3.5', 'tolerance_source' => 'au_pct', 'tolerance_mode' => 'mean']),
            self::sum('avg_ag', ["O'rtacha Ag, %", 'Среднее Ag, %', 'Ўртача Ag, %'], 'avg(ag_pct)',
                ['decimals' => 2, 'rounding' => 'trunc', 'unit' => '%', 'element_symbol' => 'Ag']),
        ];
    }

    private static function katodStages(): array
    {
        return [
            self::stage('namuna', 2), self::stage('eritish', 2), self::stage('kupelyatsiya', 2),
            self::stage('olchash', 2), self::stage('hisoblash', 1),
        ];
    }

    private static function katodParams(): array
    {
        return [
            ['key' => 'namlik', 'label_uz' => 'Namlik (вл)', 'label_ru' => 'Влажность', 'label_uz_cyrl' => 'Намлик',
             'unit' => '%', 'default' => null, 'decimals' => 2],
        ];
    }

    /** 385-2026 — Katod cho'kmasi */
    public static function katod385(): array
    {
        return [
            'code' => '385-2026', 'version' => '2026',
            'name_uz' => "Katod cho'kmasi", 'name_ru' => 'Катодный осадок', 'name_uz_cyrl' => 'Катод чўкмаси',
            'number_suffix' => 'k/ch', 'number_start' => 1, 'default_rows' => 2,
            'print_locale' => 'uz', 'orientation' => 'landscape',
            'notes' => "Dastlabki (Au aniqlash) qatorlarini «hisobga olinmasin» deb belgilang — o'rtachaga kirmaydi.",
            'stages' => self::katodStages(),
            'params' => self::katodParams(),
            'columns' => self::katodColumns('Pb₃O₄ m=3–5 g; Ag, mg (200,0)', 'Pb₃O₄ m=3–5 г; Ag, мг (200,0)'),
        ];
    }

    /** 276-2021 — Katod cho'kmasi, eski forma (kirill) */
    public static function katod276(): array
    {
        return [
            'code' => '276-2021', 'version' => '2021',
            'name_uz' => "Katod cho'kmasi (eski forma)", 'name_ru' => 'Катодный осадок (старая форма)', 'name_uz_cyrl' => 'Катод чўкмаси',
            'number_suffix' => 'ч/сл', 'number_start' => 1, 'default_rows' => 2,
            'print_locale' => 'uz_cyrl', 'orientation' => 'landscape',
            'notes' => null,
            'stages' => self::katodStages(),
            'params' => self::katodParams(),
            'columns' => self::katodColumns('Pb₃O₄ m=7–15 g; Ag, mg 5–15', 'Pb₃O₄ m=7–15 г; Ag, мг 5–15'),
        ];
    }
}
