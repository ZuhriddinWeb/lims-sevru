<?php

namespace App\Http\Controllers\Api\Svodka;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerDepartment;
use App\Models\SvodkaSetting;
use App\Models\User;
use App\Services\Svodka\SvodkaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Laboratoriya: svodkani chiqarish (laboratoriya boshlig'i) va bo'lim uchun svodka rejimi sozlamasi. [Paket 29]
 * Bu yerda nuqta (namuna) nomlari HECH QACHON qaytarilmaydi — faqat shifrlar va natijalar.
 * Boshliq hech narsani o'zgartira olmaydi: faqat «Chiqarish» (o'sha paytdagi tasdiqlangan natijalar nusxasi).
 *
 *   GET  /api/svodka-releases/departments           svodka rejimi va sozlamalar
 *   PUT  /api/svodka-releases/settings/{department}  (customer_departments.manage)
 *   GET  /api/svodka-releases/periods?department_id=&to=&days=
 *   GET  /api/svodka-releases/period?department_id=&date=&shift=
 *   POST /api/svodka-releases                        {department_id, date, shift?, note?}  (svodka.release)
 */
class SvodkaReleaseController extends Controller
{
    public function __construct(private SvodkaService $svc)
    {
    }

    public function departments(Request $request)
    {
        abort_unless($this->svc->available(), 404, "Svodka moduli o'rnatilmagan");
        $user = $request->user();
        $canManage = $user->hasPermission('customer_departments.manage');
        $canRelease = $user->hasPermission(SvodkaService::RELEASE_PERMISSION);
        $depts = CustomerDepartment::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);

        return response()->json([
            'can_manage'  => $canManage,
            'can_release' => $canRelease,
            'can_viewers' => (bool) $user->isAdmin(),   // [P31] «Svodkani ko'ruvchilar» — faqat admin
            'departments' => $depts->map(function ($d) {
                $s = $this->svc->settings((int) $d->id);

                return ['id' => (int) $d->id, 'code' => $d->code, 'name' => $d->name] + $this->settingsArray($s);
            })->filter(fn ($d) => $canManage || $d['enabled'])->values(),
        ]);
    }

    private function settingsArray(SvodkaSetting $s): array
    {
        return [
            'enabled' => (bool) $s->enabled, 'release_mode' => $s->release_mode,
            'shift1_label' => $s->shift1_label, 'shift2_label' => $s->shift2_label,
            'shift1_start' => $s->shift1_start, 'shift2_start' => $s->shift2_start, 'title' => $s->title,
        ];
    }

    public function updateSettings(Request $request, int $department)
    {
        abort_unless($this->svc->available(), 404, "Svodka moduli o'rnatilmagan");
        $dept = CustomerDepartment::findOrFail($department);
        $time = ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $data = $request->validate([
            'enabled'      => ['required', 'boolean'],
            'release_mode' => ['required', Rule::in(['day', 'shift'])],
            'shift1_label' => ['required', 'string', 'max:60'],
            'shift2_label' => ['required', 'string', 'max:60'],
            'shift1_start' => $time,
            'shift2_start' => $time,
            'title'        => ['nullable', 'string', 'max:150'],
        ]);
        abort_if($data['shift1_start'] <= $data['shift2_start'], 422, "I navbatchilik boshlanishi (kechqurun) II navbatchilik boshlanishidan (ertalab) keyin bo'lsin, masalan 20:00 va 08:00");

        $s = SvodkaSetting::updateOrCreate(['customer_department_id' => $dept->id], $data + ['updated_by' => $request->user()->id]);
        try {
            ActivityLog::create([
                'user_id' => $request->user()->id, 'action' => 'updated', 'model_type' => 'SvodkaSetting', 'model_id' => $s->id,
                'description' => "Svodka sozlamasi: {$dept->name} — " . ($s->enabled ? 'yoqilgan' : "o'chirilgan") . ", chiqarish: " . ($s->release_mode === 'shift' ? 'navbatchilik' : 'sutka'),
                'properties' => $data, 'ip_address' => $request->ip(), 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
        $this->svc->forget();

        return response()->json(['id' => (int) $dept->id, 'code' => $dept->code, 'name' => $dept->name] + $this->settingsArray($this->svc->settings((int) $dept->id)));
    }

    private function enabledDept(Request $request): int
    {
        abort_unless($this->svc->available(), 404, "Svodka moduli o'rnatilmagan");
        $id = (int) $request->input('department_id');
        abort_unless($id && $this->svc->enabled($id), 422, "Bu bo'lim uchun svodka rejimi yoqilmagan");

        return $id;
    }

    /** Davrlar ro'yxati (oxirgi N kun) */
    public function periods(Request $request)
    {
        $deptId = $this->enabledDept($request);
        $to = $request->filled('to') ? Carbon::parse($request->input('to'))->startOfDay() : now()->startOfDay();
        $days = min(31, max(1, (int) $request->input('days', 7)));
        $set = $this->svc->settings($deptId);
        // bugungi I navbatchilik kechqurun boshlanadi — ertangi sana ham ko'rsatiladi (agar boshlangan bo'lsa)
        [$curDate] = SvodkaService::periodOf(now(), $set);
        if ($to->toDateString() === now()->toDateString() && $curDate > $to->toDateString()) {
            $to = Carbon::parse($curDate);
        }
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $to->copy()->subDays($i)->toDateString();
            foreach ($set->release_mode === 'shift' ? [2, 1] : [null] as $shift) {
                $st = $this->svc->periodStatus($deptId, $date, $shift);
                $out[] = ['date' => $date, 'shift' => $shift] + $st;
            }
        }

        return response()->json([
            'department_id' => $deptId,
            'release_mode'  => $set->release_mode,
            'shift1_label'  => $set->shift1_label,
            'shift2_label'  => $set->shift2_label,
            'periods'       => $out,
        ]);
    }

    /** Davr tafsiloti: shifrlar va natijalar (nuqta nomlarisiz) */
    public function period(Request $request)
    {
        $deptId = $this->enabledDept($request);
        $data = $request->validate(['date' => ['required', 'date'], 'shift' => ['nullable', Rule::in([1, 2])]]);
        $set = $this->svc->settings($deptId);
        $date = Carbon::parse($data['date'])->toDateString();
        $shift = $set->release_mode === 'shift' ? (int) ($data['shift'] ?? 0) ?: null : null;
        abort_if($set->release_mode === 'shift' && ! $shift, 422, 'Navbatchilikni tanlang');

        $samples = $this->svc->periodSamples($deptId, $date, $shift);
        $samples->loadMissing('sampleElements.element:id,symbol');
        $status = $this->svc->periodStatus($deptId, $date, $shift, $samples);
        $rel = $this->svc->latestRelease($deptId, $date, $shift);
        $map = $this->svc->mappings($samples);

        return response()->json([
            'department_id' => $deptId, 'date' => $date, 'shift' => $shift,
            'shift_label'   => $shift ? $set->{"shift{$shift}_label"} : null,
            'status'        => $status,
            'samples'       => $samples->map(function ($s) use ($rel, $map) {
                $snap = $rel?->payload[(string) (int) $s->id] ?? null;
                $finals = SvodkaService::finals($s);

                return [
                    'id' => (int) $s->id, 'code' => $s->code, 'customer_code' => $s->customer_code,
                    'sample_number' => $s->sample_number, 'status' => $s->status,
                    'shift' => $map[(int) $s->id]['shift'] ?? null,
                    'registered_at' => $s->registered_at?->toDateTimeString(),
                    'in_release' => $snap !== null,
                    'changed' => $rel !== null && ($snap ?? []) != $finals && ! ($snap === null && $finals === []),
                    'elements' => $s->sampleElements->map(fn ($se) => [
                        'symbol' => $se->element?->symbol,
                        'value'  => $se->status === 'done' && $se->result_value !== null ? (float) $se->result_value : null,
                        'unit'   => $se->result_unit,
                        'released_value' => isset($snap[(string) (int) $se->element_id]) ? (float) $snap[(string) (int) $se->element_id][0] : null,
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    public function release(Request $request)
    {
        $deptId = $this->enabledDept($request);
        $data = $request->validate([
            'date'  => ['required', 'date'],
            'shift' => ['nullable', Rule::in([1, 2])],
            'note'  => ['nullable', 'string', 'max:500'],
        ]);
        $rel = $this->svc->release($deptId, Carbon::parse($data['date'])->toDateString(),
            isset($data['shift']) ? (int) $data['shift'] : null, $request->user(), $data['note'] ?? null);

        return response()->json([
            'id' => (int) $rel->id, 'revision' => (int) $rel->revision, 'samples' => (int) $rel->samples_count,
            'values' => (int) $rel->values_count, 'released_at' => $rel->released_at?->toDateTimeString(),
        ], 201);
    }

    // ------------------------------------------------------------------ [P31] svodkani ko'ruvchilar (faqat admin)

    private function adminOnly(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, "Svodkani ko'ruvchilarni faqat administrator belgilaydi");
    }

    private function viewersData(): array
    {
        $portal = app(\App\Services\Samples\CustomerPortal::class);
        $ids = Schema::hasTable('svodka_viewers') ? DB::table('svodka_viewers')->pluck('user_id')->map(fn ($v) => (int) $v)->all() : [];
        $loc = in_array(app()->getLocale(), ['uz', 'ru', 'en'], true) ? app()->getLocale() : 'uz';
        $users = User::with('role.permissions')->where('is_active', true)->orderBy('name')->get()
            ->reject(fn ($u) => $u->isAdmin() || $portal->isPortalUser($u))
            ->map(fn ($u) => [
                'id'       => (int) $u->id,
                'name'     => $u->name,
                'username' => $u->username,
                'role'     => $u->role ? ($u->role->{'display_name_' . $loc} ?: $u->role->name) : null,
                'viewer'   => in_array((int) $u->id, $ids, true),
                'via_role' => $u->role && $u->role->permissions->contains('name', 'svodka.view'),
            ])->values();

        return ['users' => $users];
    }

    public function viewers(Request $request)
    {
        $this->adminOnly($request);

        return response()->json($this->viewersData());
    }

    public function addViewer(Request $request)
    {
        $this->adminOnly($request);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $u = User::findOrFail((int) $data['user_id']);
        abort_if(app(\App\Services\Samples\CustomerPortal::class)->isPortalUser($u), 422, "Buyurtmachi foydalanuvchisi o'z svodkasini allaqachon ko'radi");
        if (! DB::table('svodka_viewers')->where('user_id', $u->id)->exists()) {
            DB::table('svodka_viewers')->insert(['user_id' => $u->id, 'granted_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->logViewer($request, $u, true);
        }

        return response()->json($this->viewersData());
    }

    public function removeViewer(Request $request, int $user)
    {
        $this->adminOnly($request);
        $u = User::find($user);
        if (DB::table('svodka_viewers')->where('user_id', $user)->delete() && $u) {
            $this->logViewer($request, $u, false);
        }

        return response()->json($this->viewersData());
    }

    private function logViewer(Request $request, User $u, bool $on): void
    {
        try {
            \App\Models\ActivityLog::create([
                'user_id' => $request->user()->id, 'action' => 'updated', 'model_type' => 'User', 'model_id' => $u->id,
                'description' => ($on ? "Svodkani ko'rish berildi: " : "Svodkani ko'rish olib tashlandi: ") . "{$u->name} ({$u->username})",
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
