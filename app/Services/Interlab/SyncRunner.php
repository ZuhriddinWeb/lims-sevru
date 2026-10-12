<?php

namespace App\Services\Interlab;

use App\Models\InterlabPeer;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Bitta sinxronlash aylanishi:
 *   1. Navbatdagi xabarlarni yuborish (tartib bilan, qayta urinish bilan)
 *   2. Laboratoriyalar bilan aloqani tekshirish (har 2 daqiqada)
 *
 * Chaqiriladi: Windows Task Scheduler (php artisan schedule:run → interlab:sync, har daqiqa),
 * modul sahifasi ochiq turganda (har 20 soniyada) va «Sinxronlash» tugmasi bilan.
 */
class SyncRunner
{
    public function __construct(
        private Identity $identity,
        private Outbox $outbox,
        private PeerService $peers,
    ) {
    }

    /** @return array{ran: bool, sent: int, failed: int, deferred: int, pinged: int} */
    public function run(bool $ping = true, bool $kick = false): array
    {
        $stats = ['ran' => false, 'sent' => 0, 'failed' => 0, 'deferred' => 0, 'pinged' => 0];
        $me = $this->identity->get();
        if (! $me) {
            return $stats;
        }

        $stats = array_merge($stats, $this->outbox->flush(null, $kick), ['ran' => true]);

        if ($ping) {
            $stats['pinged'] = $this->pingDue();
        }

        $me->forceFill(['last_sync_at' => now()])->save();
        try {
            Cache::put('interlab:last_sync_at', now()->toIso8601String(), 86400);
        } catch (Throwable) {
            // kesh ixtiyoriy
        }

        return $stats;
    }

    private function pingDue(): int
    {
        $interval = max(30, (int) config('interlab.ping_interval', 120));
        $count = 0;
        $peers = InterlabPeer::whereIn('status', ['active', 'pending_out'])
            ->where(function ($q) use ($interval) {
                $q->whereNull('last_ping_at')->orWhere('last_ping_at', '<', now()->subSeconds($interval));
            })->get();

        foreach ($peers as $peer) {
            // Yaqinda xabar almashgan bo'lsa — alohida tekshirish shart emas
            if ($peer->last_ok_at && $peer->last_ok_at->gt(now()->subSeconds($interval))
                && (! $peer->last_error_at || $peer->last_error_at->lt($peer->last_ok_at))) {
                continue;
            }
            $this->peers->ping($peer);
            $count++;
        }

        return $count;
    }
}
