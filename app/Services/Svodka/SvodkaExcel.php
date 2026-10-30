<?php

namespace App\Services\Svodka;

use Carbon\Carbon;

/**
 * Svodkani Excel'ga: har kun — alohida varaq, buyurtmachi svodkasi ko'rinishida. [Paket 29]
 *   asosiy jadval:  Tartib raqami | Sana / Namuna nomi | I-navbatchilik (Raqam + ko'rsatkichlar)
 *                   | II-navbatchilik (Raqam + ko'rsatkichlar) | O'rtacha ko'rsatgich
 *   qolgan bloklar: sarlavha + jadval (sutkalik yoki ro'yxat)
 * Qiymatlar — faqat laboratoriya boshlig'i chiqargan nusxadan.
 */
class SvodkaExcel
{
    public function __construct(private SvodkaReport $report)
    {
    }

    /** @return string vaqtinchalik fayl yo'li */
    public function build(int $deptId, string $from, string $to, string $deptName): string
    {
        $x = new SvodkaXlsx();
        $d = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        while ($d->lte($end)) {
            $this->day($x, $this->report->day($deptId, $d->toDateString()), $deptName);
            $d->addDay();
        }

        return $x->save();
    }

    private function day(SvodkaXlsx $x, array $day, string $deptName): void
    {
        $sh = $x->sheet(Carbon::parse($day['date'])->format('d.m.y'));
        $set = $day['settings'];
        $x->put($sh, 1, 2, Carbon::parse($day['date'])->format('d.m.Y'), 'title');
        $x->put($sh, 1, 3, $set['title'] ?: $deptName, 'title');
        $x->width($sh, 1, 8);
        $x->width($sh, 2, 26);
        $maxCol = 2;
        $r = 2;

        foreach ($day['sections'] as $i => $sec) {
            $cols = $sec['columns'];
            $n = max(1, count($cols));
            if ($i > 0 || $sec['layout'] !== 'shift') {
                $r++;
                $x->put($sh, $r, 2, $sec['title'], 'title');
                $r++;
            }

            if ($sec['layout'] === 'shift') {
                $x->put($sh, $r, 1, 'Tartib raqami', 'head');
                $x->merge($sh, $r, 1, $r + 1, 1);
                $x->put($sh, $r, 2, $i === 0 ? "Sana\nNamuna nomi" : 'Namuna nomi', 'head');
                $x->merge($sh, $r, 2, $r + 1, 2);
                $c = 3;
                foreach ([1, 2] as $s) {
                    $x->put($sh, $r, $c, $set["shift{$s}_label"] . '  ' . str_replace(':', '', $set["shift{$s}_hours"]), 'head');
                    $x->merge($sh, $r, $c, $r, $c + $n);
                    $x->put($sh, $r + 1, $c, 'Raqam', 'head');
                    foreach ($cols as $k => $col) {
                        $x->put($sh, $r + 1, $c + 1 + $k, $col['label'], 'head');
                    }
                    $c += $n + 1;
                }
                $x->put($sh, $r, $c, "O'rtacha ko'rsatgich", 'head');
                $x->merge($sh, $r, $c, $r, $c + $n - 1);
                foreach ($cols as $k => $col) {
                    $x->put($sh, $r + 1, $c + $k, $col['label'], 'head');
                }
                $maxCol = max($maxCol, $c + $n - 1);
                $x->height($sh, $r, 30);
                $r += 2;
                foreach ($sec['rows'] as $k => $row) {
                    $x->put($sh, $r, 1, $k + 1, 'code');
                    $x->put($sh, $r, 2, $row['name'], 'text');
                    $c = 3;
                    foreach ([1, 2] as $s) {
                        $cell = $row['shifts'][$s];
                        $x->put($sh, $r, $c, $this->codes($cell['codes']), 'code');
                        foreach ($cols as $j => $col) {
                            $x->put($sh, $r, $c + 1 + $j, $cell['values'][$col['element_id']] ?? null, 'num');
                        }
                        $c += $n + 1;
                    }
                    foreach ($cols as $j => $col) {
                        $x->put($sh, $r, $c + $j, $row['avg'][$col['element_id']] ?? null, 'numBold');
                    }
                    $r++;
                }
            } elseif ($sec['layout'] === 'day') {
                $heads = array_merge(['№', 'Namuna nomi', 'Raqam'], array_column($cols, 'label'));
                foreach ($heads as $k => $h) {
                    $x->put($sh, $r, $k + 1, $h, 'head');
                }
                $maxCol = max($maxCol, count($heads));
                $r++;
                foreach ($sec['rows'] as $k => $row) {
                    $x->put($sh, $r, 1, $k + 1, 'code');
                    $x->put($sh, $r, 2, $row['name'], 'text');
                    $x->put($sh, $r, 3, $this->codes($row['codes']), 'code');
                    foreach ($cols as $j => $col) {
                        $x->put($sh, $r, 4 + $j, $row['values'][$col['element_id']] ?? null, 'num');
                    }
                    $r++;
                }
            } else {
                $heads = array_merge(['№', 'Namuna nomi', 'Navbatchilik', 'Raqam'], array_column($cols, 'label'));
                foreach ($heads as $k => $h) {
                    $x->put($sh, $r, $k + 1, $h, 'head');
                }
                $maxCol = max($maxCol, count($heads));
                $r++;
                if (! $sec['rows']) {
                    $x->put($sh, $r, 1, '', 'code');
                    $x->put($sh, $r, 2, '—', 'text');
                    for ($k = 3; $k <= count($heads); $k++) {
                        $x->put($sh, $r, $k, null, 'num');
                    }
                    $r++;
                }
                foreach ($sec['rows'] as $k => $row) {
                    $x->put($sh, $r, 1, $k + 1, 'code');
                    $x->put($sh, $r, 2, $row['name'], 'text');
                    $x->put($sh, $r, 3, $row['shift'] ? $this->shortShift($set["shift{$row['shift']}_label"]) : '', 'code');
                    $x->put($sh, $r, 4, $this->num((string) $row['code'] . ($row['pending'] ? ' *' : '')), 'code');
                    foreach ($cols as $j => $col) {
                        $x->put($sh, $r, 5 + $j, $row['values'][$col['element_id']] ?? null, 'num');
                    }
                    $r++;
                }
            }
        }

        // Holat: qaysi navbatchilik laboratoriya tomonidan hali tasdiqlanmagan
        $r++;
        foreach ([1, 2] as $s) {
            $p = $day['periods'][$s];
            if (! $p['release'] && $p['samples'] > 0) {
                $x->put($sh, $r++, 2, $set["shift{$s}_label"] . ': laboratoriya hali tasdiqlamagan — qiymatlar keyin chiqadi', 'note');
            } elseif ($p['release'] && $p['waiting'] > 0) {
                $x->put($sh, $r++, 2, $set["shift{$s}_label"] . ": {$p['waiting']} ta namunada yangi/tuzatilgan natija tasdiqlanishi kutilmoqda", 'note');
            }
        }
        $x->put($sh, $r, 2, '* — namuna hali laboratoriyaga topshirilmagan', 'note');

        for ($c = 3; $c <= $maxCol; $c++) {
            $x->width($sh, $c, 9.5);
        }
    }

    /** Katakdagi shifrlar; yagona sof raqamli shifr — son sifatida (Excel «matn ko'rinishidagi son» deb belgilamasin) */
    private function codes(array $codes): string|int
    {
        $text = implode(', ', array_map(fn ($c) => $c['code'] . ($c['pending'] ? ' *' : ''), array_filter($codes, fn ($c) => $c['code'] !== null && $c['code'] !== '')));

        return $this->num($text);
    }

    /** Tor ustun uchun: «II-navbatchilik» → «II» (rim raqami bo'lmasa — o'zi) */
    private function shortShift(string $label): string
    {
        return preg_match('/^\s*([IVX]+)\b/u', $label, $m) ? $m[1] : $label;
    }

    private function num(string $v): string|int
    {
        return preg_match('/^[1-9]\d{0,8}$/', $v) ? (int) $v : $v;
    }
}
