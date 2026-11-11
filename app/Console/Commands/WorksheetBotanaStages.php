<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Worksheet;
use App\Models\WorksheetTemplate;
use App\Services\Worksheets\TemplateValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Barcha ishchi varaq shablonlarida: «Qaynatib eritdi» bosqichi ikkiga bo'linadi.
 *
 *   oldin:  … → Qaynatib eritdi → Kupelyatsiya qildi → …
 *   keyin:  … → Eritdi → Kupelyatsiya qildi → Qaynatib eritdi → …
 *   masalan Bo'tana: Shixtovka qildi, Eritdi, Kupelyatsiya qildi, Qaynatib eritdi, O'lchadi (4 → 5)
 *
 *   php artisan worksheets:eritish-stages --dry-run   — nima o'zgarishini ko'rsatadi
 *   php artisan worksheets:eritish-stages             — shablonlar (yangi varaqlar uchun)
 *   php artisan worksheets:eritish-stages --open      — + hali TASDIQLANMAGAN varaqlar ham
 *   (eski nomi worksheets:botana-stages ham ishlaydi)
 *
 * Qoidalar:
 *   - «eritish» kaliti saqlanadi (unga bog'langan ustunlar va mavjud imzolar joyida) — faqat nomi «Eritdi»;
 *   - yangi «qaynatish» bosqichi (Qaynatib eritdi) Kupelyatsiyadan keyin, imzolovchilar soni Kupelyatsiyaniki;
 *   - faqat «eritish» nomi standart («Qaynatib eritdi») bo'lsa — admin o'zgartirgan bo'lsa tegilmaydi;
 *   - TASDIQLANGAN varaqlar hech qachon o'zgartirilmaydi; natijalar va formulalar o'zgarmaydi.
 * Qayta ishga tushirish xavfsiz («qaynatish» bor joyga qayta qo'shilmaydi).
 */
class WorksheetBotanaStages extends Command
{
    protected $signature = 'worksheets:eritish-stages
        {--open : Tasdiqlanmagan mavjud varaqlarga ham}
        {--dry-run : Faqat ko\'rsatish, yozmaslik}';

    protected $aliases = ['worksheets:botana-stages'];

    protected $description = "Bosqichlar: «Qaynatib eritdi» → «Eritdi» + Kupelyatsiyadan keyin «Qaynatib eritdi»";

    public const OLD_LABEL = 'Qaynatib eritdi';
    public const NEW_KEY = 'qaynatish';

    /** Yangi bosqichlar ro'yxati; mos kelmasa — null va sabab */
    public static function transform(array $stages, ?string &$reason = null): ?array
    {
        $stages = array_values($stages);
        $keys = array_map(fn ($s) => $s['key'] ?? null, $stages);
        if (in_array(self::NEW_KEY, $keys, true)) {
            $reason = 'allaqachon bor';

            return null;
        }
        $ei = array_search('eritish', $keys, true);
        $ki = array_search('kupelyatsiya', $keys, true);
        if ($ei === false || $ki === false || $ki < $ei) {
            $reason = 'mos bosqichlar yo\'q (' . implode(', ', array_filter($keys)) . ')';

            return null;
        }
        $label = mb_strtolower(trim((string) ($stages[$ei]['label_uz'] ?? '')));
        if ($label !== mb_strtolower(self::OLD_LABEL)) {
            $reason = "«eritish» nomi o'zgartirilgan («{$stages[$ei]['label_uz']}») — muharrirda qo'lda";

            return null;
        }

        $stages[$ei]['label_uz'] = 'Eritdi';
        $stages[$ei]['label_uz_cyrl'] = 'Эритди';
        if (empty($stages[$ei]['label_ru'])) {
            $stages[$ei]['label_ru'] = 'Плавка';
        }
        array_splice($stages, $ki + 1, 0, [[
            'key'           => self::NEW_KEY,
            'label_uz'      => 'Qaynatib eritdi',
            'label_ru'      => 'Разварка',
            'label_uz_cyrl' => 'Қайнатиб эритди',
            'signers'       => max(0, (int) ($stages[$ki]['signers'] ?? 1)),
        ]]);

        return $stages;
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $open = (bool) $this->option('open');
        $tplChanged = 0;

        foreach (WorksheetTemplate::with('columns')->orderBy('code')->get() as $tpl) {
            $reason = null;
            $old = $tpl->stages ?? [];
            $new = self::transform($old, $reason);
            if ($new === null) {
                $this->line(($reason === 'allaqachon bor' ? '  = ' : '  - ') . "{$tpl->code} {$tpl->name_uz}: {$reason}");
                continue;
            }
            $def = $tpl->toDefinition();
            $def['stages'] = $new;
            if ($errors = TemplateValidator::validate($def)) {
                $this->warn("  ! {$tpl->code}: tekshiruvdan o'tmadi — " . $errors[0]['message']);
                continue;
            }
            $this->info("  + {$tpl->code} {$tpl->name_uz}: " . count($old) . ' → ' . count($new) . ' bosqich');
            $tplChanged++;
            if (! $dry) {
                $tpl->stages = $new;
                $tpl->save();
                $this->log('WorksheetTemplate', (int) $tpl->id, "Shablon {$tpl->code}: bosqichlar " . count($old) . ' → ' . count($new) . ' (Eritdi, Qaynatib eritdi)');
            }
        }

        $sheetsChanged = 0;
        $eligible = Worksheet::where('status', '!=', 'approved')->orderBy('id')->get()
            ->filter(fn (Worksheet $w) => self::transform($w->definition()['stages'] ?? []) !== null);
        if (! $open) {
            $this->line('');
            $this->line("Tasdiqlanmagan varaqlardan eski bosqichlilari: {$eligible->count()} ta. Ularga ham: --open");
        } else {
            foreach ($eligible as $w) {
                $sheetsChanged++;
                if ($dry) {
                    continue;
                }
                $snap = $w->definition();
                $snap['stages'] = self::transform($snap['stages']);
                DB::table('worksheets')->where('id', $w->id)->update([
                    'template_snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE),
                    'updated_at'        => now(),
                ]);
            }
            if ($sheetsChanged && ! $dry) {
                $this->log('Worksheet', null, "Bosqichlar (Eritdi, Qaynatib eritdi): {$sheetsChanged} ta tasdiqlanmagan varaq");
            }
            $this->line('');
            $this->line("Varaqlar: {$sheetsChanged} ta" . ($dry ? " o'zgaradi" : ' yangilandi'));
        }
        $this->line('Tasdiqlangan varaqlar (' . Worksheet::where('status', 'approved')->count() . " ta) o'zgartirilmadi.");
        $this->info(($dry ? '[sinov] ' : '') . "Shablonlar: {$tplChanged} ta.");

        return self::SUCCESS;
    }

    private function log(string $type, ?int $id, string $text): void
    {
        try {
            ActivityLog::create([
                'user_id' => null, 'action' => 'updated', 'model_type' => $type, 'model_id' => $id,
                'description' => $text . ' [worksheets:eritish-stages]', 'properties' => null,
                'ip_address' => null, 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
