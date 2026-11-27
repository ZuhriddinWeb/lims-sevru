<?php

namespace App\Services\Worksheets;

use App\Models\AppSetting;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * [Paket 45] Ishchi varaqni chop etishda pastki «Yaratdi / Tasdiqladi» bloki va QR kod.
 *
 * Sozlama (app_settings, kalit worksheets.print) — admin / worksheet_templates.manage:
 *   sign_block  — blok chiqadimi (o'chiq bo'lsa varaq avvalgidek)
 *   qr          — har kartada QR kod chiqadimi
 *   qr_mode     — text: QR ichida matn (varaq, proba, kim, qachon, tekshiruv kodi) — internetsiz o'qiladi;
 *                 link: LIMS'dagi varaq havolasi (telefon shu tarmoqda bo'lsa ochiladi, kirish talab qilinadi)
 *
 * Tekshiruv kodi — HMAC(APP_KEY, varaq|rol|foydalanuvchi|vaqt) dan 8 belgi: qog'ozdagi kod LIMS'dagi
 * varaq ma'lumotiga mos kelishini tasdiqlaydi (soxta yozuvni oddiy ko'z bilan ajratish uchun).
 */
class PrintStamps
{
    public const KEY = 'worksheets.print';

    public const MODES = ['text', 'link'];

    public const DEFAULTS = ['sign_block' => true, 'qr' => true, 'qr_mode' => 'text'];

    public function settings(): array
    {
        $stored = [];
        try {
            if (Schema::hasTable('app_settings')) {
                $raw = AppSetting::where('key', self::KEY)->value('value');
                $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            }
        } catch (Throwable $e) {
            report($e);
        }

        return self::clean($stored);
    }

    public static function clean(array $v): array
    {
        $out = self::DEFAULTS;
        foreach (['sign_block', 'qr'] as $k) {
            if (array_key_exists($k, $v) && $v[$k] !== null) {
                $out[$k] = filter_var($v[$k], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? self::DEFAULTS[$k];
            }
        }
        if (in_array($v['qr_mode'] ?? null, self::MODES, true)) {
            $out['qr_mode'] = $v['qr_mode'];
        }

        return $out;
    }

    public function update(array $v, User $user): array
    {
        $new = self::clean($v + $this->settings());
        AppSetting::updateOrCreate(['key' => self::KEY], ['value' => json_encode($new), 'updated_by' => $user->id]);

        return $new;
    }

    /** Varaq javobiga qo'shiladigan blok: sozlama + yaratgan / tasdiqlagan (ism, vaqt, kod) */
    public function forWorksheet(Worksheet $w): array
    {
        $s = $this->settings();

        return [
            'settings' => $s,
            'created'  => $this->stamp($w, 'created', $w->created_by, $w->created_at, $w->creator?->name),
            'approved' => $w->approved_at ? $this->stamp($w, 'approved', $w->approved_by, $w->approved_at, $w->approver?->name) : null,
        ];
    }

    private function stamp(Worksheet $w, string $role, $userId, $at, ?string $name): ?array
    {
        if (! $at) {
            return null;
        }
        $iso = $at->format('Y-m-d H:i');

        return [
            'name' => $name ?: '—',
            'at'   => $iso,
            'code' => self::code((int) $w->id, $role, (int) $userId, $iso),
        ];
    }

    public static function code(int $worksheetId, string $role, int $userId, string $at): string
    {
        $h = strtoupper(substr(hash_hmac('sha256', "ws|{$worksheetId}|{$role}|{$userId}|{$at}", (string) config('app.key')), 0, 8));

        return substr($h, 0, 4) . '-' . substr($h, 4, 4);
    }
}
