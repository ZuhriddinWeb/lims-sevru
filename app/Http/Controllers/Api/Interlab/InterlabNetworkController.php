<?php

namespace App\Http\Controllers\Api\Interlab;

use App\Http\Controllers\Controller;
use App\Models\InterlabInvite;
use App\Models\InterlabMessage;
use App\Models\InterlabPeer;
use App\Models\InterlabReferral;
use App\Services\Interlab\Crypto;
use App\Services\Interlab\Identity;
use App\Services\Interlab\Outbox;
use App\Services\Interlab\PeerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administratsiya → Laboratoriyalar tarmog'i: shu laboratoriya sozlamasi va ulanishlar.
 */
class InterlabNetworkController extends Controller
{
    use HandlesInterlabErrors;

    public function __construct(
        private Identity $identity,
        private PeerService $peers,
        private Outbox $outbox,
    ) {
    }

    public function show(Request $request)
    {
        $me = $this->identity->get();

        $pending = InterlabMessage::where('direction', 'out')->whereIn('status', ['pending', 'sending'])
            ->select('peer_id', DB::raw('count(*) as c'))->groupBy('peer_id')->pluck('c', 'peer_id');
        $failed = InterlabMessage::where('direction', 'out')->where('status', 'failed')
            ->select('peer_id', DB::raw('count(*) as c'))->groupBy('peer_id')->pluck('c', 'peer_id');
        $referrals = InterlabReferral::select('peer_id', DB::raw('count(*) as c'))->groupBy('peer_id')->pluck('c', 'peer_id');

        $order = ['pending_in' => 0, 'active' => 1, 'pending_out' => 2, 'disabled' => 3, 'rejected' => 4];

        return response()->json([
            'identity' => $me ? [
                'node_id'           => $me->node_id,
                'name'              => $me->name,
                'code'              => $me->code,
                'base_url'          => $me->base_url,
                'fingerprint'       => $me->fingerprint,
                'fingerprint_short' => Crypto::formatFingerprint($me->fingerprint),
                'last_sync_at'      => $me->last_sync_at,
                'created_at'        => $me->created_at,
            ] : null,
            'suggested_url' => rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/'),
            'peers' => InterlabPeer::get()
                ->sortBy(fn (InterlabPeer $p) => [($order[$p->status] ?? 9), mb_strtolower($p->name)])
                ->values()
                ->map(fn (InterlabPeer $p) => $p->toPublicArray() + [
                    'fingerprint_short' => Crypto::formatFingerprint($p->fingerprint),
                    'pending_messages'  => (int) ($pending[$p->id] ?? 0),
                    'failed_messages'   => (int) ($failed[$p->id] ?? 0),
                    'referrals'         => (int) ($referrals[$p->id] ?? 0),
                ]),
            'invites' => $me ? $this->peers->invites() : [],
            'invite_hours' => PeerService::INVITE_HOURS,
            'queue' => $this->outbox->counts(),
            'openssl' => extension_loaded('openssl'),
        ]);
    }

    public function createIdentity(Request $request)
    {
        $data = $this->validateIdentity($request);

        return $this->attempt(function () use ($request, $data) {
            $me = $this->identity->create($data['name'], $data['code'], $data['base_url'] ?? null);
            app(\App\Services\Interlab\Notifier::class)->audit('interlab.identity_create',
                "Laboratoriya tarmoqqa sozlandi: {$me->name}", ['fingerprint' => $me->fingerprint]);

            return $this->show($request);
        });
    }

    public function updateIdentity(Request $request)
    {
        $data = $this->validateIdentity($request);

        return $this->attempt(function () use ($request, $data) {
            $changed = $this->identity->update($data['name'], $data['code'], $data['base_url'] ?? null);
            if ($changed) {
                $this->peers->broadcastNodeUpdate();
            }

            return $this->show($request);
        });
    }

    /** Ulanish kodi yaratish (kod faqat shu javobda bir marta qaytadi) */
    public function createInvite(Request $request)
    {
        $data = $request->validate([
            'hours' => ['required', 'integer', 'in:' . implode(',', PeerService::INVITE_HOURS)],
            'note'  => ['nullable', 'string', 'max:191'],
        ], [], ['hours' => 'muddat', 'note' => 'izoh']);

        return $this->attempt(function () use ($request, $data) {
            $result = $this->peers->createInvite($request->user(), (int) $data['hours'], $data['note'] ?? null);

            return response()->json([
                'code'   => $result['code'],
                'invite' => $result['invite']->toPublicArray(),
            ], 201);
        });
    }

    public function revokeInvite(Request $request, InterlabInvite $invite)
    {
        return $this->attempt(function () use ($request, $invite) {
            $this->peers->revokeInvite($invite);

            return $this->show($request);
        });
    }

    /** Boshqa laboratoriyaga uning ulanish kodi bilan ulanish */
    public function connect(Request $request)
    {
        $data = $request->validate([
            'url'  => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
        ], [], ['url' => 'manzil', 'code' => 'ulanish kodi']);

        return $this->attempt(function () use ($request, $data) {
            $peer = $this->peers->connectWithCode($data['url'], $data['code'], $request->user());

            return $this->show($request)->getData(true) + ['connected' => ['id' => $peer->id, 'name' => $peer->name]];
        });
    }

    public function reject(Request $request, InterlabPeer $peer)
    {
        return $this->attempt(function () use ($request, $peer) {
            $this->peers->reject($peer, $request->user());

            return $this->show($request);
        });
    }

    public function ping(Request $request, InterlabPeer $peer)
    {
        return $this->attempt(function () use ($request, $peer) {
            $this->peers->ping($peer);
            $this->outbox->flush($peer, true);

            return $this->show($request);
        });
    }

    public function disable(Request $request, InterlabPeer $peer)
    {
        return $this->attempt(function () use ($request, $peer) {
            $this->peers->setEnabled($peer, false);

            return $this->show($request);
        });
    }

    public function enable(Request $request, InterlabPeer $peer)
    {
        return $this->attempt(function () use ($request, $peer) {
            $this->peers->setEnabled($peer, true);

            return $this->show($request);
        });
    }

    public function destroy(Request $request, InterlabPeer $peer)
    {
        return $this->attempt(function () use ($request, $peer) {
            $this->peers->delete($peer);

            return $this->show($request);
        });
    }

    private function validateIdentity(Request $request): array
    {
        return $request->validate([
            'name'     => ['required', 'string', 'max:191'],
            'code'     => ['required', 'string', 'max:20'],
            'base_url' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'nomi', 'code' => 'qisqa kod', 'base_url' => 'manzil']);
    }
}
