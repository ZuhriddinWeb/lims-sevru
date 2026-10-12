<?php

namespace App\Console\Commands;

use App\Models\InterlabPeer;
use App\Services\Interlab\Outbox;
use App\Services\Interlab\SyncRunner;
use Illuminate\Console\Command;

/**
 * php artisan interlab:sync          — navbatdagi xabarlarni yuborish + aloqani tekshirish
 * php artisan interlab:sync --now    — kutish vaqtlarini bekor qilib, hoziroq urinib ko'rish
 * php artisan interlab:sync --status — faqat holatni ko'rsatish
 */
class InterlabSync extends Command
{
    protected $signature = 'interlab:sync {--now : Kutish vaqtini bekor qilib hoziroq yuborish} {--status : Faqat holatni ko\'rsatish}';

    protected $description = 'Laboratoriyalararo tekshiruv: xabarlarni sinxronlash';

    public function handle(SyncRunner $runner, Outbox $outbox): int
    {
        if (! $this->option('status')) {
            $stats = $runner->run(true, (bool) $this->option('now'));
            if (! $stats['ran']) {
                $this->warn('Bu laboratoriya hali tarmoqqa sozlanmagan (Administratsiya → Laboratoriyalar tarmog\'i).');

                return self::SUCCESS;
            }
            $this->info("Yuborildi: {$stats['sent']}, kutmoqda: {$stats['deferred']}, xato: {$stats['failed']}, tekshirildi: {$stats['pinged']}");
        }

        $counts = $outbox->counts();
        $this->line("Navbatda: {$counts['pending']}, yuborib bo'lmagan: {$counts['failed']}");

        $rows = InterlabPeer::orderBy('name')->get()->map(fn (InterlabPeer $p) => [
            $p->name, $p->base_url, $p->status, $p->online ? 'ha' : 'yo\'q',
            optional($p->last_ok_at)->format('Y-m-d H:i:s') ?? '—', $p->last_error ? mb_substr($p->last_error, 0, 60) : '',
        ]);
        if ($rows->isNotEmpty()) {
            $this->table(['Laboratoriya', 'Manzil', 'Holat', 'Onlayn', 'Oxirgi aloqa', 'Xato'], $rows);
        }

        return self::SUCCESS;
    }
}
