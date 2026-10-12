<?php

namespace App\Services\Worksheets;

/**
 * Shablon konfiguratsiyasini tekshiradi (kalitlar, formulalar, aylanma bog'lanish).
 * Xatolar ['field' => 'columns.3.formula', 'message' => '...'] ko'rinishida qaytadi.
 *
 * JS'dagi validateDefinition() (engine.js) shu qoidalarni takrorlaydi —
 * konstruktorda xato darhol ko'rinadi, server esa saqlashda qayta tekshiradi.
 */
final class TemplateValidator
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';
    public const ROW_TYPES = ['input', 'computed', 'text'];
    public const TOLERANCE_MODES = ['mean', 'range'];

    public static function validate(array $def): array
    {
        $errors = [];
        $err = function (string $field, string $message) use (&$errors) {
            $errors[] = ['field' => $field, 'message' => $message];
        };

        $stages  = array_values($def['stages'] ?? []);
        $params  = array_values($def['params'] ?? []);
        $columns = array_values($def['columns'] ?? []);

        // ---- Bosqichlar ----
        $stageKeys = [];
        foreach ($stages as $i => $s) {
            $key = (string) ($s['key'] ?? '');
            if (! preg_match(self::KEY_PATTERN, $key)) {
                $err("stages.$i.key", "Kalit lotin kichik harf bilan boshlanib, harf/raqam/_ dan iborat bo'lsin");
            } elseif (isset($stageKeys[$key])) {
                $err("stages.$i.key", "Bosqich kaliti takrorlangan: $key");
            }
            $stageKeys[$key] = true;
            if (trim((string) ($s['label_uz'] ?? '')) === '') {
                $err("stages.$i.label_uz", 'Bosqich nomi kiritilmagan');
            }
            $signers = (int) ($s['signers'] ?? 1);
            if ($signers < 0 || $signers > 6) {
                $err("stages.$i.signers", "Imzolovchilar soni 0–6 oralig'ida bo'lsin");
            }
        }

        // ---- Kalitlar (parametr + ustun) — umumiy nomlar maydoni ----
        $allKeys = [];
        $checkKey = function (string $field, $key) use (&$allKeys, $err) {
            $key = (string) $key;
            if (! preg_match(self::KEY_PATTERN, $key)) {
                $err($field, "Kalit lotin kichik harf bilan boshlanib, harf/raqam/_ dan iborat bo'lsin (masalan: kortochka)");

                return;
            }
            if (in_array($key, Formula::FUNCTIONS, true)) {
                $err($field, "«{$key}» — funksiya nomi, kalit sifatida ishlatib bo'lmaydi");

                return;
            }
            if (isset($allKeys[$key])) {
                $err($field, "Kalit takrorlangan: $key");

                return;
            }
            $allKeys[$key] = true;
        };

        foreach ($params as $i => $p) {
            $checkKey("params.$i.key", $p['key'] ?? '');
            if (trim((string) ($p['label_uz'] ?? '')) === '') {
                $err("params.$i.label_uz", 'Parametr nomi kiritilmagan');
            }
            $def_ = $p['default'] ?? null;
            if ($def_ !== null && $def_ !== '' && WorksheetCalculator::parseNumber($def_) === false) {
                $err("params.$i.default", 'Boshlang\'ich qiymat son bo\'lishi kerak');
            }
        }

        $rowCount = 0;
        $rowKeysNumeric = [];
        foreach ($columns as $i => $c) {
            $scope = $c['scope'] ?? 'row';
            $checkKey("columns.$i.key", $c['key'] ?? '');

            if (! in_array($scope, ['row', 'summary', 'total'], true)) {
                $err("columns.$i.scope", "Noto'g'ri ko'lam");
                continue;
            }
            if (trim((string) ($c['label_uz'] ?? '')) === '') {
                $err("columns.$i.label_uz", 'Ustun nomi kiritilmagan');
            }

            if ($scope === 'row') {
                $rowCount++;
                $type = $c['type'] ?? 'input';
                if (! in_array($type, self::ROW_TYPES, true)) {
                    $err("columns.$i.type", "Noto'g'ri tur");
                }
                if ($type !== 'text') {
                    $rowKeysNumeric[(string) ($c['key'] ?? '')] = true;
                }
                if (! empty($c['element_id'])) {
                    $err("columns.$i.element_id", 'Element faqat yakuniy natija ustuniga biriktiriladi');
                }
            }
            if ($scope === 'total' && ! empty($c['element_id'])) {
                $err("columns.$i.element_id", 'Element faqat yakuniy natija ustuniga biriktiriladi');
            }

            $stage = $c['stage'] ?? null;
            if ($stage !== null && $stage !== '' && ! isset($stageKeys[$stage])) {
                $err("columns.$i.stage", "Bunday bosqich yo'q: $stage");
            }

            $dec = $c['decimals'] ?? null;
            if ($dec !== null && $dec !== '' && (! is_numeric($dec) || (int) $dec < 0 || (int) $dec > 10)) {
                $err("columns.$i.decimals", "Xona soni 0–10 oralig'ida bo'lsin");
            }
            if (! in_array($c['rounding'] ?? 'round', Rounding::MODES, true)) {
                $err("columns.$i.rounding", "Noto'g'ri yaxlitlash usuli");
            }

            if (WorksheetCalculator::isComputed($c)) {
                $formula = trim((string) ($c['formula'] ?? ''));
                if ($formula === '') {
                    $err("columns.$i.formula", 'Hisoblanadigan ustun uchun formula kiritilmagan');
                } else {
                    try {
                        Formula::parse($formula);
                    } catch (FormulaException $e) {
                        $err("columns.$i.formula", $e->getMessage());
                    }
                }
            }
        }

        if ($rowCount === 0) {
            $err('columns', "Kamida bitta qator ustuni bo'lishi kerak");
        }

        // ---- Bog'liqlik qoidalari ----
        $paramKeys = array_flip(array_map(fn ($p) => (string) ($p['key'] ?? ''), $params));
        $summaryKeys = [];
        $totalKeys = [];
        foreach ($columns as $c) {
            if (($c['scope'] ?? 'row') === 'summary') {
                $summaryKeys[(string) ($c['key'] ?? '')] = true;
            }
            if (($c['scope'] ?? 'row') === 'total') {
                $totalKeys[(string) ($c['key'] ?? '')] = true;
            }
        }

        foreach ($columns as $i => $c) {
            if (! WorksheetCalculator::isComputed($c)) {
                continue;
            }
            try {
                $ast = Formula::parse((string) ($c['formula'] ?? ''));
            } catch (FormulaException $e) {
                continue; // yuqorida xabar berilgan
            }
            $scope = $c['scope'] ?? 'row';
            foreach (Formula::identifiers($ast) as $id) {
                $known = isset($paramKeys[$id]) || isset($rowKeysNumeric[$id])
                    || ($scope === 'summary' && isset($summaryKeys[$id]))
                    || ($scope === 'total' && isset($totalKeys[$id]));
                if (! $known) {
                    if ($scope === 'row' && isset($summaryKeys[$id])) {
                        $err("columns.$i.formula", "Qator formulasida yakuniy natija («{$id}») ishlatib bo'lmaydi");
                    } elseif (isset($totalKeys[$id])) {
                        $err("columns.$i.formula", "Jami («{$id}») faqat boshqa jami formulasida ishlatiladi");
                    } elseif ($scope === 'total' && isset($summaryKeys[$id])) {
                        $err("columns.$i.formula", "Jami formulasida yakuniy natija («{$id}») ishlatib bo'lmaydi");
                    } elseif (isset($allKeys[$id])) {
                        $err("columns.$i.formula", "«{$id}» — matnli ustun, formulada ishlatib bo'lmaydi");
                    } else {
                        $err("columns.$i.formula", "Noma'lum o'zgaruvchi: «{$id}»");
                    }
                }
            }

            // Ruxsat etilgan farq
            if ($scope === 'summary') {
                $pct = $c['tolerance_pct'] ?? null;
                if ($pct !== null && $pct !== '') {
                    if (! is_numeric($pct) || (float) $pct <= 0 || (float) $pct > 100) {
                        $err("columns.$i.tolerance_pct", "Ruxsat etilgan farq 0 dan katta, 100 dan kichik bo'lsin");
                    }
                    $src = $c['tolerance_source'] ?? null;
                    if (! $src || ! isset($rowKeysNumeric[$src])) {
                        $err("columns.$i.tolerance_source", "Farq qaysi qator ustuni bo'yicha tekshirilishini tanlang");
                    }
                    if (! in_array($c['tolerance_mode'] ?? 'mean', self::TOLERANCE_MODES, true)) {
                        $err("columns.$i.tolerance_mode", "Noto'g'ri usul");
                    }
                }
            }
        }

        if ($errors) {
            return $errors;
        }

        // ---- Sinov hisobi: aylanma bog'lanish va ro'yxat/son aralashuvi ----
        $dryRows = [];
        for ($r = 0; $r < 2; $r++) {
            $vals = [];
            foreach ($columns as $c) {
                if (($c['scope'] ?? 'row') === 'row' && ($c['type'] ?? 'input') === 'input') {
                    $vals[$c['key']] = '1';
                }
            }
            $dryRows[] = ['values' => $vals, 'excluded' => false];
        }
        $dryHeader = [];
        foreach ($params as $p) {
            $dryHeader[$p['key']] = '1';
        }

        $result = WorksheetCalculator::calculate($def, $dryHeader, $dryRows);
        foreach ($result['errors'] as $e) {
            $idx = null;
            foreach ($columns as $i => $c) {
                if (($c['key'] ?? null) === $e['key']) {
                    $idx = $i;
                    break;
                }
            }
            $err($idx !== null ? "columns.$idx.formula" : 'columns', $e['message']);
        }

        return $errors;
    }
}
