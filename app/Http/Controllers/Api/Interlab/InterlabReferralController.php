<?php

namespace App\Http\Controllers\Api\Interlab;

use App\Http\Controllers\Controller;
use App\Models\InterlabMessage;
use App\Models\InterlabPeer;
use App\Models\InterlabReferral;
use App\Models\LaboratoryGroup;
use App\Models\Sample;
use App\Services\Interlab\Identity;
use App\Services\Interlab\InterlabException;
use App\Services\Interlab\Outbox;
use App\Services\Interlab\ReferralService;
use App\Services\Interlab\SyncRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class InterlabReferralController extends Controller
{
    use HandlesInterlabErrors;

    public function __construct(private ReferralService $service)
    {
    }

    /** Menyu nishonlari va sahifa sarlavhasi uchun */
    public function summary(Request $request, Identity $identity, Outbox $outbox)
    {
        $user = $request->user();
        $visible = fn () => $this->service->scopeVisible(InterlabReferral::query(), $user);
        $me = $identity->get();
        $can = fn (string $p) => $user->isAdmin() || $user->hasPermission($p);

        return response()->json([
            'configured'     => (bool) $me,
            'lab'            => $me ? ['name' => $me->name, 'code' => $me->code] : null,
            'last_sync_at'   => $me?->last_sync_at,
            'in_new'         => $visible()->where('direction', 'in')->where('status', 'sent')->count(),
            'in_work'        => $visible()->where('direction', 'in')->where('status', 'accepted')->count(),
            'out_open'       => $visible()->where('direction', 'out')->whereIn('status', ['sending', 'sent', 'accepted'])->count(),
            'out_unreviewed' => $visible()->where('direction', 'out')->where('status', 'completed')->whereNull('reviewed_at')->count(),
            'queue'          => $outbox->counts(),
            'peers_active'   => InterlabPeer::where('status', 'active')->count(),
            'peers_waiting'  => $can('interlab.manage') ? InterlabPeer::where('status', 'pending_in')->count() : 0,
            'can'            => [
                'send'    => $can('interlab.send'),
                'process' => $can('interlab.process'),
                'manage'  => $can('interlab.manage'),
            ],
        ]);
    }

    /**
     * Sahifa ochiq turganda har 20 soniyada chaqiriladi (Task Scheduler sozlanmagan bo'lsa ham
     * xabarlar yuboriladi). Ko'p oyna ochiq bo'lsa — 15 soniyada bir martadan ko'p ishlamaydi.
     */
    public function sync(Request $request, SyncRunner $runner, Identity $identity, Outbox $outbox)
    {
        $now = $request->boolean('now');
        $stats = null;
        $shouldRun = $now;
        if (! $shouldRun) {
            try {
                $shouldRun = Cache::add('interlab:ui-sync', 1, 15);
            } catch (Throwable) {
                $shouldRun = true;
            }
        }
        if ($shouldRun) {
            $stats = $runner->run(true, $now);
        }

        return response()->json([
            'ran'          => (bool) $stats,
            'stats'        => $stats,
            'queue'        => $outbox->counts(),
            'last_sync_at' => $identity->get()?->fresh()?->last_sync_at,
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'direction' => ['nullable', 'in:in,out'],
            'status'    => ['nullable', 'string', 'max:20'],
            'peer_id'   => ['nullable', 'integer'],
            'search'    => ['nullable', 'string', 'max:100'],
            'open_only' => ['nullable', 'boolean'],
            'per_page'  => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $user = $request->user();

        $query = $this->service->scopeVisible(InterlabReferral::query(), $user)
            ->with('peer', 'group')
            ->when($data['direction'] ?? null, fn ($q, $d) => $q->where('direction', $d))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['peer_id'] ?? null, fn ($q, $p) => $q->where('peer_id', $p))
            ->when(! empty($data['open_only']), fn ($q) => $q->whereIn('status', InterlabReferral::OPEN))
            ->when($data['search'] ?? null, function ($q, $s) {
                $like = '%' . $s . '%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)
                    ->orWhere('sample_code', 'like', $like)
                    ->orWhere('sample_number', 'like', $like));
            })
            ->orderByDesc('last_event_at')->orderByDesc('id');

        $page = $query->paginate($data['per_page'] ?? 20);
        $page->getCollection()->transform(fn (InterlabReferral $r) => $this->service->present($r, $user));

        return response()->json($page);
    }

    public function show(Request $request, InterlabReferral $referral)
    {
        return $this->attempt(function () use ($request, $referral) {
            $this->service->assertVisible($request->user(), $referral);

            return $this->service->present($referral, $request->user(), true);
        });
    }

    public function activePeers()
    {
        return response()->json(
            InterlabPeer::where('status', 'active')->orderBy('name')->get()
                ->map(fn (InterlabPeer $p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'online' => $p->online])
        );
    }

    /** Jo'natish uchun proba qidirish (o'z probalarimiz) */
    public function samples(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $samples = Sample::query()
            ->with(['sampleElements.element'])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('sample_number', 'like', $like));
            })
            ->whereNotIn('status', ['archived'])
            ->orderByDesc('id')->limit(20)->get();

        $open = InterlabReferral::where('direction', 'out')->whereIn('status', InterlabReferral::OPEN)
            ->whereIn('sample_id', $samples->pluck('id'))
            ->select('sample_id', DB::raw('count(*) as c'))->groupBy('sample_id')->pluck('c', 'sample_id');

        return response()->json($samples->map(fn (Sample $s) => [
            'id'             => $s->id,
            'code'           => $s->code,
            'sample_number'  => $s->sample_number,
            'status'         => $s->status,
            'registered_at'  => $s->registered_at,
            'open_referrals' => (int) ($open[$s->id] ?? 0),
            'elements'       => $s->sampleElements->filter(fn ($se) => $se->element)->map(fn ($se) => [
                'symbol'       => $se->element->symbol,
                'name_uz'      => $se->element->name_uz,
                'name_ru'      => $se->element->name_ru,
                'name_en'      => $se->element->name_en,
                'unit'         => $se->result_unit ?: $se->element->default_unit,
                'result_value' => $se->result_value !== null ? (float) $se->result_value : null,
            ])->values(),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'peer_id'       => ['required', 'integer'],
            'sample_id'     => ['required', 'integer'],
            'elements'      => ['required', 'array', 'min:1', 'max:60'],
            'elements.*'    => ['string', 'max:16'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'note'          => ['nullable', 'string', 'max:2000'],
            'deadline'      => ['nullable', 'date_format:Y-m-d'],
            'tolerance_pct' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'hide_code'     => ['nullable', 'boolean'],
        ], [], ['elements' => 'elementlar', 'peer_id' => 'laboratoriya', 'sample_id' => 'proba']);

        return $this->attempt(function () use ($request, $data) {
            $referral = $this->service->create($request->user(), $data);

            return response()->json($this->service->present($referral, $request->user(), true), 201);
        });
    }

    public function cancel(Request $request, InterlabReferral $referral)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], ['reason' => 'sabab']);

        return $this->act($request, $referral, fn ($u) => $this->service->cancel($u, $referral, $data['reason']));
    }

    public function reviewed(Request $request, InterlabReferral $referral)
    {
        return $this->act($request, $referral, fn ($u) => $this->service->markReviewed($u, $referral));
    }

    public function accept(Request $request, InterlabReferral $referral)
    {
        $data = $request->validate(['group_id' => ['nullable', 'integer']]);
        if (! empty($data['group_id']) && ! LaboratoryGroup::whereKey($data['group_id'])->exists()) {
            return response()->json(['message' => 'Guruh topilmadi'], 422);
        }

        return $this->act($request, $referral, fn ($u) => $this->service->accept($u, $referral, $data['group_id'] ?? null));
    }

    public function reject(Request $request, InterlabReferral $referral)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], ['reason' => 'sabab']);

        return $this->act($request, $referral, fn ($u) => $this->service->reject($u, $referral, $data['reason']));
    }

    public function saveResults(Request $request, InterlabReferral $referral)
    {
        $data = $request->validate([
            'results'              => ['present', 'array', 'max:60'],
            'results.*.symbol'     => ['required', 'string', 'max:16'],
            'results.*.value_text' => ['nullable', 'string', 'max:64'],
            'results.*.unit'       => ['nullable', 'string', 'max:32'],
            'results.*.method'     => ['nullable', 'string', 'max:255'],
            'results.*.notes'      => ['nullable', 'string', 'max:500'],
            'result_note'          => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->act($request, $referral,
            fn ($u) => $this->service->saveDraft($u, $referral, $data['results'], $data['result_note'] ?? null));
    }

    public function sendResults(Request $request, InterlabReferral $referral)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return $this->act($request, $referral, fn ($u) => $this->service->sendResults($u, $referral, $data['reason'] ?? null));
    }

    public function retry(Request $request, InterlabMessage $message, Outbox $outbox)
    {
        return $this->attempt(function () use ($request, $message, $outbox) {
            if ($message->referral_uuid) {
                $referral = InterlabReferral::where('uuid', $message->referral_uuid)->first();
                if (! $referral) {
                    throw new InterlabException('Yo\'llanma topilmadi', 404);
                }
                $this->service->assertVisible($request->user(), $referral);
            } elseif (! $request->user()->isAdmin() && ! $request->user()->hasPermission('interlab.manage')) {
                throw new InterlabException('Ruxsat yo\'q', 403);
            }
            $outbox->retry($message);
            $peer = $message->peer;
            $stats = $peer ? $outbox->flush($peer, true) : null;

            return ['ok' => true, 'stats' => $stats, 'message' => $message->fresh()->only(['id', 'status', 'attempts', 'last_error', 'next_attempt_at', 'sent_at'])];
        });
    }

    private function act(Request $request, InterlabReferral $referral, callable $action)
    {
        return $this->attempt(function () use ($request, $referral, $action) {
            $this->service->assertVisible($request->user(), $referral);
            $updated = $action($request->user());

            return $this->service->present($updated, $request->user(), true);
        });
    }
}
