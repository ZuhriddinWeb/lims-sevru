<?php

namespace App\Console\Commands;

use App\Models\HelpEntry;
use App\Services\Help\HelpAssistant;
use Illuminate\Console\Command;

/**
 * php artisan help:import [fayl] [--force]
 * Savol-javoblarni JSON fayldan bazaga yuklaydi (slug bo'yicha: bor bo'lsa yangilaydi, yo'q bo'lsa qo'shadi).
 * Administrator tahrirlagan (is_edited) yozuvlar --force bo'lmasa o'zgartirilmaydi.
 * Faylda yo'q, lekin bazadagi tayyor (is_custom=0) yozuvlar o'chirilmaydi, faqat hisobotda ko'rsatiladi.
 */
class HelpImport extends Command
{
    protected $signature = 'help:import {path? : JSON fayl (standart: database/help/help_qa.json)} {--force : tahrirlangan yozuvlar ustidan ham yozish}';

    protected $description = 'Yordamchi savol-javoblarini JSON fayldan bazaga yuklash';

    public function handle(HelpAssistant $help): int
    {
        $path = $this->argument('path') ?: base_path('database/help/help_qa.json');
        if (! is_file($path)) {
            $this->error("Fayl topilmadi: {$path}");

            return self::FAILURE;
        }
        $items = json_decode((string) file_get_contents($path), true);
        if (! is_array($items) || $items === []) {
            $this->error('JSON o‘qib bo‘lmadi yoki bo‘sh.');

            return self::FAILURE;
        }

        $added = $updated = $skipped = $same = 0;
        $slugs = [];
        foreach ($items as $i => $it) {
            foreach (['id', 'uz', 'ru', 'en'] as $k) {
                if (empty($it[$k])) {
                    $this->error('Yozuv #'.($i + 1)." — «{$k}» yo‘q, to‘xtadi.");

                    return self::FAILURE;
                }
            }
            $slug = (string) $it['id'];
            $slugs[] = $slug;
            $row = HelpEntry::where('slug', $slug)->first();
            if ($row && $row->is_edited && ! $this->option('force')) {
                $skipped++;

                continue;
            }
            $data = [
                'section' => $it['section'] ?? null,
                'route' => $it['route'] ?? null,
                'permission' => $this->perms($it['permission'] ?? null),
                'type' => $it['type'] ?? 'howto',
                'is_top' => (bool) ($it['top'] ?? false),
                'is_page' => (bool) ($it['page'] ?? false),
                'keywords' => array_values($it['keywords'] ?? []),
                'sort_order' => $i + 1,
                'is_active' => true,
            ];
            foreach (HelpAssistant::LOCALES as $l) {
                $data['q_'.$l] = $it[$l]['q'];
                $data['alt_'.$l] = array_values($it[$l]['alt'] ?? []);
                $data['a_'.$l] = str_replace('\\n', "\n", $it[$l]['a']);
            }
            if ($row) {
                $row->fill($data);
                if ($row->isDirty()) {   // mazmun o'zgarmagan bo'lsa yozilmaydi (updated_at ham)
                    $row->save();
                    $updated++;
                } else {
                    $same++;
                }
            } else {
                HelpEntry::create($data + ['slug' => $slug, 'is_custom' => false, 'is_edited' => false]);
                $added++;
            }
        }

        $help->forget();
        $this->info("Tayyor: qo‘shildi {$added}, yangilandi {$updated}, o‘zgarmagan {$same}, o‘tkazib yuborildi (tahrirlangan) {$skipped}.");

        $extra = HelpEntry::where('is_custom', false)->whereNotIn('slug', $slugs)->pluck('slug');
        if ($extra->isNotEmpty()) {
            $this->warn('Faylda yo‘q, lekin bazada bor (o‘chirilmadi): '.$extra->implode(', '));
        }

        return self::SUCCESS;
    }

    /** JSON da ruxsat ro'yxat (["samples.view"]) yoki matn bo'lishi mumkin; bazada vergul bilan saqlanadi (ro'yxatdagilardan biri yetarli) */
    private function perms(mixed $v): ?string
    {
        $list = is_array($v) ? $v : preg_split('/[,\s]+/', (string) $v);
        $list = array_values(array_filter(array_map('trim', $list)));

        return $list ? implode(',', $list) : null;
    }
}
