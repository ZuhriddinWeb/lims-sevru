<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Baza holati — noldan o'rnatish skripti ishlab turgan bazaga tegmasligi uchun.
 *
 *   php artisan lims:install-state
 *
 * Chiqish kodi (deploy\server_yangi_ornatish.bat «if errorlevel» bilan o'qiydi):
 *    0  empty     — baza bo'sh: noldan o'rnatish mumkin
 *   10  partial   — jadvallar bor, foydalanuvchi yo'q: avvalgi o'rnatish chala qolgan, davom ettirish mumkin
 *   20  existing  — foydalanuvchilar bor: ISHLAB TURGAN baza — faqat server_yangilash.bat
 *   30  foreign   — migrations jadvali yo'q, lekin boshqa jadvallar bor: boshqa dastur bazasi
 *   40  error     — bazaga ulanib bo'lmadi
 */
class InstallState extends Command
{
    protected $signature = 'lims:install-state';

    protected $description = "Baza holati: bo'sh / chala / ishlab turgan (noldan o'rnatish skripti uchun)";

    public function handle(): int
    {
        try {
            $tables = collect(Schema::getTables())->pluck('name')
                ->reject(fn ($n) => in_array($n, ['sqlite_sequence'], true))->values();
        } catch (Throwable $e) {
            $this->line('error');
            $this->error("Bazaga ulanib bo'lmadi: " . $e->getMessage());

            return 40;
        }

        if (! $tables->contains('migrations')) {
            if ($tables->isEmpty()) {
                $this->line("empty — baza bo'sh");

                return 0;
            }
            $this->line('foreign — migrations jadvali yo\'q, lekin ' . $tables->count() . ' ta boshqa jadval bor: ' . $tables->take(8)->implode(', '));

            return 30;
        }

        $users = $tables->contains('users') ? (int) DB::table('users')->count() : 0;
        if ($users > 0) {
            $samples = $tables->contains('samples') ? (int) DB::table('samples')->count() : 0;
            $this->line("existing — ishlab turgan baza: {$users} foydalanuvchi, {$samples} namuna");

            return 20;
        }

        $this->line("partial — jadvallar bor, foydalanuvchi yo'q (avvalgi o'rnatish chala qolgan)");

        return 10;
    }
}
