<?php

namespace App\Console\Commands;

use App\Services\Samples\SampleRequests;
use Illuminate\Console\Command;

class SampleRequestsExpire extends Command
{
    protected $signature = 'sample-requests:expire {--remind : [P39] yopilmagan buyurtmalar haqida kunlik eslatma}';

    protected $description = "Muddatida olib kelinmagan buyurtmalarni «muddati o'tgan» deb belgilaydi; [P39] N kundan keyin avtomatik yopadi";

    public function handle(SampleRequests $requests): int
    {
        if ($this->option('remind')) {
            $this->info('Eslatmalar: ' . $requests->remindUnclosed());

            return self::SUCCESS;
        }
        $n = $requests->expireOverdue();
        $this->info("Muddati o'tgan so'rovlar: {$n}");
        $this->info('Avtomatik yopilgan: ' . $requests->autoCloseExpired());

        return self::SUCCESS;
    }
}
