<?php

namespace App\Console\Commands;

use App\Services\Samples\SampleRequests;
use Illuminate\Console\Command;

class SampleRequestsExpire extends Command
{
    protected $signature = 'sample-requests:expire';

    protected $description = "Muddatida olib kelinmagan buyurtma so'rovlarini «muddati o'tgan» deb belgilaydi";

    public function handle(SampleRequests $requests): int
    {
        $n = $requests->expireOverdue();
        $this->info("Muddati o'tgan so'rovlar: {$n}");

        return self::SUCCESS;
    }
}
