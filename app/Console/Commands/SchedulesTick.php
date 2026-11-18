<?php

namespace App\Console\Commands;

use App\Services\Samples\RequestSchedules;
use Illuminate\Console\Command;

/**
 * [Paket 36] Grafik bo'yicha namunalar — har 5 daqiqada (php artisan schedule:run orqali):
 *   bugungi partiyalarni tayyorlash, kechikkanlar haqida laboratoriya va buyurtmachiga xabar,
 *   o'tgan kunlarning kelmagan partiyalarini «kelmadi» deb yopish.
 */
class SchedulesTick extends Command
{
    protected $signature = 'schedules:tick';

    protected $description = "Grafik bo'yicha namunalar: partiyalar, kechikish xabari, kelmaganlar";

    public function handle(RequestSchedules $schedules): int
    {
        $r = $schedules->tick();
        $this->line("Yangi partiya: {$r['created']}, kechikish xabari: {$r['late']}, kelmadi: {$r['missed']}");

        return self::SUCCESS;
    }
}
