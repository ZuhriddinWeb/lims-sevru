<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CloseReason;
use App\Models\CustomerDepartment;
use App\Models\RequestSchedule;
use App\Models\SampleRequest;
use App\Services\Samples\RequestSchedules;
use App\Services\Samples\RequestSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * [Paket 39] «Buyurtma sozlamalari» (ruxsat: sample_requests.settings — admin'da doim bor).
 *
 *   GET  /api/request-settings                        umumiy qiymatlar, bo'linmalar, sabablar
 *   PUT  /api/request-settings                        umumiy qiymatlar
 *   PUT  /api/request-settings/departments/{id}       bo'linma: grafik rejimi, kechikish, oldin yaratish, muddat qoidasi
 *   POST /api/request-settings/reasons                PUT|DELETE /api/request-settings/reasons/{id}
 */
class RequestSettingsController extends Controller
{
    public function __construct(private RequestSettings $settings)
    {
    }

    private function ready(): void
    {
        abort_unless(Schema::hasTable('close_reasons'), 503, "Paket 39 migratsiyasi bajarilmagan — php artisan migrate");
    }

    public function show()
    {
        $this->ready();
        $sched = RequestSchedules::available();

        return response()->json([
            'settings'     => $this->settings->get(),
            'defaults'     => RequestSettings::DEFAULTS,
            'limits'       => RequestSettings::LIMITS,
            'expire_modes' => RequestSettings::EXPIRE_MODES,
            'has_shifts'   => Schema::hasTable('lab_shifts') && \Illuminate\Support\Facades\DB::table('lab_shifts')->where('is_active', true)->exists(),
            'departments'  => CustomerDepartment::where('is_active', true)->orderBy('name')->get()->map(fn ($d) => [
                'id'            => (int) $d->id,
                'name'          => $d->name,
                'enabled'       => $sched && (bool) $d->schedule_enabled,
                'late_minutes'  => (int) ($d->schedule_late_minutes ?: 30),
                'lead_minutes'  => $d->schedule_lead_minutes !== null ? (int) $d->schedule_lead_minutes : null,
                'expire_mode'   => $d->schedule_expire_mode ?: null,
                'expire_hours'  => $d->schedule_expire_hours ? (int) $d->schedule_expire_hours : null,
                'schedules'     => $sched ? RequestSchedule::where('customer_department_id', $d->id)->where('is_active', true)->count() : 0,
            ])->values(),
            'reasons'      => CloseReason::orderBy('sort_order')->orderBy('id')->get()->map(fn ($r) => $this->reason($r))->values(),
        ]);
    }

    public function update(Request $request)
    {
        $this->ready();
        $rules = ['schedule_expire_mode' => ['sometimes', Rule::in(RequestSettings::EXPIRE_MODES)]];
        foreach (RequestSettings::LIMITS as $k => [$min, $max]) {
            $rules[$k] = ['sometimes', 'integer', "between:$min,$max"];
        }
        foreach (RequestSettings::FLAGS as $k) {
            $rules[$k] = ['sometimes', 'boolean']; // [P41]
        }
        $data = $request->validate($rules);
        $before = $this->settings->get();
        $after = $this->settings->set($data, $request->user());
        $this->log($request, 'RequestSettings', null, "Buyurtma sozlamalari o'zgartirildi", ['before' => $before, 'after' => $after]);

        return response()->json(['settings' => $after]);
    }

    public function department(Request $request, int $id)
    {
        $this->ready();
        $data = $request->validate([
            'enabled'      => ['required', 'boolean'],
            'late_minutes' => ['nullable', 'integer', 'between:5,600'],
            'lead_minutes' => ['nullable', 'integer', 'between:0,720'],
            'expire_mode'  => ['nullable', Rule::in(RequestSettings::EXPIRE_MODES)],
            'expire_hours' => ['nullable', 'integer', 'between:1,72'],
        ]);
        abort_unless(RequestSchedules::available(), 503, "Grafik moduli o'rnatilmagan");
        $d = CustomerDepartment::findOrFail($id);
        $d->forceFill([
            'schedule_enabled'      => $data['enabled'],
            'schedule_late_minutes' => $data['late_minutes'] ?? ($d->schedule_late_minutes ?: 30),
            'schedule_lead_minutes' => $data['lead_minutes'] ?? null,
            'schedule_expire_mode'  => $data['expire_mode'] ?? null,
            'schedule_expire_hours' => $data['expire_hours'] ?? null,
        ])->save();
        $this->log($request, 'CustomerDepartment', $d->id, "Grafik sozlamasi: {$d->name} — " . ($data['enabled'] ? 'yoqilgan' : "o'chirilgan")
            . ", kechikish {$d->schedule_late_minutes} daq., oldin " . ($d->schedule_lead_minutes ?? 'umumiy') . ', muddat ' . ($d->schedule_expire_mode ?? 'umumiy'));

        return $this->show();
    }

    /* ---------------------------------------------------------------- sabablar */

    private function reason(CloseReason $r): array
    {
        return [
            'id' => (int) $r->id, 'name_uz' => $r->name_uz, 'name_ru' => $r->name_ru, 'name_en' => $r->name_en,
            'needs_note' => (bool) $r->needs_note, 'sort_order' => (int) $r->sort_order, 'is_active' => (bool) $r->is_active,
            'used' => SampleRequest::where('close_reason_id', $r->id)->count(),
        ];
    }

    private function reasonData(Request $request): array
    {
        $data = $request->validate([
            'name_uz'    => ['required', 'string', 'max:191'],
            'name_ru'    => ['nullable', 'string', 'max:191'],
            'name_en'    => ['nullable', 'string', 'max:191'],
            'needs_note' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        return [
            'name_uz' => trim($data['name_uz']), 'name_ru' => trim((string) ($data['name_ru'] ?? '')) ?: null,
            'name_en' => trim((string) ($data['name_en'] ?? '')) ?: null, 'needs_note' => (bool) ($data['needs_note'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0), 'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    public function storeReason(Request $request)
    {
        $this->ready();
        $r = CloseReason::create($this->reasonData($request));
        $this->log($request, 'CloseReason', $r->id, "Yopish sababi qo'shildi: {$r->name_uz}");

        return response()->json($this->reason($r), 201);
    }

    public function updateReason(Request $request, int $id)
    {
        $this->ready();
        $r = CloseReason::findOrFail($id);
        $r->fill($this->reasonData($request))->save();
        $this->log($request, 'CloseReason', $r->id, "Yopish sababi o'zgartirildi: {$r->name_uz}");

        return response()->json($this->reason($r));
    }

    /** Ishlatilgan sabab o'chirilmaydi — nofaol qilinadi (hisobotda nomi qolsin) */
    public function destroyReason(Request $request, int $id)
    {
        $this->ready();
        $r = CloseReason::findOrFail($id);
        if (SampleRequest::where('close_reason_id', $r->id)->exists()) {
            $r->forceFill(['is_active' => false])->save();
            $this->log($request, 'CloseReason', $r->id, "Yopish sababi nofaol qilindi: {$r->name_uz}");

            return response()->json(['ok' => true, 'deactivated' => true]);
        }
        $this->log($request, 'CloseReason', $r->id, "Yopish sababi o'chirildi: {$r->name_uz}");
        $r->delete();

        return response()->json(['ok' => true, 'deactivated' => false]);
    }

    private function log(Request $request, string $type, ?int $id, string $text, ?array $props = null): void
    {
        try {
            ActivityLog::create([
                'user_id' => $request->user()->id, 'action' => 'updated', 'model_type' => $type, 'model_id' => $id,
                'description' => mb_substr($text, 0, 255), 'properties' => $props, 'ip_address' => $request->ip(), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
