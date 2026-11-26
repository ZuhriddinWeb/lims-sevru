<?php

namespace App\Console\Commands;

use App\Services\Central\CentralLink;
use Illuminate\Console\Command;

/**
 * [Paket 44] Markaziy portalga ruxsat berilgan sonlarni yuborish.
 * Jadval har 5 daqiqada chaqiradi; sozlamadagi oraliq (5–60 daqiqa) o'tmagan bo'lsa — hech narsa qilmaydi.
 *   php artisan central:push           — vaqti kelgan bo'lsa yuboradi
 *   php artisan central:push --force   — hozir yuboradi (sinash uchun)
 *   php artisan central:push --show    — yuboriladigan ma'lumotni ko'rsatadi, yubormaydi
 */
class CentralPush extends Command
{
    protected $signature = 'central:push {--force : Oraliqni kutmasdan hozir yuborish} {--show : Faqat ko\'rsatish, yubormaslik}';

    protected $description = "Markaziy portalga (НГМК LIMS) ruxsat berilgan jamlangan sonlarni yuborish";

    public function handle(CentralLink $link): int
    {
        if ($this->option('show')) {
            $this->line(json_encode($link->snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $s = $link->settings();
        if (! $this->option('force')) {
            if (! $link->due()) {
                return self::SUCCESS; // o'chiq yoki vaqti kelmagan — jim
            }
        } elseif (! $s['url'] || ! $link->key()) {
            $this->error("Markaz manzili yoki kalit kiritilmagan (Administratsiya → Markaziy portal)");

            return self::FAILURE;
        }

        $st = $link->push();
        if ($st['ok']) {
            $this->info("Yuborildi: {$st['bytes']} bayt, {$st['ms']} ms");

            return self::SUCCESS;
        }
        $this->error('Yuborilmadi: ' . ($st['http'] ? "HTTP {$st['http']} — " : '') . $st['error']);

        return self::FAILURE;
    }
}
