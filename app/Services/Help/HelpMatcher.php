<?php

namespace App\Services\Help;

/**
 * Yordamchi: savolga eng mos yozuvni topish (Laravelga bog'liq emas).
 *
 * Yozuv (entry) shakli:
 *   ['id'=>int, 'section'=>str, 'route'=>?str, 'permission'=>?str, 'keywords'=>str[],
 *    'uz'=>['q'=>str,'alt'=>str[],'a'=>str], 'ru'=>[...], 'en'=>[...]]
 *
 * Qidiruv uch tilning savollari + qo'shimcha so'rovlari + kalit so'zlari bo'yicha olib boriladi
 * (dastur uzbekcha bo'lsa ham rus yoki ingliz tilida yozish mumkin), javob esa foydalanuvchi tilida qaytadi.
 */
final class HelpMatcher
{
    public const LANGS = ['uz', 'ru', 'en'];

    /** Shu balldan yuqori — to'g'ridan-to'g'ri javob; shundan past, lekin MIN_SUGGEST dan yuqori — «shuni nazarda tutdingizmi?» */
    public const ANSWER = 0.62;
    public const MIN_SUGGEST = 0.38;

    /**
     * Qidiruv indeksini qurish (keshga saqlanadi).
     *
     * @param  array<int,array> $entries
     */
    public static function buildIndex(array $entries): array
    {
        $docs = [];
        $df = [];
        foreach ($entries as $e) {
            $variants = [];
            $phrases = [];
            $seen = [];
            foreach (self::LANGS as $lang) {
                $block = $e[$lang] ?? null;
                if (! $block) {
                    continue;
                }
                foreach (array_merge([$block['q'] ?? ''], $block['alt'] ?? []) as $i => $text) {
                    $tok = array_values(array_unique(HelpText::tokens((string) $text)));
                    if ($tok === []) {
                        continue;
                    }
                    $variants[] = ['k' => $i === 0 ? 'q' : 'alt', 't' => $tok];
                    $phrases[HelpText::fold((string) $text)] = true;
                    foreach ($tok as $w) {
                        $seen[$w] = true;
                    }
                }
            }
            $kw = [];
            foreach ($e['keywords'] ?? [] as $k) {
                foreach (HelpText::tokens((string) $k) as $w) {
                    $kw[$w] = true;
                }
            }
            if ($kw) {
                $variants[] = ['k' => 'kw', 't' => array_keys($kw)];
                foreach ($kw as $w => $_) {
                    $seen[$w] = true;
                }
            }
            // Javob matni — kuchsiz signal (oxirgi chora)
            foreach (self::LANGS as $lang) {
                $a = $e[$lang]['a'] ?? '';
                if ($a !== '') {
                    $tok = array_slice(array_values(array_unique(HelpText::tokens($a))), 0, 160);
                    if ($tok) {
                        $variants[] = ['k' => 'ans', 't' => $tok];
                    }
                }
            }
            foreach (array_keys($seen) as $w) {
                $df[$w] = ($df[$w] ?? 0) + 1;
            }
            $docs[$e['id']] = ['v' => $variants, 'p' => array_keys($phrases)];
        }
        $n = max(1, count($entries));
        $idf = [];
        foreach ($df as $w => $c) {
            $idf[$w] = log(1 + $n / (1 + $c)) + 0.15;
        }

        return ['n' => $n, 'idf' => $idf, 'docs' => $docs];
    }

    /**
     * @param  array  $index   buildIndex() natijasi
     * @param  array<int,array>  $meta  id => ['route'=>?]  (foydalanuvchi turgan sahifaga ustuvorlik uchun)
     * @param  callable|null  $allow  fn(int $id): bool — foydalanuvchiga ruxsat etilganlarni qoldiradi
     * @return array<int,array{id:int,score:float}>  ball bo'yicha kamayish tartibida (eng ko'pi $limit ta)
     */
    public static function search(array $index, string $query, ?string $route = null, array $meta = [], ?callable $allow = null, int $limit = 6): array
    {
        $folded = HelpText::fold($query);
        $qt = array_values(array_unique(HelpText::tokens($query)));
        if ($folded === '' || $qt === []) {
            return [];
        }
        $idf = $index['idf'];
        $maxIdf = log(1 + $index['n']) + 0.15;
        $w = [];
        foreach ($qt as $t) {
            $w[$t] = $idf[$t] ?? $maxIdf * 0.3;   // bazada yo'q so'z (imlo xatosi, suhbat so'zi) natijani buzmasin
        }
        $sumQ = array_sum($w);

        $out = [];
        foreach ($index['docs'] as $id => $doc) {
            if ($allow && ! $allow($id)) {
                continue;
            }
            if (in_array($folded, $doc['p'], true)) {
                $best = 1.0;
            } else {
                $best = 0.0;
                foreach ($doc['v'] as $var) {
                    $s = self::variantScore($w, $sumQ, $var, $idf, $maxIdf);
                    if ($s > $best) {
                        $best = $s;
                    }
                }
                // bitta so'zli so'rov noaniq — to'g'ridan-to'g'ri javob bermaymiz, ro'yxat ko'rsatamiz
                if (count($qt) === 1 && $best > 0.6) {
                    $best = 0.6;
                }
            }
            if ($best < 0.2) {
                continue;
            }
            // Foydalanuvchi turgan sahifaga oid yozuvga ozgina ustuvorlik
            if ($route && ($meta[$id]['route'] ?? null) === $route && $best >= 0.3) {
                $best = min(1.0, $best + 0.06);
            }
            $out[] = ['id' => (int) $id, 'score' => round($best, 4)];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);

        return array_slice($out, 0, $limit);
    }

    private static function variantScore(array $w, float $sumQ, array $var, array $idf, float $maxIdf): float
    {
        $vt = $var['t'];
        $got = 0.0;
        $strong = false;
        $matchedV = [];
        foreach ($w as $t => $iw) {
            $bw = 0.0;
            $bv = null;
            foreach ($vt as $x) {
                $l = HelpText::likeness((string) $t, (string) $x);
                if ($l > $bw) {
                    $bw = $l;
                    $bv = $x;
                    if ($l === 1.0) {
                        break;
                    }
                }
            }
            if ($bw > 0) {
                $got += $iw * $bw;
                if ($bw >= 0.85) {
                    $strong = true;
                }
                $matchedV[$bv] = max($matchedV[$bv] ?? 0, $bw);
            }
        }
        if (! $strong) {
            return 0.0;
        }
        $qcov = $got / $sumQ;
        $k = $var['k'];
        if ($k === 'kw') {
            return $qcov * 0.72;
        }
        if ($k === 'ans') {
            return $qcov * 0.5;
        }
        $sumV = 0.0;
        $gotV = 0.0;
        foreach ($vt as $x) {
            $iw = $idf[$x] ?? $maxIdf;
            $sumV += $iw;
            if (isset($matchedV[$x])) {
                $gotV += $iw * $matchedV[$x];
            }
        }
        $vcov = $sumV > 0 ? $gotV / $sumV : 0.0;

        return $qcov * (0.62 + 0.38 * $vcov);
    }

    /** @return 'answer'|'suggest'|'none' */
    public static function verdict(array $ranked): string
    {
        $top = $ranked[0]['score'] ?? 0.0;
        if ($top >= self::ANSWER) {
            return 'answer';
        }

        return $top >= self::MIN_SUGGEST ? 'suggest' : 'none';
    }
}
