<?php

namespace App\Services\Worksheets;

/**
 * «Varaq bo'yicha jami» (korotochkalar yig'indisi) — KO'RSATISH uchun. [Paket 28]
 *
 * Jami ustuni Paket 23 dan beri shablonlarda bor, lekin undan oldin yaratilgan varaqlarda
 * (va tasdiqlanganlarida — ular hech qachon o'zgartirilmaydi) nusxada yo'q. Bunday varaqda
 * «kortochka» qator ustuni bo'lsa, jami ekranda va chop etishda baribir ko'rsatilsin:
 * ta'rif nusxasiga «virtual» jami ustuni qo'shiladi (bazadagi nusxa O'ZGARMAYDI, natijalar ham).
 */
final class DisplayTotals
{
    public const KEY = 'jami_kortochka';
    public const SOURCE = 'kortochka';

    public static function column(): array
    {
        return [
            'scope'         => 'total',
            'key'           => self::KEY,
            'type'          => 'computed',
            'stage'         => null,
            'label_uz'      => "Korotochkalar yig'indisi, mg",
            'label_ru'      => 'Сумма корточек, мг',
            'label_uz_cyrl' => 'Корточкалар йиғиндиси, мг',
            'unit'          => 'mg',
            'decimals'      => 3,
            'rounding'      => 'round',
            'formula'       => 'sum(' . self::SOURCE . ')',
            'default_value' => null,
            'required'      => false,
            'element_id'    => null,
            'tolerance_pct' => null,
            'tolerance_source' => null,
            'tolerance_mode'   => 'mean',
            'width'         => null,
            'virtual'       => true,   // nusxada yo'q — faqat ko'rsatish uchun qo'shilgan
        ];
    }

    /** Ta'rifda jami yo'q, lekin «kortochka» ustuni bor bo'lsa — virtual jami qo'shilgan nusxa */
    public static function augment(array $def): array
    {
        $cols = $def['columns'] ?? [];
        $hasSource = false;
        foreach ($cols as $c) {
            $scope = $c['scope'] ?? 'row';
            if ($scope === 'total') {
                return $def;
            }
            if ($scope === 'row' && ($c['key'] ?? '') === self::SOURCE) {
                $hasSource = true;
            }
            if (($c['key'] ?? '') === self::KEY) {
                return $def; // kalit band — tegmaymiz
            }
        }
        if (! $hasSource) {
            return $def;
        }
        $def['columns'] = array_values($cols);
        $def['columns'][] = self::column();

        return $def;
    }

    /** Ta'rifdagi jami ustunlari (virtual bilan) */
    public static function columns(array $def): array
    {
        return array_values(array_filter(self::augment($def)['columns'] ?? [], fn ($c) => ($c['scope'] ?? 'row') === 'total'));
    }
}
