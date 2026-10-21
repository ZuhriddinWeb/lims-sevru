<?php

namespace App\Support;

/**
 * Kimyoviy yozuv variantlari — qidiruv uchun. [Paket 25]
 * Interfeysdagi resources/js/lib/chemtext.js bilan bir xil qoida:
 *   «SO4» → «SO₄» (pastki indeks), «Fe3+» → «Fe³⁺» (yuqori indeks), «SO₄» → «SO4» (oddiy).
 */
final class ChemText
{
    private const SUB = ['0' => '₀', '1' => '₁', '2' => '₂', '3' => '₃', '4' => '₄', '5' => '₅', '6' => '₆', '7' => '₇', '8' => '₈', '9' => '₉', '+' => '₊', '-' => '₋'];
    private const SUP = ['0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹', '+' => '⁺', '-' => '⁻'];

    /** @return string[] qidiruv so'zining yozuv variantlari (takrorsiz, ko'pi bilan 4 ta) */
    public static function variants(string $q): array
    {
        $plain = strtr($q, array_flip(self::SUB) + array_flip(self::SUP));

        return array_values(array_unique([
            $q,
            $plain,
            self::subscriptDigitsAfterLetters($plain),
            strtr($plain, self::SUP),
        ]));
    }

    /** Harf yoki ) dan keyingi raqamlarni pastki indeksga: SO4 → SO₄, (NH4)2 → (NH₄)₂ */
    public static function subscriptDigitsAfterLetters(string $s): string
    {
        return preg_replace_callback('/(?<=[\p{L})\]])\d+/u', fn ($m) => strtr($m[0], self::SUB), $s) ?? $s;
    }
}
