<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Worksheet;
use App\Models\WorksheetSample;
use App\Services\Worksheets\WorksheetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * [Paket 31] TASDIQLANGAN ishchi varaqlarning yakuniy natijasini probaga o'tkazish — tasdiqlash paytida
 * yozilmay qolganlari uchun (masalan, katalogda «Au» ikki xil: shablonda biri, probada boshqasi).
 *
 *   php artisan worksheets:natija-otkaz --dry-run          — nima o'tkazilishini ko'rsatadi, hech narsa yozmaydi
 *   php artisan worksheets:natija-otkaz                    — o'tkazadi
 *   php artisan worksheets:natija-otkaz --varaq=9          — faqat shu raqamli varaq(lar) (masalan, «9 k/m» → 9)
 *   php artisan worksheets:natija-otkaz --kun=30           — oxirgi 30 kunda tasdiqlanganlar
 *
 * Xavfsizlik:
 *   - faqat «Tasdiqlangan» varaqlar (nazorat varag'i va «takroriy tahlil» probalari — yo'q);
 *   - probaning elementida natija BO'LSA — tegilmaydi (hech qachon ustidan yozmaydi);
 *   - qiymat varaqda saqlangan hisobdan olinadi; varaq nusxasi va hisob O'ZGARMAYDI;
 *   - har bir o'tkazish faoliyat jurnaliga yoziladi.
 */
class WorksheetPushResults extends Command
{
    protected $signature = 'worksheets:natija-otkaz
        {--dry-run : Faqat ko\'rsatish, yozmaslik}
        {--varaq= : Faqat shu raqamli varaq (raqam qismi, masalan 9)}
        {--kun= : Faqat oxirgi N kunda tasdiqlangan varaqlar}';

    protected $description = "Tasdiqlangan ishchi varaqlar natijasini probaga o'tkazish (yozilmay qolganlari; mavjud natijaga tegmaydi)";

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = Worksheet::query()->where('status', 'approved')->orderBy('approved_at')->orderBy('id');
        $only = trim((string) $this->option('varaq'));
        if ($only !== '') {
            $q->where('number', 'like', "{$only}%");   // aniq tekshiruv quyida: «9» → «9 k/m», «9n», lekin «90» emas
        }
        if ($d = (int) $this->option('kun')) {
            $q->where('approved_at', '>=', now()->subDays($d));
        }

        $pushed = 0;
        $skipped = 0;
        foreach ($q->get() as $w) {
            if ($only !== '' && ! preg_match('/^' . preg_quote($only, '/') . '(\D|$)/u', (string) $w->number)) {
                continue;
            }
            if (method_exists($w, 'isControl') && $w->isControl()) {
                continue;
            }
            $def = $w->definition();
            $cols = array_values(array_filter($def['columns'] ?? [],
                fn ($c) => ($c['scope'] ?? 'row') === 'summary' && ! empty($c['element_id'])));
            if (! $cols) {
                continue;
            }
            $groups = (array) ($w->results['groups'] ?? []);
            $members = WorksheetSample::where('worksheet_id', $w->id)->where('status', '!=', 'retest')->with('sample')->get();
            $lines = [];
            foreach ($members as $m) {
                $sample = $m->sample;
                if (! $sample) {
                    continue;
                }
                $summary = (array) ($groups[(string) $sample->id]['summary'] ?? (count($groups) === 0 ? ($w->results['summary'] ?? []) : []));
                foreach ($cols as $c) {
                    $value = $summary[$c['key']] ?? null;
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $label = ($c['element_symbol'] ?? null) ?: ($c['label_uz'] ?? $c['key']);
                    [$se, $why] = WorksheetService::resolveSampleElement((int) $sample->id, (int) $c['element_id'], $c['element_symbol'] ?? null);
                    if (! $se) {
                        $skipped++;
                        $lines[] = "  - {$sample->code}: {$label} — {$why}";
                        continue;
                    }
                    if ($se->result_value !== null) {
                        continue; // natija bor — tegilmaydi
                    }
                    $pushed++;
                    $lines[] = "  + {$sample->code}: {$label} = {$value}" . (($c['unit'] ?? null) ? " {$c['unit']}" : '') . ($why ? " ({$why})" : '');
                    if ($dry) {
                        continue;
                    }
                    try {
                        DB::transaction(function () use ($se, $w, $value, $c, $sample, $label) {
                            $se->forceFill([
                                'result_value'      => $value,
                                'result_unit'       => ($c['unit'] ?? null) ?: $se->result_unit,
                                'status'            => 'done',
                                'result_entered_by' => $w->approved_by,
                                'result_entered_at' => now(),
                                'method'            => mb_substr(trim('Ishchi varaq № ' . $w->number . ' (' . ($w->definition()['code'] ?? '') . ')'), 0, 191),
                                'notes'             => "Ishchi varaqdan (keyin o'tkazildi): {$w->number}",
                            ])->save();
                            if ($sample->status === 'assigned') {
                                $sample->forceFill(['status' => 'in_progress'])->save();
                            }
                            if (method_exists($sample, 'refreshStatus')) {
                                $sample->unsetRelation('sampleElements');
                                $sample->refreshStatus();
                            }
                            ActivityLog::create([
                                'user_id' => null, 'action' => 'updated', 'model_type' => 'Sample', 'model_id' => $sample->id,
                                'description' => "Natija ishchi varaqdan o'tkazildi: {$sample->code} {$label} = {$value} (varaq № {$w->number}) [worksheets:natija-otkaz]",
                                'properties' => ['worksheet_id' => $w->id, 'element_id' => $se->element_id, 'value' => $value],
                                'created_at' => now(),
                            ]);
                        });
                    } catch (Throwable $e) {
                        report($e);
                        $this->error("  ! {$sample->code}: {$label} — xato: " . $e->getMessage());
                    }
                }
            }
            if ($lines) {
                $this->line("Varaq № {$w->number} (" . ($def['code'] ?? '') . ', tasdiqlangan ' . $w->approved_at?->format('d.m.Y') . '):');
                foreach ($lines as $l) {
                    $this->line($l);
                }
            }
        }

        $this->newLine();
        $this->info(($dry ? "O'tkaziladi" : "O'tkazildi") . ": {$pushed}   O'tkazib bo'lmaydi: {$skipped}" . ($dry ? '   [--dry-run: hech narsa yozilmadi]' : ''));
        if ($skipped) {
            $this->line("«O'tkazib bo'lmaydi» — sababi qatorda. Probada bir xil belgili bir nechta element bo'lsa, natijani qo'lda kiriting.");
        }

        return self::SUCCESS;
    }
}
