<?php

namespace App\Services\Samples;

use App\Models\AppSetting;
use App\Models\CustomerDepartment;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * [Paket 39] «Buyurtma sozlamalari» — umumiy qiymatlar (app_settings, kalit sample_requests.settings).
 * Bo'linmaga xos qiymat (customer_departments.schedule_*) bo'lsa — o'sha ustun turadi.
 */
class RequestSettings
{
    public const KEY = 'sample_requests.settings';

    public const EXPIRE_MODES = ['day_end', 'shift_end', 'next_run', 'hours'];

    public const DEFAULTS = [
        'schedule_lead_minutes' => 60,        // grafik buyurtmasi vaqtidan necha daqiqa oldin yaratiladi
        'schedule_expire_mode'  => 'day_end', // grafik buyurtmasi qachon «Muddati o'tdi»
        'schedule_expire_hours' => 4,         // mode=hours bo'lsa — grafik vaqtidan necha soat keyin
        'late_entry_hours'      => 72,        // shundan eski qabulni faqat laboratoriya boshlig'i kiritadi
        'auto_close_days'       => 7,         // muddati o'tgan buyurtma N kundan keyin avtomatik yopiladi (0 — yo'q)
        'schedule_code_time'    => true,      // [P41] grafik shifriga soat qo'shiladi («9999-17:00»); o'chiq — faqat prefiks («9999»)
    ];

    /** [P41] ha/yo'q qiymatlar */
    public const FLAGS = ['schedule_code_time'];

    /** [min, max] */
    public const LIMITS = [
        'schedule_lead_minutes' => [0, 720],
        'schedule_expire_hours' => [1, 72],
        'late_entry_hours'      => [1, 720],
        'auto_close_days'       => [0, 90],
    ];

    private ?array $cached = null;

    public function get(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $stored = [];
        try {
            if (Schema::hasTable('app_settings')) {
                $raw = AppSetting::where('key', self::KEY)->value('value');
                $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $this->cached = self::clean($stored + self::DEFAULTS);
    }

    public static function clean(array $v): array
    {
        $out = self::DEFAULTS;
        foreach (self::LIMITS as $k => [$min, $max]) {
            if (isset($v[$k]) && is_numeric($v[$k])) {
                $out[$k] = max($min, min($max, (int) $v[$k]));
            }
        }
        if (in_array($v['schedule_expire_mode'] ?? null, self::EXPIRE_MODES, true)) {
            $out['schedule_expire_mode'] = $v['schedule_expire_mode'];
        }
        foreach (self::FLAGS as $k) {
            if (array_key_exists($k, $v) && $v[$k] !== null) {
                $out[$k] = filter_var($v[$k], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? self::DEFAULTS[$k];
            }
        }

        return $out;
    }

    public function set(array $values, User $user): array
    {
        $new = self::clean($values + $this->get());
        AppSetting::updateOrCreate(['key' => self::KEY], ['value' => json_encode($new), 'updated_by' => $user->id]);
        $this->cached = $new;

        return $new;
    }

    /** Bo'linma uchun amaldagi qiymatlar (bo'linmaniki → umumiy) */
    public function forDepartment(?CustomerDepartment $d): array
    {
        $g = $this->get();
        if (! $d) {
            return $g;
        }
        $lead = $d->getAttribute('schedule_lead_minutes');
        $mode = $d->getAttribute('schedule_expire_mode');
        $hours = $d->getAttribute('schedule_expire_hours');

        return [
            'schedule_lead_minutes' => $lead !== null ? (int) $lead : $g['schedule_lead_minutes'],
            'schedule_expire_mode'  => in_array($mode, self::EXPIRE_MODES, true) ? $mode : $g['schedule_expire_mode'],
            'schedule_expire_hours' => $hours ? (int) $hours : $g['schedule_expire_hours'],
        ] + $g;
    }
}
