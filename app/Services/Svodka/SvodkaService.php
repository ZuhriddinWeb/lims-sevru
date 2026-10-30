<?php

namespace App\Services\Svodka;

use App\Models\Notification;
use App\Models\Sample;
use App\Models\SvodkaAssignment;
use App\Models\SvodkaPoint;
use App\Models\SvodkaRelease;
use App\Models\SvodkaSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Buyurtmachi svodkasi — yadro. [Paket 29]
 *
 * DAVR (ishlab chiqarish navbatchiligi), standart:
 *   I  — oldingi kun 20:00 → shu kun 08:00   (sanasi — tugagan kun: «26.10 I» = 25.10 20:00 – 26.10 08:00)
 *   II — shu kun 08:00 → shu kun 20:00
 * Namuna davri: buyurtmachi bog'lagan sana/navbatchilik; bog'lanmagan bo'lsa — ro'yxatga olingan vaqt bo'yicha.
 *
 * CHIQARISH: laboratoriya boshlig'i davrni chiqaradi — shu paytdagi tasdiqlangan natijalar nusxasi
 * saqlanadi (o'zgartirib bo'lmaydi). Keyin natija qo'shilsa/tuzatilsa — «qayta chiqarish» (yangi tahrir).
 *
 * YOPISH: svodka rejimidagi bo'limda buyurtmachi natijani faqat chiqarilgan va o'shandan beri
 * o'zgarmagan bo'lsa ko'radi (har element alohida). Dastlabki (tasdiqlanmagan) natija ko'rsatilmaydi.
 */
class SvodkaService
{
    public const RELEASE_PERMISSION = 'svodka.release';

    /** @var array<int, SvodkaSetting> */
    private array $settings = [];

    public function available(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('svodka_settings') && Schema::hasTable('svodka_releases');
    }

    public function settings(int $deptId): SvodkaSetting
    {
        return $this->settings[$deptId] ??= ($this->available()
            ? SvodkaSetting::forDepartment($deptId)
            : new SvodkaSetting(['customer_department_id' => $deptId] + SvodkaSetting::DEFAULTS));
    }

    public function enabled(?int $deptId): bool
    {
        return $deptId && $this->available() && (bool) $this->settings($deptId)->enabled;
    }

    public function forget(): void
    {
        $this->settings = [];
    }

    // ------------------------------------------------------------------ davrlar

    /** Vaqt qaysi davrga tushadi: [Y-m-d, 1|2] */
    public static function periodOf(Carbon $t, SvodkaSetting $s): array
    {
        $hm = $t->format('H:i');
        $s1 = $s->shift1_start ?: '20:00';
        $s2 = $s->shift2_start ?: '08:00';
        if ($hm >= $s2 && $hm < $s1) {
            return [$t->toDateString(), 2];
        }
        if ($hm >= $s1) {
            return [$t->copy()->addDay()->toDateString(), 1];
        }

        return [$t->toDateString(), 1];
    }

    /** Davr oralig'i [boshlanish, tugash) */
    public static function window(string $date, int $shift, SvodkaSetting $s): array
    {
        $d = Carbon::parse($date)->startOfDay();
        [$h1, $m1] = array_map('intval', explode(':', $s->shift1_start ?: '20:00'));
        [$h2, $m2] = array_map('intval', explode(':', $s->shift2_start ?: '08:00'));
        if ($shift === 1) {
            return [$d->copy()->subDay()->setTime($h1, $m1), $d->copy()->setTime($h2, $m2)];
        }

        return [$d->copy()->setTime($h2, $m2), $d->copy()->setTime($h1, $m1)];
    }

    /** Chiqarish kaliti: sutka rejimida navbatchilik null */
    public function releaseShift(SvodkaSetting $s, int $shift): ?int
    {
        return $s->release_mode === 'shift' ? $shift : null;
    }

    // ------------------------------------------------------------------ bog'lash (faqat buyurtmachi tomoni)

    /**
     * Namunalarning nuqtasi va davri. Qo'lda bog'lash buyurtmadagidan ustun.
     * @return array<int, array{point_id:int|null, date:string, shift:int, source:string}>
     */
    public function mappings(Collection $samples): array
    {
        $ids = $samples->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (! $ids || ! $this->available()) {
            return [];
        }
        $manual = SvodkaAssignment::whereIn('sample_id', $ids)->get()->keyBy('sample_id');
        $items = DB::table('sample_request_items')
            ->join('sample_requests', 'sample_requests.id', '=', 'sample_request_items.sample_request_id')
            ->whereIn('sample_request_items.sample_id', $ids)
            ->get(['sample_request_items.sample_id', 'sample_request_items.svodka_point_id',
                'sample_requests.svodka_date', 'sample_requests.svodka_shift'])
            ->keyBy(fn ($r) => (int) $r->sample_id);

        $out = [];
        foreach ($samples as $s) {
            $sid = (int) $s->id;
            $set = $this->settings((int) $s->customer_department_id);
            [$rDate, $rShift] = self::periodOf(($s->registered_at ?? $s->created_at ?? now())->copy(), $set);
            if ($m = $manual->get($sid)) {
                $out[$sid] = ['point_id' => (int) $m->point_id, 'date' => $m->production_date->toDateString(), 'shift' => (int) $m->shift, 'source' => 'manual'];
            } elseif (($it = $items->get($sid)) && ($it->svodka_point_id || $it->svodka_date)) {
                $date = $it->svodka_date ? Carbon::parse($it->svodka_date)->toDateString() : $rDate;
                $shift = in_array((int) $it->svodka_shift, [1, 2], true) ? (int) $it->svodka_shift : $rShift;
                $out[$sid] = ['point_id' => $it->svodka_point_id ? (int) $it->svodka_point_id : null, 'date' => $date, 'shift' => $shift, 'source' => 'request'];
            } else {
                $out[$sid] = ['point_id' => null, 'date' => $rDate, 'shift' => $rShift, 'source' => 'registered'];
            }
        }

        return $out;
    }

    /** Davrdagi namunalar (bo'lim): bog'langanlari + bog'lanmagan, shu oraliqda ro'yxatga olinganlar */
    public function periodSamples(int $deptId, string $date, ?int $shift, bool $withElements = true): Collection
    {
        $set = $this->settings($deptId);
        $shifts = $shift ? [$shift] : [1, 2];
        [$from] = self::window($date, $shifts[0], $set);
        [, $to] = self::window($date, end($shifts), $set);

        $manualIds = SvodkaAssignment::where('customer_department_id', $deptId)->whereDate('production_date', $date)
            ->whereIn('shift', $shifts)->pluck('sample_id')->map(fn ($v) => (int) $v)->all();
        $requestIds = DB::table('sample_request_items')
            ->join('sample_requests', 'sample_requests.id', '=', 'sample_request_items.sample_request_id')
            ->where('sample_requests.customer_department_id', $deptId)
            ->whereDate('sample_requests.svodka_date', $date)
            ->where(fn ($q) => $q->whereIn('sample_requests.svodka_shift', $shifts)->orWhereNull('sample_requests.svodka_shift'))
            ->whereNotNull('sample_request_items.sample_id')
            ->pluck('sample_request_items.sample_id')->map(fn ($v) => (int) $v)->all();
        $windowIds = Sample::where('customer_department_id', $deptId)
            ->where('registered_at', '>=', $from)->where('registered_at', '<', $to)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        $ids = array_values(array_unique(array_merge($manualIds, $requestIds, $windowIds)));
        if (! $ids) {
            return collect();
        }
        $q = Sample::query()->where('customer_department_id', $deptId)->whereIn('id', $ids);
        if ($withElements) {
            $q->with(['sampleElements:id,sample_id,element_id,result_value,result_unit,status', 'sampleElements.element:id,symbol']);
        }
        $samples = $q->orderBy('registered_at')->orderBy('id')->get();
        $map = $this->mappings($samples);

        return $samples->filter(function ($s) use ($map, $date, $shifts) {
            $m = $map[(int) $s->id] ?? null;

            return $m && $m['date'] === $date && in_array($m['shift'], $shifts, true);
        })->values();
    }

    // ------------------------------------------------------------------ natijalar nusxasi

    /** Namunaning tasdiqlangan natijalari: element_id => [qiymat, birlik] */
    public static function finals($sample): array
    {
        $out = [];
        foreach ($sample->sampleElements as $se) {
            if ($se->status === 'done' && $se->result_value !== null) {
                $out[(string) (int) $se->element_id] = [round((float) $se->result_value, 6), $se->result_unit];
            }
        }
        ksort($out);

        return $out;
    }

    private static function same(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return abs((float) $a[0] - (float) $b[0]) < 1e-9 && (string) ($a[1] ?? '') === (string) ($b[1] ?? '');
    }

    /** Davrni qamragan eng oxirgi chiqarish (aniq navbatchilik yoki butun sutka) */
    public function coveringRelease(int $deptId, string $date, int $shift): ?SvodkaRelease
    {
        return SvodkaRelease::where('customer_department_id', $deptId)->whereDate('production_date', $date)
            ->where(fn ($q) => $q->where('shift', $shift)->orWhereNull('shift'))
            ->orderByDesc('id')->first();
    }

    /** Aynan shu kalitdagi oxirgi chiqarish */
    public function latestRelease(int $deptId, string $date, ?int $shift): ?SvodkaRelease
    {
        $q = SvodkaRelease::where('customer_department_id', $deptId)->whereDate('production_date', $date);
        $shift === null ? $q->whereNull('shift') : $q->where('shift', $shift);

        return $q->orderByDesc('revision')->orderByDesc('id')->first();
    }

    /**
     * Davr holati (laboratoriya boshlig'i uchun — nuqta nomlarisiz).
     * @return array{samples:int, complete:int, pending:int, values:int, release:?array, changed:int, ended:bool}
     */
    public function periodStatus(int $deptId, string $date, ?int $shift, ?Collection $samples = null): array
    {
        $set = $this->settings($deptId);
        $samples ??= $this->periodSamples($deptId, $date, $shift);
        $rel = $this->latestRelease($deptId, $date, $shift);
        $payload = $rel?->payload ?? [];
        $complete = 0;
        $values = 0;
        $changed = 0;
        foreach ($samples as $s) {
            $f = self::finals($s);
            $values += count($f);
            if ($s->sampleElements->count() && count($f) === $s->sampleElements->count()) {
                $complete++;
            }
            if ($rel && $f != ($payload[(string) (int) $s->id] ?? null) && ! ($f === [] && ! isset($payload[(string) (int) $s->id]))) {
                $changed++;
            }
        }
        [, $end] = self::window($date, $shift ?? 2, $set);

        return [
            'samples'  => $samples->count(),
            'complete' => $complete,
            'pending'  => $samples->count() - $complete,
            'values'   => $values,
            'release'  => $rel ? [
                'id' => (int) $rel->id, 'revision' => (int) $rel->revision,
                'released_at' => $rel->released_at?->toDateTimeString(), 'released_by' => $rel->releaser?->name,
                'samples' => (int) $rel->samples_count, 'values' => (int) $rel->values_count,
            ] : null,
            'changed'  => $changed,
            'ended'    => now()->greaterThanOrEqualTo($end),
        ];
    }

    /** Laboratoriya boshlig'i chiqaradi: o'zgartirish yo'q — faqat shu paytdagi natijalar nusxasi */
    public function release(int $deptId, string $date, ?int $shift, User $user, ?string $note = null): SvodkaRelease
    {
        $set = $this->settings($deptId);
        if (! $set->enabled) {
            throw ValidationException::withMessages(['department_id' => ["Bu bo'lim uchun svodka rejimi yoqilmagan"]]);
        }
        $expected = $set->release_mode === 'shift';
        if ($expected && ! in_array($shift, [1, 2], true)) {
            throw ValidationException::withMessages(['shift' => ['Navbatchilikni tanlang (I yoki II)']]);
        }
        if (! $expected) {
            $shift = null;
        }

        return DB::transaction(function () use ($deptId, $date, $shift, $user, $note) {
            $samples = $this->periodSamples($deptId, $date, $shift);
            $payload = [];
            $values = 0;
            foreach ($samples as $s) {
                $f = self::finals($s);
                $payload[(string) (int) $s->id] = $f;
                $values += count($f);
            }
            if ($values === 0) {
                throw ValidationException::withMessages(['date' => ["Bu davrda hali birorta tasdiqlangan natija yo'q"]]);
            }
            $prev = $this->latestRelease($deptId, $date, $shift);
            if ($prev && $prev->payload == $payload) {
                throw ValidationException::withMessages(['date' => ["O'zgarish yo'q — bu davr allaqachon shu natijalar bilan chiqarilgan"]]);
            }
            $rel = SvodkaRelease::create([
                'customer_department_id' => $deptId,
                'production_date'        => $date,
                'shift'                  => $shift,
                'revision'               => $prev ? $prev->revision + 1 : 1,
                'payload'                => $payload,
                'samples_count'          => count($payload),
                'values_count'           => $values,
                'released_by'            => $user->id,
                'released_at'            => now(),
                'note'                   => $note ? mb_substr($note, 0, 500) : null,
            ]);
            $this->log($user, $rel);
            DB::afterCommit(fn () => $this->notifyCustomers($rel));

            return $rel;
        });
    }

    // ------------------------------------------------------------------ yopish (buyurtmachi ko'rinishi)

    /**
     * @return array<int, array{enabled:bool, released:bool, full:bool, visible: array<int,bool>}>
     *   visible — element_id => ko'rsatish mumkinmi (chiqarilgan va o'shandan beri o'zgarmagan)
     */
    public function gate(Collection $samples): array
    {
        $out = [];
        $byDept = $samples->groupBy(fn ($s) => (int) $s->customer_department_id);
        foreach ($byDept as $deptId => $list) {
            if (! $this->enabled((int) $deptId)) {
                foreach ($list as $s) {
                    $out[(int) $s->id] = ['enabled' => false, 'released' => true, 'full' => true, 'visible' => []];
                }
                continue;
            }
            $map = $this->mappings($list);
            $dates = array_values(array_unique(array_column($map, 'date')));
            // sana oralig'i bilan olinadi, aniq solishtirish PHP da (bazaga bog'liq emas)
            $releases = SvodkaRelease::where('customer_department_id', $deptId)
                ->whereDate('production_date', '>=', min($dates ?: [now()->toDateString()]))
                ->whereDate('production_date', '<=', max($dates ?: [now()->toDateString()]))
                ->orderBy('id')->get();

            foreach ($list as $s) {
                $sid = (int) $s->id;
                $m = $map[$sid];
                $rel = $releases->filter(fn ($r) => $r->production_date->toDateString() === $m['date']
                    && ($r->shift === null || (int) $r->shift === (int) $m['shift']))->last();
                $snap = $rel ? ($rel->payload[(string) $sid] ?? null) : null;
                $finals = self::finals($s);
                $visible = [];
                foreach ($s->sampleElements as $se) {
                    $eid = (string) (int) $se->element_id;
                    $visible[(int) $se->element_id] = $snap !== null && isset($snap[$eid], $finals[$eid]) && self::same($snap[$eid], $finals[$eid]);
                }
                $out[$sid] = [
                    'enabled'  => true,
                    'released' => $snap !== null,
                    'full'     => $snap !== null && $visible && ! in_array(false, $visible, true),
                    'visible'  => $visible,
                ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ xabarnomalar

    /** Natija yozilganda (kuzatuvchi): davr tugagan va hammasi tayyor bo'lsa — boshliqqa xabar */
    public function checkReady(int $sampleId): void
    {
        try {
            $s = Sample::with('sampleElements:id,sample_id,element_id,result_value,result_unit,status')->find($sampleId);
            if (! $s || ! $this->enabled((int) $s->customer_department_id)) {
                return;
            }
            $deptId = (int) $s->customer_department_id;
            $set = $this->settings($deptId);
            $m = $this->mappings(collect([$s]))[$sampleId];
            $shift = $this->releaseShift($set, (int) $m['shift']);
            $st = $this->periodStatus($deptId, $m['date'], $shift);
            if (! $st['ended'] || $st['samples'] === 0 || $st['pending'] > 0) {
                return;
            }
            if ($st['release'] && $st['changed'] === 0) {
                return;
            }
            $rev = ($st['release']['revision'] ?? 0) + 1;
            $dept = DB::table('customer_departments')->where('id', $deptId)->value('name');
            $when = Carbon::parse($m['date'])->format('d.m.Y') . ($shift ? ' · ' . ($shift === 1 ? $set->shift1_label : $set->shift2_label) : '');
            $title = $st['release'] ? "Svodka: yangi/tuzatilgan natija — {$dept}" : "Svodka tayyor — {$dept}";
            // ruxsati rol orqali berilganlar (boshliqlar); hech kim bo'lmasa — administratorlar
            $portal = app(\App\Services\Samples\CustomerPortal::class);
            $users = User::where('is_active', true)->get()->filter(fn ($u) => ! $u->isAdmin() && ! $portal->isPortalUser($u) && $u->hasPermission(self::RELEASE_PERMISSION));
            if ($users->isEmpty()) {
                $users = User::where('is_active', true)->get()->filter(fn ($u) => $u->isAdmin());
            }
            foreach ($users as $u) {
                Notification::firstOrCreate(
                    ['dedup_key' => "svodka-ready-{$deptId}-{$m['date']}-" . ($shift ?? 'd') . "-r{$rev}-{$u->id}"],
                    [
                        'user_id' => $u->id, 'type' => 'sample', 'level' => 'info', 'title' => $title,
                        'body' => "{$when}: {$st['samples']} ta namuna natijasi tayyor. Tekshirib, «Chiqarish» ni bosing.",
                        'link_route' => 'svodka-releases', 'link_id' => $deptId,
                    ]
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function notifyCustomers(SvodkaRelease $rel): void
    {
        try {
            $set = $this->settings((int) $rel->customer_department_id);
            $when = $rel->production_date->format('d.m.Y') . ($rel->shift ? ' · ' . ($rel->shift === 1 ? $set->shift1_label : $set->shift2_label) : '');
            $userIds = DB::table('customer_department_user')->where('customer_department_id', $rel->customer_department_id)->pluck('user_id');
            foreach (User::whereIn('id', $userIds)->where('is_active', true)->get() as $u) {
                Notification::firstOrCreate(
                    ['dedup_key' => "svodka-released-{$rel->id}-{$u->id}"],
                    [
                        'user_id' => $u->id, 'type' => 'sample', 'level' => 'success',
                        'title' => 'Svodka tayyor: ' . $when . ($rel->revision > 1 ? " ({$rel->revision}-tahrir)" : ''),
                        'body' => "Laboratoriya natijalarni tasdiqladi — «Svodka» sahifasida ko'rish va Excel'ga yuklab olish mumkin.",
                        'link_route' => 'portal-svodka', 'link_id' => $rel->id,
                    ]
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function log(User $user, SvodkaRelease $rel): void
    {
        try {
            \App\Models\ActivityLog::create([
                'user_id' => $user->id, 'action' => 'created', 'model_type' => 'SvodkaRelease', 'model_id' => $rel->id,
                'description' => "Svodka chiqarildi: bo'lim #{$rel->customer_department_id}, " . $rel->production_date->format('d.m.Y')
                    . ($rel->shift ? " ({$rel->shift}-navbatchilik)" : ' (sutka)') . ", {$rel->revision}-tahrir, {$rel->samples_count} namuna, {$rel->values_count} qiymat",
                'properties' => null, 'ip_address' => request()?->ip(), 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Nuqta shu bo'limnikimi */
    public function pointBelongs(?int $pointId, int $deptId): bool
    {
        return $pointId === null || SvodkaPoint::where('id', $pointId)->where('customer_department_id', $deptId)->exists();
    }
}
