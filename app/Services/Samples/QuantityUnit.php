<?php

namespace App\Services\Samples;

/**
 * Proba miqdori birliklari: massa (mg, g, kg) va hajm (ml, l).
 * Shablon ustunlarida birlik turlicha yozilgan bo'lishi mumkin: «g», «г», «гр», «мл» ...
 */
class QuantityUnit
{
    public const UNITS = ['g', 'kg', 'mg', 'ml', 'l'];

    private const FACTOR = [
        'mg' => ['mass', 0.001],
        'g'  => ['mass', 1.0],
        'kg' => ['mass', 1000.0],
        'ml' => ['volume', 1.0],
        'l'  => ['volume', 1000.0],
    ];

    private const ALIASES = [
        'mg' => 'mg', 'мг' => 'mg',
        'g' => 'g', 'gr' => 'g', 'г' => 'g', 'гр' => 'g', 'gramm' => 'g', 'грамм' => 'g',
        'kg' => 'kg', 'кг' => 'kg',
        'ml' => 'ml', 'мл' => 'ml', 'cm3' => 'ml', 'см3' => 'ml',
        'l' => 'l', 'л' => 'l', 'litr' => 'l', 'литр' => 'l',
    ];

    /** Ustun/proba birligini kanonik ko'rinishga keltiradi; tanilmasa — null */
    public static function normalize(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }
        $u = mb_strtolower(trim($unit));
        $u = trim($u, " .,;:()[]");
        $u = str_replace(['³', ' '], ['3', ''], $u);

        return self::ALIASES[$u] ?? null;
    }

    public static function dimension(?string $unit): ?string
    {
        $u = self::normalize($unit);

        return $u ? self::FACTOR[$u][0] : null;
    }

    /** Birlikdan birlikka o'tkazish; o'lchov turi mos kelmasa (g ↔ ml) — null */
    public static function convert(float $value, ?string $from, ?string $to): ?float
    {
        $f = self::normalize($from);
        $t = self::normalize($to);
        if (! $f || ! $t || self::FACTOR[$f][0] !== self::FACTOR[$t][0]) {
            return null;
        }

        return $value * self::FACTOR[$f][1] / self::FACTOR[$t][1];
    }

    /** Son sifatida o'qish: «1,0» → 1.0; son bo'lmasa — null */
    public static function number(mixed $raw): ?float
    {
        if ($raw === null || $raw === '' || is_bool($raw)) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        $s = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim((string) $raw));

        return is_numeric($s) ? (float) $s : null;
    }

    public static function round(?float $v): ?float
    {
        return $v === null ? null : round($v, 4) + 0.0;
    }
}
