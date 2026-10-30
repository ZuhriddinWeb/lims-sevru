<?php

namespace App\Services\Svodka;

use App\Models\Sample;
use Illuminate\Support\Collection;

/**
 * Svodka rejimidagi bo'lim: buyurtmachi portalidagi natijalarni laboratoriya boshlig'i chiqarguncha yopish. [Paket 29]
 * Faqat chiqarilgan va o'shandan beri o'zgarmagan qiymat ko'rinadi (har element alohida);
 * dastlabki (tasdiqlanmagan) natija, varaq ichidagi o'lchovlar, jami va solishtirish — namuna to'liq chiqarilmaguncha yo'q.
 * Svodka rejimida bo'lmagan bo'limlarga hech qanday ta'siri yo'q.
 */
final class SvodkaGate
{
    /** @return array<int, array> sample_id => gate (SvodkaService::gate) */
    public static function for(Collection $samples): array
    {
        if (! class_exists(SvodkaService::class) || $samples->isEmpty()) {
            return [];
        }
        $svc = app(SvodkaService::class);

        return $svc->available() ? $svc->gate($samples) : [];
    }

    /** Ro'yxatdagi natija belgilari (element_id bilan) */
    public static function chips(array $chips, ?array $g): array
    {
        if (! $g || ! $g['enabled']) {
            return $chips;
        }

        return array_map(function (array $c) use ($g) {
            $eid = (int) ($c['element_id'] ?? 0);
            if ($c['state'] === 'final' && ! empty($g['visible'][$eid])) {
                return $c;
            }
            if ($c['state'] === 'final' || $c['state'] === 'preliminary') {   // «hidden» — admin sozlamasi, o'zgarmaydi
                $c['value'] = null;
                $c['decimals'] = null;
                $c['worksheet'] = null;
                $c['state'] = 'locked';
            }

            return $c;
        }, $chips);
    }

    /** Namuna sahifasi javobi (CustomerPortalController::show) */
    public static function sample(array $p, ?array $g): array
    {
        if (! $g || ! $g['enabled']) {
            return $p;
        }
        $p['results'] = array_map(function ($r) use ($g) {
            $eid = (int) ($r['element_id'] ?? 0);
            if (($r['value'] ?? null) !== null && empty($g['visible'][$eid])) {
                $r['value'] = null;
                $r['entered_at'] = null;
                $r['locked'] = true;
            }
            if (! empty($r['preliminary'])) {
                $r['preliminary'] = null;
                $r['locked'] = true;
            }

            return $r;
        }, $p['results'] ?? []);

        if (! $g['full']) {
            $p['analyses'] = array_map(function (array $b) {
                $b['rows'] = [];
                $b['columns'] = [];
                $b['params'] = [];
                $b['summary'] = (object) [];
                $b['tolerance'] = (object) [];
                $b['summary_columns'] = [];
                $b['totals'] = null;

                return $b;
            }, $p['analyses'] ?? []);
            $p['comparison'] = [];
        }
        $p['svodka'] = ['enabled' => true, 'released' => (bool) $g['released'], 'locked' => ! $g['full']];

        return $p;
    }

    /**
     * Varaq bo'yicha jami: o'z namunalaridan birortasi to'liq chiqarilmagan bo'lsa — olib tashlanadi.
     * own_sample_ids (ichki maydon) javobdan olib tashlanadi.
     */
    public static function totals(array $list): array
    {
        $ids = [];
        foreach ($list as $t) {
            foreach ($t['own_sample_ids'] ?? [] as $id) {
                $ids[(int) $id] = true;
            }
        }
        $gates = $ids ? self::for(Sample::query()->whereIn('id', array_keys($ids))
            ->with('sampleElements:id,sample_id,element_id,result_value,result_unit,status')->get()) : [];

        $out = [];
        foreach ($list as $t) {
            $locked = false;
            foreach ($t['own_sample_ids'] ?? [] as $id) {
                $g = $gates[(int) $id] ?? null;
                if ($g && $g['enabled'] && ! $g['full']) {
                    $locked = true;
                    break;
                }
            }
            unset($t['own_sample_ids']);
            if (! $locked) {
                $out[] = $t;
            }
        }

        return $out;
    }
}
