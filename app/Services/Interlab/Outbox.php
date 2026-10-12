<?php

namespace App\Services\Interlab;

use App\Models\InterlabMessage;
use App\Models\InterlabPeer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chiquvchi xabarlar navbati.
 *
 *  - Xabar avval bazaga yoziladi (o'zgarish bilan bitta tranzaksiyada) — server o'chib qolsa ham yo'qolmaydi.
 *  - Har bir laboratoriyaga xabarlar QAT'IY TARTIBDA boradi (oldingisi yetmaguncha keyingisi kutadi).
 *  - Aloqa yo'q bo'lsa: 30 s, 1, 2, 5, 10, 30 daqiqa, keyin har soat qayta urinish — cheksiz.
 *  - Qabul qiluvchi xabarni uuid bo'yicha bir marta qayta ishlaydi, shuning uchun takroriy yuborish xavfsiz.
 */
class Outbox
{
    private const BACKOFF = [30, 60, 120, 300, 600, 1800, 3600];
    private const STUCK_AFTER_SECONDS = 300;

    public function __construct(
        private Identity $identity,
        private Transport $transport,
    ) {
    }

    public function queue(InterlabPeer $peer, string $type, array $body, ?string $referralUuid = null): InterlabMessage
    {
        $me = $this->identity->require();
        $envelope = Envelope::build($me, $peer->node_id, $peer->enc_public, $type, $body);

        return InterlabMessage::create([
            'uuid'            => $envelope['id'],
            'peer_id'         => $peer->id,
            'peer_node_id'    => $peer->node_id,
            'direction'       => 'out',
            'type'            => $type,
            'referral_uuid'   => $referralUuid,
            'body'            => $body,
            'envelope'        => json_encode($envelope, JSON_UNESCAPED_SLASHES),
            'status'          => 'pending',
            'attempts'        => 0,
            'next_attempt_at' => now(),
        ]);
    }

    /**
     * Navbatdagi xabarlarni yuboradi.
     *
     * @param  bool  $kick  foydalanuvchi amali: kutish vaqtini bekor qilib, hozir urinib ko'radi
     * @return array{sent: int, failed: int, deferred: int}
     */
    public function flush(?InterlabPeer $only = null, bool $kick = false, ?int $limit = null): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'deferred' => 0];
        $limit ??= max(1, (int) config('interlab.batch', 50));

        $this->recoverStuck();

        $peerIds = InterlabMessage::query()
            ->where('direction', 'out')->where('status', 'pending')
            ->when($only, fn ($q) => $q->where('peer_id', $only->id))
            ->distinct()->pluck('peer_id')->map(fn ($id) => (int) $id)->all();

        foreach ($peerIds as $peerId) {
            if ($limit <= 0) {
                break;
            }
            $peer = InterlabPeer::find($peerId);
            if (! $peer) {
                continue;
            }
            if ($kick) {
                InterlabMessage::where('direction', 'out')->where('status', 'pending')
                    ->where('peer_id', $peerId)->update(['next_attempt_at' => now()]);
            }

            $this->withLock("interlab-flush-{$peerId}", function () use ($peer, &$stats, &$limit) {
                $this->flushPeer($peer, $stats, $limit);
            });
        }

        return $stats;
    }

    private function flushPeer(InterlabPeer $peer, array &$stats, int &$limit): void
    {
        $messages = InterlabMessage::query()
            ->where('direction', 'out')->where('status', 'pending')->where('peer_id', $peer->id)
            ->orderBy('id')->limit($limit)->get();

        foreach ($messages as $message) {
            // Tartib: eski xabar kutish vaqtida bo'lsa — keyingilari ham kutadi
            if ($message->next_attempt_at && $message->next_attempt_at->isFuture()) {
                $stats['deferred'] += 1;
                return;
            }

            $block = $this->blockReason($peer, $message->type);
            if ($block === 'wait') {
                $stats['deferred'] += 1;
                return;
            }
            if ($block !== null) {
                $message->forceFill(['status' => 'failed', 'last_error' => $block])->save();
                $stats['failed'] += 1;
                continue;
            }

            // Boshqa jarayon olib qo'ymaganini kafolatlash
            $claimed = InterlabMessage::where('id', $message->id)->where('status', 'pending')
                ->update(['status' => 'sending', 'updated_at' => now()]);
            if (! $claimed) {
                return;
            }
            // Model xotirasini bazaga moslash — aks holda «pending» ga qaytarish «o'zgarish yo'q» deb saqlanmaydi
            $message->forceFill(['status' => 'sending'])->syncOriginalAttribute('status');

            $limit -= 1;
            $result = $this->transport->send($peer, json_decode($message->envelope, true));
            $attempts = $message->attempts + 1;

            if ($result['ok']) {
                $message->forceFill([
                    'status' => 'sent', 'sent_at' => now(), 'attempts' => $attempts,
                    'next_attempt_at' => null, 'last_error' => null,
                ])->save();
                $peer->forceFill(['last_ok_at' => now(), 'last_error' => null, 'last_error_at' => null])->save();
                $stats['sent'] += 1;
                $this->afterDelivered($message);
                continue;
            }

            $peer->forceFill(['last_error' => $result['error'], 'last_error_at' => now()])->save();

            if ($result['retryable']) {
                $message->forceFill([
                    'status' => 'pending', 'attempts' => $attempts, 'last_error' => $result['error'],
                    'next_attempt_at' => now()->addSeconds($this->backoff($attempts)),
                ])->save();
                $stats['deferred'] += 1;
                return; // tartibni saqlash uchun bu laboratoriyaga keyingilarini yubormaymiz
            }

            $message->forceFill([
                'status' => 'failed', 'attempts' => $attempts, 'last_error' => $result['error'],
                'next_attempt_at' => null,
            ])->save();
            $stats['failed'] += 1;
        }
    }

    /** null — yuborish mumkin; 'wait' — keyinroq; boshqa matn — yuborib bo'lmaydi (sabab) */
    private function blockReason(InterlabPeer $peer, string $type): ?string
    {
        return match ($peer->status) {
            'active'      => null,
            'pending_out' => $type === 'pair.request' ? null : 'wait',
            'disabled'    => 'wait',
            'rejected'    => $type === 'pair.reject' ? null : 'Laboratoriya ulanishi rad etilgan',
            'pending_in'  => $type === 'pair.reject' ? null : 'wait',
            default       => 'wait',
        };
    }

    /** Muvaffaqiyatsiz (failed) yoki kutayotgan xabarni hoziroq qayta yuborishga qo'yadi */
    public function retry(InterlabMessage $message): void
    {
        if ($message->direction !== 'out' || ! in_array($message->status, ['failed', 'pending'], true)) {
            throw new InterlabException('Bu xabarni qayta yuborib bo\'lmaydi');
        }
        $message->forceFill(['status' => 'pending', 'next_attempt_at' => now()])->save();
    }

    /** Jarayon yarmida uzilib «sending» da qolgan xabarlarni qaytaradi */
    public function recoverStuck(): int
    {
        return InterlabMessage::where('direction', 'out')->where('status', 'sending')
            ->where('updated_at', '<', now()->subSeconds(self::STUCK_AFTER_SECONDS))
            ->update(['status' => 'pending', 'next_attempt_at' => now()]);
    }

    private function backoff(int $attempts): int
    {
        return self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1];
    }

    private function afterDelivered(InterlabMessage $message): void
    {
        try {
            if (str_starts_with($message->type, 'referral.')) {
                app(ReferralService::class)->onDelivered($message);
            }
        } catch (Throwable $e) {
            Log::warning('interlab: afterDelivered', ['id' => $message->uuid, 'error' => $e->getMessage()]);
        }
    }

    private function withLock(string $name, callable $callback): void
    {
        try {
            $lock = Cache::lock($name, 120);
        } catch (Throwable) {
            $callback();
            return;
        }

        if (! $lock->get()) {
            return; // boshqa jarayon hozir yuboryapti
        }
        try {
            $callback();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // e'tiborsiz
            }
        }
    }

    /** Statistika — UI va buyruq uchun */
    public function counts(): array
    {
        $rows = InterlabMessage::query()->where('direction', 'out')
            ->whereIn('status', ['pending', 'sending', 'failed'])
            ->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        return [
            'pending' => (int) ($rows['pending'] ?? 0) + (int) ($rows['sending'] ?? 0),
            'failed'  => (int) ($rows['failed'] ?? 0),
        ];
    }
}
