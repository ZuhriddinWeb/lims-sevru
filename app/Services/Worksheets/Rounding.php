<?php

namespace App\Services\Worksheets;

/**
 * Laboratoriya yaxlitlash qoidalari.
 *
 *  round — 0,085 → 0,09 (yarmi yuqoriga, noldan uzoqqa)
 *  trunc — 0,168 → 0,16 (ortiqcha xonalar tashlanadi)
 *  none  — yaxlitlanmaydi
 *
 * Suzuvchi nuqta "shovqini" (0,085 = 0,08499999…) avval tozalanadi, shuning uchun
 * natija qog'ozdagi qo'lda hisob bilan bir xil chiqadi. JS'dagi nusxasi
 * (resources/js/modules/worksheets/engine.js) aynan shu algoritmni bajaradi.
 */
final class Rounding
{
    public const MODES = ['round', 'trunc', 'none'];

    public static function apply(?float $value, ?int $decimals, string $mode = 'round'): ?float
    {
        if ($value === null || ! is_finite($value)) {
            return null;
        }
        if ($decimals === null || $mode === 'none') {
            return $value;
        }

        $decimals = max(0, min(10, $decimals));
        $factor = 10 ** $decimals;

        $clean  = round($value, 10);
        $scaled = round($clean * $factor, 6);
        $abs    = abs($scaled);

        $int = $mode === 'trunc' ? floor($abs) : floor($abs + 0.5);
        if ($scaled < 0) {
            $int = -$int;
        }
        if ($int == 0.0) {
            $int = 0.0; // -0 bo'lmasin
        }

        return round($int / $factor, $decimals);
    }
}
