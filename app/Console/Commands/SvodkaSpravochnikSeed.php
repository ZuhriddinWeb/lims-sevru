<?php

namespace App\Console\Commands;

use App\Models\CustomerDepartment;
use App\Models\Element;
use App\Models\SvodkaPoint;
use App\Models\SvodkaSection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyurtmachi svodkasi spravochnigini GMZ-3 svodkasi (svodka.xlsx) bo'yicha to'ldirish.
 *
 *   php artisan svodka:spravochnik S2            (bo'lim kodi, id yoki nomi)
 *   php artisan svodka:spravochnik S2 --dry-run  (faqat ko'rsatadi)
 *
 * Qayta ishga tushirish xavfsiz: bor blok (nomi bo'yicha) va bor nuqta (nomi bo'yicha) qayta qo'shilmaydi,
 * buyurtmachi qo'shgan/o'zgartirgan narsaga tegilmaydi. Elementlar katalogiga hech narsa qo'shilmaydi —
 * katalogda topilmagan ko'rsatkich ustuni o'tkazib yuboriladi (keyin «Svodka → Nuqtalar» da qo'shiladi).
 * Svodka rejimini yoqmaydi — buni laboratoriya «Svodkalar → Sozlama» da qiladi.
 */
class SvodkaSpravochnikSeed extends Command
{
    protected $signature = 'svodka:spravochnik {bolim : Buyurtmachi bo\'lim kodi, id yoki nomi} {--dry-run : Faqat ko\'rsatish, yozmaslik}';

    protected $description = "Buyurtmachi svodkasi spravochnigini (bloklar va nuqtalar) GMZ-3 svodkasi bo'yicha to'ldirish";

    /** Ko'rsatkich: element belgisi variantlari (katalogdagi birinchi topilgani olinadi) */
    private const EL = [
        'Au'   => ['Au'],
        'Ag'   => ['Ag'],
        'S'    => ['S', 'Ss', 'S_sulf', 'Sсульф', 'S сульф'],
        'Corg' => ['Corg', 'C_org', 'Сорг', 'Cорг', 'C org'],
        'Ca'   => ['Ca', 'Са'],
    ];

    /** svodka.xlsx dagi bloklar: [nomi, ko'rinishi, [[element, ustun nomi]...], [nuqtalar...]] */
    private const SECTIONS = [
        ['Asosiy', 'shift', [['Au', 'Au,g/t'], ['Ag', 'Ag,g/t'], ['S', 'Ss%'], ['Corg', 'Сорг%']], [
            '№2 "K" SAF', '№3 "D" SAF', '№5 "A" SAF',
            '"K" fl.chiqindisi', '"D"fl.chiqindisi', '"A"fl.chiqindisi',
            '"K" Fl.Boyit.', '"D" Fl.Boyit.', '"A" Fl.Boyit.',
            'Sut. Oqim taqsim.', 'Sut. Oqim taqsim.-2', 'SUT. 3-QQYQsut.', 'Sut. MASQ',
            '"Kеm."kirish sut.', '"Kеm"chiqindi.sut.',
            'Sorb.kirish', 'Sorb.chiqindisi-1', 'Sorb.chiqindisi-2', 'ODP', 'Sutkali.zav.chiqin.',
        ]],
        ['Partiya', 'list', [['Au', 'Au,%'], ['Ag', 'Ag,%']], ['Partiya']],
        ['LSU', 'list', [['Au', 'Au']], ['LSU yuqori', 'LSU past']],
        ['RK', 'list', [['Au', 'Au,g/kg']], ['RK-1', 'RK-2', 'RK-3', 'RK-4', 'RK-5']],
        ["Boyitilgan ko'mir", 'list', [['Au', 'Au,g/kg'], ['Ca', 'Ca']], ["Boyitilgan ko'mir"]],
        ["Oltinsizlantirilgan ko'mir", 'list', [['Au', 'Au,g/kg'], ['Ca', 'Ca']], ["Oltinsizlantirilgan ko'mir (oldin)", "Oltinsizlantirilgan ko'mir (keyin)"]],
        ["Ko'mir maydasi", 'list', [['Au', 'Au']], ["Ko'mir maydasi (sianlashdan oldin)", "Ko'mir maydasi (sianlashdan keyin)"]],
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('svodka_sections') || ! Schema::hasTable('svodka_points')) {
            $this->error('Svodka jadvallari yo\'q — avval: php artisan migrate');

            return self::FAILURE;
        }
        $dept = $this->department((string) $this->argument('bolim'));
        if (! $dept) {
            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $this->info("Bo'lim: {$dept->name}" . ($dept->code ? " ({$dept->code})" : '') . " — id {$dept->id}" . ($dry ? '   [--dry-run: yozilmaydi]' : ''));

        $elements = $this->elements();
        $missing = [];
        $stat = ['sections' => 0, 'points' => 0, 'skipped_points' => 0];

        DB::transaction(function () use ($dept, $elements, $dry, &$missing, &$stat) {
            $sort = (int) SvodkaSection::where('customer_department_id', $dept->id)->max('sort_order');
            foreach (self::SECTIONS as [$title, $layout, $cols, $points]) {
                $columns = [];
                foreach ($cols as [$key, $label]) {
                    if (isset($elements[$key])) {
                        $columns[] = ['element_id' => $elements[$key], 'label' => $label];
                    } else {
                        $missing[$key] = true;
                    }
                }
                $sec = SvodkaSection::where('customer_department_id', $dept->id)->where('title', $title)->first();
                if (! $sec) {
                    $this->line("  + blok «{$title}» (" . count($columns) . ' ustun: ' . (implode(', ', array_column($columns, 'label')) ?: '—') . ')');
                    $stat['sections']++;
                    if (! $dry) {
                        $sec = SvodkaSection::create([
                            'customer_department_id' => $dept->id, 'title' => $title, 'layout' => $layout,
                            'columns' => $columns, 'sort_order' => ++$sort, 'is_active' => true,
                        ]);
                    }
                } else {
                    $this->line("  = blok «{$title}» bor — ustunlari o'zgartirilmaydi");
                }

                $existing = $sec ? SvodkaPoint::where('section_id', $sec->id)->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all() : [];
                $psort = $sec ? (int) SvodkaPoint::where('section_id', $sec->id)->max('sort_order') : 0;
                $added = 0;
                foreach ($points as $name) {
                    if (in_array(mb_strtolower($name), $existing, true)) {
                        $stat['skipped_points']++;
                        continue;
                    }
                    $added++;
                    if (! $dry) {
                        SvodkaPoint::create([
                            'customer_department_id' => $dept->id, 'section_id' => $sec->id, 'name' => $name,
                            'daily' => (bool) preg_match('/^sut[\.\s]/iu', $name), 'is_active' => true, 'sort_order' => ++$psort,
                        ]);
                    }
                }
                $stat['points'] += $added;
                if ($added) {
                    $this->line("      {$added} ta nuqta qo'shiladi");
                }
            }
        });

        $this->newLine();
        $this->info("Yangi bloklar: {$stat['sections']}, yangi nuqtalar: {$stat['points']}, bor nuqtalar (o'tkazildi): {$stat['skipped_points']}");
        if ($missing) {
            $this->warn('Elementlar katalogida topilmadi (ustun qo\'shilmadi): ' . implode(', ', array_keys($missing))
                . " — element qo'shilgach, «Svodka → Nuqtalar → blokni tahrirlash» da ustunni qo'shing.");
        }
        $this->line("Svodka rejimi: «Svodkalar → {$dept->name} → Sozlama» (laboratoriya yoqadi).");

        return self::SUCCESS;
    }

    private function department(string $q): ?CustomerDepartment
    {
        $d = ctype_digit($q) ? CustomerDepartment::find((int) $q) : null;
        $d ??= CustomerDepartment::where('code', $q)->first();
        if (! $d) {
            $list = CustomerDepartment::where('name', 'like', "%$q%")->get();
            if ($list->count() === 1) {
                $d = $list->first();
            } elseif ($list->count() > 1) {
                $this->error("«{$q}» bo'yicha bir nechta bo'lim topildi — kodini yoki id sini yozing:");
            }
        }
        if (! $d) {
            $this->error("Bo'lim topilmadi: {$q}. Mavjud bo'limlar:");
            foreach (CustomerDepartment::orderBy('name')->get(['id', 'code', 'name']) as $x) {
                $this->line("  {$x->id}  " . str_pad((string) $x->code, 8) . " {$x->name}");
            }
        }

        return $d;
    }

    /** @return array<string, int> ko'rsatkich kaliti => element id */
    private function elements(): array
    {
        $all = Element::query()->get(['id', 'symbol']);
        $out = [];
        foreach (self::EL as $key => $variants) {
            foreach ($variants as $v) {
                $e = $all->first(fn ($x) => mb_strtolower(trim($x->symbol)) === mb_strtolower($v));
                if ($e) {
                    $out[$key] = (int) $e->id;
                    break;
                }
            }
        }

        return $out;
    }
}
