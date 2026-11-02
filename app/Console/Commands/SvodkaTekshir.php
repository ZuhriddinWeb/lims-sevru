<?php

namespace App\Console\Commands;

use App\Models\CustomerDepartment;
use App\Models\Sample;
use App\Models\Worksheet;
use App\Models\WorksheetSample;
use App\Models\SvodkaSection;
use App\Services\Svodka\SvodkaService;
use App\Services\Worksheets\WorksheetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 31] Nega natija svodkaga (va probaga) tushmayapti — har bir namuna bo'yicha sabab.
 * Hech narsani o'zgartirmaydi.
 *
 *   php artisan svodka:tekshir TNBGMZ3            — oxirgi 7 kunda ro'yxatga olingan namunalar
 *   php artisan svodka:tekshir TNBGMZ3 --kun=30
 *   php artisan svodka:tekshir TNBGMZ3 --shifr=349 — faqat shu shifrli namuna(lar)
 *
 * Natija probaga FAQAT ishchi varaq TASDIQLANGANDA, shablondagi ELEMENTGA BOG'LANGAN YAKUNIY ustundan yoziladi.
 * Svodka probaga yozilgan natijani oladi va buyurtmachiga laboratoriya boshlig'i chiqargandan keyin ko'rsatadi.
 */
class SvodkaTekshir extends Command
{
    protected $signature = 'svodka:tekshir {bolim : Bo\'lim kodi yoki id} {--kun=7 : Necha kunlik namunalar} {--shifr= : Faqat shu shifr}';

    protected $description = "Natija nega svodkaga tushmayapti — namunalar bo'yicha sabab (hech narsani o'zgartirmaydi)";

    private const ST = ['draft' => 'Yaratildi', 'printed' => 'Chop etilgan', 'in_progress' => "To'ldirilmoqda", 'completed' => 'Yakunlangan (TASDIQLANMAGAN)', 'approved' => 'Tasdiqlangan'];

    public function handle(): int
    {
        $q = (string) $this->argument('bolim');
        $dept = ctype_digit($q) ? CustomerDepartment::find((int) $q) : null;
        $dept ??= CustomerDepartment::where('code', $q)->first();
        if (! $dept) {
            $this->error("Bo'lim topilmadi: {$q}. Mavjud bo'limlar:");
            foreach (CustomerDepartment::orderBy('name')->get(['id', 'code', 'name']) as $x) {
                $this->line("  {$x->id}  " . str_pad((string) $x->code, 10) . " {$x->name}");
            }

            return self::FAILURE;
        }

        $samples = Sample::query()->where('customer_department_id', $dept->id)
            ->when($this->option('shifr'), fn ($w, $c) => $w->where('customer_code', $c))
            ->when(! $this->option('shifr'), fn ($w) => $w->where('registered_at', '>=', now()->subDays(max(1, (int) $this->option('kun')))))
            ->with('sampleElements.element:id,symbol')->orderBy('registered_at')->limit(200)->get();
        $this->info("Bo'lim: {$dept->name} ({$dept->code}) — namunalar: {$samples->count()}");
        if ($samples->isEmpty()) {
            return self::SUCCESS;
        }

        // katalogda bir xil belgili elementlar
        $dupSymbols = DB::table('elements')->select('symbol', DB::raw('count(*) as n'))->groupBy('symbol')->having(DB::raw('count(*)'), '>', 1)->pluck('n', 'symbol');
        if ($dupSymbols->isNotEmpty()) {
            $this->warn('Elementlar katalogida bir xil belgili elementlar bor: ' . $dupSymbols->map(fn ($n, $s) => "{$s} ({$n} ta)")->implode(', '));
            foreach (DB::table('elements')->whereIn('symbol', $dupSymbols->keys())->orderBy('symbol')->get(['id', 'symbol', 'name_uz', 'default_unit']) as $e) {
                $this->line("    id {$e->id}: {$e->symbol} — {$e->name_uz} [{$e->default_unit}]");
            }
        }

        // svodka spravochnigi ustunlari: qaysi elementga bog'langan
        $symMap = SvodkaService::symbolMap();
        $colIds = [];
        $colSym = [];
        if (Schema::hasTable('svodka_sections')) {
            $secs = SvodkaSection::where('customer_department_id', $dept->id)->where('is_active', true)->orderBy('sort_order')->get();
            $this->line('Svodka ustunlari: ' . ($secs->isEmpty() ? '<fg=red>spravochnik bo\'sh</>' : ''));
            foreach ($secs as $sec) {
                $parts = [];
                foreach ((array) $sec->columns as $c) {
                    $id = (int) ($c['element_id'] ?? 0);
                    $colIds[$id] = true;
                    if (isset($symMap[$id])) {
                        $colSym[$symMap[$id]][] = $id;
                    }
                    $parts[] = (($c['label'] ?? null) ?: '?') . " → element id {$id}";
                }
                $this->line("    {$sec->title}: " . ($parts ? implode('; ', $parts) : '—'));
            }
            $enabled = app(SvodkaService::class)->enabled((int) $dept->id);
            if (! $enabled) {
                $this->warn("    Bu bo'lim uchun svodka YOQILMAGAN («Svodkalar» → sozlamalar).");
            }
        }

        $hasLog = Schema::hasTable('activity_logs');
        $stats = ['ok' => 0, 'no_ws' => 0, 'not_approved' => 0, 'no_column' => 0, 'not_pushed' => 0];
        foreach ($samples as $s) {
            $this->newLine();
            $this->line("<options=bold>{$s->code}</>  shifr: " . ($s->customer_code ?: '—') . '  ro\'yxatga olingan: ' . $s->registered_at?->format('d.m.Y H:i'));
            $els = $s->sampleElements;
            $bySymbol = $els->groupBy(fn ($se) => $se->element?->symbol ?? '?');
            foreach ($els as $se) {
                $sym = $se->element?->symbol ?? '?';
                $val = $se->result_value !== null && $se->status === 'done' ? "{$se->result_value} {$se->result_unit}" : '— (natija yo\'q)';
                $this->line("   {$sym} (element id {$se->element_id}): {$val}" . ($bySymbol[$sym]->count() > 1 ? '   <fg=yellow>[probada «' . $sym . '» ' . $bySymbol[$sym]->count() . ' ta]</>' : ''));
            }

            foreach ($els as $se) {
                if ($se->result_value === null || $se->status !== 'done' || ! $colIds || isset($colIds[(int) $se->element_id])) {
                    continue;
                }
                $sy = $symMap[(int) $se->element_id] ?? null;
                if ($sy !== null && isset($colSym[$sy])) {
                    $this->line("   <fg=yellow>Svodka ustuni boshqa «{$se->element?->symbol}» ga bog'langan</> (element id " . implode(', ', array_unique($colSym[$sy]))
                        . "), natija esa id {$se->element_id} da — Paket 31 dan keyin belgi bo'yicha ko'rsatiladi");
                } else {
                    $this->line("   Svodka spravochnigida «" . ($se->element?->symbol ?? '?') . "» ustuni yo'q — svodkada ko'rinmaydi");
                }
            }
            if ($colIds && isset($enabled) && $enabled && $els->contains(fn ($se) => $se->result_value !== null && $se->status === 'done')) {
                try {
                    $g = app(SvodkaService::class)->gate(collect([$s]))[(int) $s->id] ?? null;
                    if ($g && ! $g['released']) {
                        $this->line('   <fg=yellow>Natija bor, lekin svodka hali CHIQARILMAGAN</> — laboratoriya boshlig\'i «Svodkalar → Chiqarish»');
                    } elseif ($g && $els->contains(fn ($se) => $se->result_value !== null && $se->status === 'done' && empty($g['visible'][(int) $se->element_id]))) {
                        $this->line('   <fg=yellow>Chiqarilgandan keyin natija o\'zgargan/qo\'shilgan</> — qayta «Chiqarish» kerak');
                    }
                } catch (\Throwable $e) {
                    // diagnostika — davom etamiz
                }
            }

            $links = WorksheetSample::where('sample_id', $s->id)->get();
            if ($links->isEmpty()) {
                if ($els->contains(fn ($se) => $se->result_value === null)) {
                    $this->line('   <fg=red>SABAB: hech qaysi ishchi varaqqa qo\'shilmagan</> (natija qo\'lda kiritilishi yoki varaqqa qo\'shilishi kerak)');
                    $stats['no_ws']++;
                } else {
                    $stats['ok']++;
                }
                continue;
            }
            foreach ($links as $l) {
                $w = Worksheet::withTrashed()->find($l->worksheet_id);
                if (! $w) {
                    continue;
                }
                $def = $w->definition();
                $summaries = array_values(array_filter($def['columns'] ?? [], fn ($c) => ($c['scope'] ?? 'row') === 'summary'));
                $linked = array_values(array_filter($summaries, fn ($c) => ! empty($c['element_id'])));
                $this->line("   Varaq № {$w->number}  shablon " . ($def['code'] ?? '?') . '  holati: ' . (self::ST[$w->status] ?? $w->status)
                    . ($w->trashed() ? ' (O\'CHIRILGAN)' : '') . ($l->status === 'retest' ? '  [takroriy tahlilga qaytarilgan]' : '')
                    . ((method_exists($w, 'isControl') && $w->isControl()) ? '  [NAZORAT varag\'i — natija probaga yozilmaydi]' : ''));
                $this->line('     yakuniy ustunlar: ' . ($summaries ? implode('; ', array_map(fn ($c) => ($c['label_uz'] ?? $c['key'])
                    . ' → ' . (! empty($c['element_id']) ? (($c['element_symbol'] ?? '?') . " (element id {$c['element_id']})") : '<fg=red>ELEMENTGA BOG\'LANMAGAN</>'), $summaries)) : '<fg=red>YO\'Q</>'));

                if ($w->trashed() || $l->status === 'retest' || ((method_exists($w, 'isControl') && $w->isControl()))) {
                    continue;
                }
                if ($w->status !== 'approved') {
                    $this->line('     <fg=red>SABAB: varaq hali TASDIQLANMAGAN</> — natija probaga «Tasdiqlash» paytida yoziladi');
                    $stats['not_approved']++;
                    continue;
                }
                if (! $linked) {
                    $this->line("     <fg=red>SABAB: shablonda elementga bog'langan yakuniy ustun yo'q</> — «Ishchi varaq shablonlari»da yakuniy ustunga element tanlang (yangi varaqlar uchun); bu varaq natijasini probaga qo'lda kiriting");
                    $stats['no_column']++;
                    continue;
                }
                // tasdiqlangan, bog'langan: probaga yozilganmi
                $missing = [];
                foreach ($linked as $c) {
                    [$se, $why] = WorksheetService::resolveSampleElement((int) $s->id, (int) $c['element_id'], $c['element_symbol'] ?? null);
                    if (! $se) {
                        $missing[] = ($c['element_symbol'] ?? '?') . ": {$why}";
                    } elseif ($se->result_value === null) {
                        $missing[] = ($c['element_symbol'] ?? '?') . ': tasdiqlashda yozilmagan' . ($why ? " (endi: {$why})" : '');
                    }
                }
                if ($hasLog) {
                    $log = DB::table('activity_logs')->where('model_type', 'Worksheet')->where('model_id', $w->id)
                        ->where('description', 'like', 'Ishchi varaq tasdiqlandi%')->orderByDesc('id')->value('properties');
                    $p = $log ? json_decode($log, true) : null;
                    foreach ((array) ($p['skipped'] ?? []) as $line) {
                        // bir nechta probali varaqda qator «PR-...: » bilan boshlanadi — faqat shu probaniki
                        if (str_contains((string) $line, $s->code) || ! str_contains((string) $line, 'PR-')) {
                            $this->line('     tasdiqlashda o\'tkazilmagan: ' . $line);
                        }
                    }
                }
                if ($missing) {
                    $this->line('     <fg=red>SABAB: tasdiqlangan, lekin probaga yozilmagan</> — ' . implode('; ', $missing));
                    $this->line('     Tuzatish: php artisan worksheets:natija-otkaz --varaq=' . preg_replace('/\D.*$/', '', (string) $w->number) . ' --dry-run');
                    $stats['not_pushed']++;
                } else {
                    $this->line('     <fg=green>OK — natija probaga yozilgan</>');
                    $stats['ok']++;
                }
            }
        }

        $this->newLine();
        $this->info("Xulosa: OK {$stats['ok']}; varaqsiz {$stats['no_ws']}; tasdiqlanmagan {$stats['not_approved']}; "
            . "shablonda bog'langan ustun yo'q {$stats['no_column']}; tasdiqlangan, lekin yozilmagan {$stats['not_pushed']}");
        $this->line("Eslatma: probaga yozilgan natija buyurtmachi svodkasida laboratoriya boshlig'i «Svodkalar → Chiqarish» qilgandan keyin ko'rinadi.");

        return self::SUCCESS;
    }
}
