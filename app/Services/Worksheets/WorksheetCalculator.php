<?php

namespace App\Services\Worksheets;

/**
 * Ishchi varaq hisoblagichi — Laravel'ga bog'liq emas (sof PHP).
 *
 * Kirish:
 *   $definition  — shablon nusxasi: ['params' => [...], 'columns' => [...]]
 *   $header      — sarlavha parametrlari qiymatlari: ['namlik' => '0,77', ...]
 *   $rows        — parallellar: [['values' => [...], 'excluded' => false, 'group' => 12], ...]
 *                  'group' — qator qaysi probaga tegishli (partiya varaqda). Berilmasa —
 *                  hamma qatorlar bitta guruh (bitta proba) hisoblanadi.
 *
 * Ustun ko'lamlari:
 *   row     — har bir parallel qatori
 *   summary — har bir proba (guruh) bo'yicha yakuniy natija: o'rtacha, ±% nazorati
 *   total   — butun varaq bo'yicha jami (masalan, barcha korotochkalar yig'indisi)
 *
 * Chiqish:
 *   rows[i].computed          — hisoblangan qator ustunlari
 *   rows[i].invalid           — son bo'lmagan kiritmalar
 *   rows[i].out_of_tolerance  — ruxsat etilgan chegaradan chiqqan yakuniy ustunlar
 *   groups[]                  — har bir proba: key, rows, summary, tolerance, has_warnings
 *   summary / tolerance       — guruh bitta bo'lsa o'shaniki (eski varaqlar uchun), aks holda bo'sh
 *   totals                    — varaq bo'yicha jami ustunlar
 *   errors                    — formula xatolari
 *   has_warnings              — biror ogohlantirish bormi
 *
 * Mantiq JS'da aynan takrorlangan (engine.js) — ekrandagi jonli hisob va
 * serverdagi yakuniy hisob bir xil chiqadi. Yakuniy (rasmiy) hisob — serverniki.
 */
final class WorksheetCalculator
{
    public static function calculate(array $definition, array $header, array $rows): array
    {
        $params  = array_values($definition['params'] ?? []);
        $columns = array_values($definition['columns'] ?? []);
        $rows    = array_values($rows);

        $rowCols = array_values(array_filter($columns, fn ($c) => ($c['scope'] ?? 'row') === 'row'));
        $sumCols = array_values(array_filter($columns, fn ($c) => ($c['scope'] ?? 'row') === 'summary'));
        $totCols = array_values(array_filter($columns, fn ($c) => ($c['scope'] ?? 'row') === 'total'));

        $errors = [];
        $errorSeen = [];
        $addError = function (string $scope, string $key, string $message, ?int $row = null) use (&$errors, &$errorSeen) {
            $sig = $scope . '|' . $key . '|' . $message;
            if (isset($errorSeen[$sig])) {
                return;
            }
            $errorSeen[$sig] = true;
            $errors[] = ['scope' => $scope, 'key' => $key, 'row' => $row, 'message' => $message];
        };

        // ---- Parametrlar (sarlavha) ----
        $paramVals = [];
        foreach ($params as $p) {
            $raw = $header[$p['key']] ?? null;
            if ($raw === null || $raw === '') {
                $raw = $p['default'] ?? null;
            }
            $num = self::parseNumber($raw);
            if ($num === false) {
                $addError('param', $p['key'], 'Son emas: «' . $raw . '»');
                $num = null;
            }
            $paramVals[$p['key']] = $num;
        }

        // ---- Formulalarni bir marta tahlil qilish ----
        $asts = [];
        foreach ($columns as $c) {
            if (! self::isComputed($c)) {
                continue;
            }
            try {
                $asts[$c['key']] = Formula::parse((string) ($c['formula'] ?? ''));
            } catch (FormulaException $e) {
                $asts[$c['key']] = null;
                $addError($c['scope'] ?? 'row', $c['key'], $e->getMessage());
            }
        }

        [$rowOrder, $rowCycle] = self::order(
            array_values(array_filter($rowCols, fn ($c) => self::isComputed($c))),
            $asts
        );
        [$sumOrder, $sumCycle] = self::order($sumCols, $asts);
        [$totOrder, $totCycle] = self::order($totCols, $asts);
        foreach ($rowCycle as $k) {
            $addError('row', $k, "Formulalar bir-biriga aylanma bog'langan");
        }
        foreach ($sumCycle as $k) {
            $addError('summary', $k, "Formulalar bir-biriga aylanma bog'langan");
        }
        foreach ($totCycle as $k) {
            $addError('total', $k, "Formulalar bir-biriga aylanma bog'langan");
        }

        $rowColByKey = [];
        foreach ($rowCols as $c) {
            $rowColByKey[$c['key']] = $c;
        }
        $sumColByKey = [];
        foreach ($sumCols as $c) {
            $sumColByKey[$c['key']] = $c;
        }
        $totColByKey = [];
        foreach ($totCols as $c) {
            $totColByKey[$c['key']] = $c;
        }

        // ---- Qatorlar ----
        $outRows = [];
        $rowValues = []; // [i => [key => float|null]]  (input + computed, text emas)
        foreach (array_values($rows) as $i => $row) {
            $values = $row['values'] ?? [];
            $vars = $paramVals;
            $invalid = [];

            foreach ($rowCols as $c) {
                if (($c['type'] ?? 'input') !== 'input') {
                    continue;
                }
                $num = self::parseNumber($values[$c['key']] ?? null);
                if ($num === false) {
                    $invalid[$c['key']] = 'Son emas';
                    $num = null;
                }
                $vars[$c['key']] = $num;
            }

            $computed = [];
            foreach ($rowOrder as $key) {
                $c = $rowColByKey[$key];
                $value = null;
                if ($asts[$key] !== null) {
                    try {
                        $value = Rounding::apply(
                            Formula::evaluate($asts[$key], $vars),
                            self::decimals($c),
                            $c['rounding'] ?? 'round'
                        );
                    } catch (FormulaException $e) {
                        $addError('row', $key, $e->getMessage());
                    }
                }
                $vars[$key] = $value;
                $computed[$key] = $value;
            }
            foreach ($rowCycle as $key) {
                $computed[$key] = null;
                $vars[$key] = null;
            }

            $rowValues[$i] = $vars;
            $outRows[$i] = [
                'computed'         => $computed,
                'invalid'          => (object) $invalid,
                'out_of_tolerance' => [],
            ];
        }

        // ---- Guruhlar (probalar) — qatorlar paydo bo'lish tartibida ----
        $groupOrder = [];
        $groupRows = [];
        foreach (array_values($rows) as $i => $row) {
            $g = $row['group'] ?? null;
            $gk = ($g === null || $g === '') ? '_' : (string) $g;
            if (! isset($groupRows[$gk])) {
                $groupRows[$gk] = [];
                $groupOrder[] = ['sig' => $gk, 'key' => ($g === null || $g === '') ? '_' : $g];
            }
            $groupRows[$gk][] = $i;
        }
        if (! $groupOrder) {
            $groupOrder[] = ['sig' => '_', 'key' => '_'];
            $groupRows['_'] = [];
        }

        $groups = [];
        $hasWarnings = ! empty($errors);
        foreach ($groupOrder as $g) {
            $included = [];
            foreach ($groupRows[$g['sig']] as $i) {
                if (empty($rows[$i]['excluded'])) {
                    $included[] = $i;
                }
            }

            // Yakuniy natijalar — faqat shu probaning hisobga olingan parallellaridan
            $sumVars = self::listVars($paramVals, $rowCols, $rowValues, $included);
            $summary = [];
            foreach ($sumOrder as $key) {
                $c = $sumColByKey[$key];
                $value = null;
                if ($asts[$key] !== null) {
                    try {
                        $value = Rounding::apply(
                            Formula::evaluate($asts[$key], $sumVars),
                            self::decimals($c),
                            $c['rounding'] ?? 'round'
                        );
                    } catch (FormulaException $e) {
                        $addError('summary', $key, $e->getMessage());
                    }
                }
                $sumVars[$key] = $value;
                $summary[$key] = $value;
            }
            foreach ($sumCycle as $key) {
                $summary[$key] = null;
            }

            // Parallellar farqi nazorati — shu proba ichida
            $tolerance = [];
            $groupWarn = false;
            foreach ($sumCols as $c) {
                $limit = isset($c['tolerance_pct']) && $c['tolerance_pct'] !== '' ? (float) $c['tolerance_pct'] : null;
                $source = $c['tolerance_source'] ?? null;
                if (! $limit || $limit <= 0 || ! $source || ! isset($rowColByKey[$source])) {
                    continue;
                }
                $mode = ($c['tolerance_mode'] ?? 'mean') === 'range' ? 'range' : 'mean';

                $vals = [];
                foreach ($included as $i) {
                    $v = $rowValues[$i][$source] ?? null;
                    if ($v !== null) {
                        $vals[$i] = $v;
                    }
                }

                $entry = [
                    'source' => $source, 'limit' => $limit, 'mode' => $mode,
                    'deviation' => null, 'ok' => null, 'rows' => [], 'n' => count($vals),
                ];

                if (count($vals) >= 2) {
                    $mean = array_sum($vals) / count($vals);
                    if ($mean != 0.0) {
                        $bad = [];
                        if ($mode === 'range') {
                            $max = max($vals);
                            $min = min($vals);
                            $dev = ($max - $min) / abs($mean) * 100;
                            if ($dev > $limit + 1e-9) {
                                foreach ($vals as $i => $v) {
                                    if ($v == $max || $v == $min) {
                                        $bad[] = $i;
                                    }
                                }
                            }
                        } else {
                            $dev = 0.0;
                            foreach ($vals as $i => $v) {
                                $d = abs($v - $mean) / abs($mean) * 100;
                                $dev = max($dev, $d);
                                if ($d > $limit + 1e-9) {
                                    $bad[] = $i;
                                }
                            }
                        }
                        $entry['deviation'] = Rounding::apply($dev, 2, 'round');
                        $entry['ok'] = $dev <= $limit + 1e-9;
                        $entry['rows'] = $bad;
                        foreach ($bad as $i) {
                            $outRows[$i]['out_of_tolerance'][] = $c['key'];
                        }
                        if ($entry['ok'] === false) {
                            $groupWarn = true;
                        }
                    }
                }

                $tolerance[$c['key']] = $entry;
            }

            foreach ($groupRows[$g['sig']] as $i) {
                if (count((array) $outRows[$i]['invalid']) > 0) {
                    $groupWarn = true;
                }
            }
            if ($groupWarn) {
                $hasWarnings = true;
            }

            $groups[] = [
                'key'          => $g['key'],
                'rows'         => $groupRows[$g['sig']],
                'summary'      => (object) $summary,
                'tolerance'    => (object) $tolerance,
                'has_warnings' => $groupWarn,
            ];
        }

        // ---- Varaq bo'yicha jami — barcha probalarning hisobga olingan qatorlari ----
        $allIncluded = [];
        foreach (array_values($rows) as $i => $row) {
            if (empty($row['excluded'])) {
                $allIncluded[] = $i;
            }
        }
        $totVars = self::listVars($paramVals, $rowCols, $rowValues, $allIncluded);
        $totals = [];
        foreach ($totOrder as $key) {
            $c = $totColByKey[$key];
            $value = null;
            if ($asts[$key] !== null) {
                try {
                    $value = Rounding::apply(
                        Formula::evaluate($asts[$key], $totVars),
                        self::decimals($c),
                        $c['rounding'] ?? 'round'
                    );
                } catch (FormulaException $e) {
                    $addError('total', $key, $e->getMessage());
                }
            }
            $totVars[$key] = $value;
            $totals[$key] = $value;
        }
        foreach ($totCycle as $key) {
            $totals[$key] = null;
        }

        foreach ($outRows as $r) {
            if (count((array) $r['invalid']) > 0) {
                $hasWarnings = true;
            }
        }
        if (! empty($errors)) {
            $hasWarnings = true;
        }

        $single = count($groups) === 1;

        return [
            'rows'         => $outRows,
            'groups'       => $groups,
            'summary'      => $single ? $groups[0]['summary'] : (object) [],
            'tolerance'    => $single ? $groups[0]['tolerance'] : (object) [],
            'totals'       => (object) $totals,
            'errors'       => $errors,
            'has_warnings' => $hasWarnings,
        ];
    }

    /** Yakuniy formulalar uchun o'zgaruvchilar: parametrlar (son) + qator ustunlari (ro'yxat) */
    private static function listVars(array $paramVals, array $rowCols, array $rowValues, array $indices): array
    {
        $vars = $paramVals;
        foreach ($rowCols as $c) {
            if (($c['type'] ?? 'input') === 'text') {
                continue;
            }
            $list = [];
            foreach ($indices as $i) {
                $list[] = $rowValues[$i][$c['key']] ?? null;
            }
            $vars[$c['key']] = $list;
        }

        return $vars;
    }

    /**
     * "0,093" / "0.093" / " 1 234,5 " → float; bo'sh → null; son emas → false.
     *
     * @return float|null|false
     */
    public static function parseNumber($raw)
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return is_finite((float) $raw) ? (float) $raw : false;
        }
        if (! is_string($raw)) {
            return false;
        }
        $s = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim($raw));
        if ($s === '') {
            return null;
        }
        if (! preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $s)) {
            return false;
        }

        return (float) $s;
    }

    public static function isComputed(array $c): bool
    {
        $scope = $c['scope'] ?? 'row';

        return $scope === 'summary' || $scope === 'total' || ($c['type'] ?? 'input') === 'computed';
    }

    private static function decimals(array $c): ?int
    {
        return isset($c['decimals']) && $c['decimals'] !== '' && $c['decimals'] !== null ? (int) $c['decimals'] : null;
    }

    /**
     * Hisoblanadigan ustunlarni bog'liqlik tartibida saralash (Kahn algoritmi).
     *
     * @return array{0: string[], 1: string[]}  [tartib, aylanma bog'langanlar]
     */
    public static function order(array $cols, array $asts): array
    {
        $keys = array_map(fn ($c) => $c['key'], $cols);
        $inScope = array_flip($keys);
        $deps = [];
        foreach ($keys as $k) {
            $ids = isset($asts[$k]) && $asts[$k] !== null ? Formula::identifiers($asts[$k]) : [];
            $deps[$k] = array_values(array_filter($ids, fn ($id) => isset($inScope[$id])));
        }

        $order = [];
        $done = [];
        $progress = true;
        while ($progress) {
            $progress = false;
            foreach ($keys as $k) {
                if (isset($done[$k])) {
                    continue;
                }
                $ready = true;
                foreach ($deps[$k] as $d) {
                    if (! isset($done[$d])) {
                        $ready = false;
                        break;
                    }
                }
                if ($ready) {
                    $done[$k] = true;
                    $order[] = $k;
                    $progress = true;
                }
            }
        }

        $cyclic = array_values(array_filter($keys, fn ($k) => ! isset($done[$k])));

        return [$order, $cyclic];
    }
}
