<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\Notification;
use App\Models\Sample;
use App\Models\TestObject;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * TZ 1.5 — kalibrovka va xizmat eslatmalari:
 * sana, ishlatilish soni yoki boshqa shartlar bo'yicha.
 * Kuniga bir marta ishga tushiriladi (rejalashtirilgan).
 */
class CheckReminders extends Command
{
    protected $signature = 'lims:check-reminders {--days=30 : Necha kun oldin ogohlantirish}';

    protected $description = 'Kalibrovka, texnik xizmat va saqlash muddatlarini tekshirib, eslatmalar yaratadi';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $created = 0;

        // Eslatmalarni kim ko'radi: uskuna ko'rish ruxsati borlar
        $recipients = User::where('is_active', true)->get()
            ->filter(fn ($u) => $u->hasPermission('equipment.view'))
            ->pluck('id');

        $objectRecipients = User::where('is_active', true)->get()
            ->filter(fn ($u) => $u->hasPermission('test_objects.view'))
            ->pluck('id');

        /* ---------- 1. Kalibrovka muddati (sana bo'yicha) ---------- */
        $items = Equipment::whereNotNull('next_calibration_at')
            ->whereDate('next_calibration_at', '<=', now()->addDays($days))
            ->get();

        foreach ($items as $eq) {
            $left = now()->startOfDay()->diffInDays($eq->next_calibration_at->startOfDay(), false);
            $overdue = $left < 0;

            $created += $this->push($recipients, [
                'type'       => 'calibration',
                'level'      => $overdue ? 'critical' : ($left <= 7 ? 'warning' : 'info'),
                'title'      => $overdue
                    ? "Kalibrovka muddati o'tgan: {$eq->name}"
                    : "Kalibrovka yaqinlashmoqda: {$eq->name}",
                'body'       => $overdue
                    ? abs($left) . " kun kechikkan · " . $eq->serial_number
                    : "{$left} kun qoldi · " . $eq->serial_number,
                'link_route' => 'equipment-detail',
                'link_id'    => $eq->id,
                'dedup_key'  => "cal-{$eq->id}-" . $eq->next_calibration_at->format('Ymd'),
            ]);
        }

        /* ---------- 2. Ishlatilish soni bo'yicha ---------- */
        $byUsage = Equipment::whereNotNull('max_usage_before_calibration')
            ->whereColumn('usage_count', '>=', 'max_usage_before_calibration')
            ->get();

        foreach ($byUsage as $eq) {
            $created += $this->push($recipients, [
                'type'       => 'calibration',
                'level'      => 'critical',
                'title'      => "Ishlatilish limiti tugadi: {$eq->name}",
                'body'       => "{$eq->usage_count} / {$eq->max_usage_before_calibration} · kalibrovka talab etiladi",
                'link_route' => 'equipment-detail',
                'link_id'    => $eq->id,
                'dedup_key'  => "usage-{$eq->id}-{$eq->usage_count}",
            ]);
        }

        /* ---------- 3. Boshqa shartlar (ixtiyoriy sana) ---------- */
        $byCustom = Equipment::whereNotNull('custom_condition_due')
            ->whereDate('custom_condition_due', '<=', now()->addDays($days))
            ->get();

        foreach ($byCustom as $eq) {
            $created += $this->push($recipients, [
                'type'       => 'calibration',
                'level'      => 'warning',
                'title'      => "Shart bajarilishi kerak: {$eq->name}",
                'body'       => $eq->custom_condition ?: 'Qo\'shimcha shart muddati yaqinlashdi',
                'link_route' => 'equipment-detail',
                'link_id'    => $eq->id,
                'dedup_key'  => "cond-{$eq->id}-" . $eq->custom_condition_due->format('Ymd'),
            ]);
        }

        /* ---------- 4. Texnik xizmat ---------- */
        $maint = Equipment::whereNotNull('next_maintenance_at')
            ->whereDate('next_maintenance_at', '<=', now()->addDays($days))
            ->get();

        foreach ($maint as $eq) {
            $left = now()->startOfDay()->diffInDays($eq->next_maintenance_at->startOfDay(), false);
            $created += $this->push($recipients, [
                'type'       => 'maintenance',
                'level'      => $left < 0 ? 'warning' : 'info',
                'title'      => "Texnik xizmat: {$eq->name}",
                'body'       => $left < 0 ? abs($left) . ' kun kechikkan' : "{$left} kun qoldi",
                'link_route' => 'equipment-detail',
                'link_id'    => $eq->id,
                'dedup_key'  => "mnt-{$eq->id}-" . $eq->next_maintenance_at->format('Ymd'),
            ]);
        }

        /* ---------- 5. Saqlash muddati (TZ 2.12) ---------- */
        $objects = TestObject::whereNotNull('storage_expiry')
            ->where('status', '!=', 'disposed')
            ->whereDate('storage_expiry', '<=', now()->addDays(14))
            ->get();

        foreach ($objects as $obj) {
            $left = now()->startOfDay()->diffInDays($obj->storage_expiry->startOfDay(), false);
            $created += $this->push($objectRecipients, [
                'type'       => 'storage',
                'level'      => $left < 0 ? 'critical' : 'warning',
                'title'      => "Saqlash muddati: {$obj->name}",
                'body'       => ($left < 0 ? abs($left) . ' kun o\'tgan' : "{$left} kun qoldi") . " · {$obj->identifier}",
                'link_route' => 'test-object-detail',
                'link_id'    => $obj->id,
                'dedup_key'  => "stor-{$obj->id}-" . $obj->storage_expiry->format('Ymd'),
            ]);
        }

        /* ---------- 6. Uzoq turgan probalar ---------- */
        $stale = Sample::whereIn('status', ['assigned', 'in_progress'])
            ->whereNotNull('assigned_at')
            ->where('assigned_at', '<=', now()->subDays(7))
            ->get();

        foreach ($stale as $sample) {
            $recipient = User::where('laboratory_group_id', $sample->assigned_group_id)
                ->where('is_active', true)->pluck('id');

            $created += $this->push($recipient, [
                'type'       => 'sample',
                'level'      => 'warning',
                'title'      => "Proba 7 kundan beri ishda: {$sample->code}",
                'body'       => "Proba raqami: {$sample->sample_number}",
                'link_route' => 'sample-detail',
                'link_id'    => $sample->id,
                'dedup_key'  => "stale-{$sample->id}-" . now()->format('YW'),
            ]);
        }

        $this->info("Yaratilgan eslatmalar: {$created}");

        // Eski o'qilgan eslatmalarni tozalash (60 kundan oshgan)
        Notification::whereNotNull('read_at')
            ->where('read_at', '<=', now()->subDays(60))
            ->delete();

        return self::SUCCESS;
    }

    /**
     * Har bir qabul qiluvchiga eslatma yaratadi (takrorlanmasligini tekshirib).
     */
    private function push($userIds, array $data): int
    {
        $count = 0;
        foreach ($userIds as $userId) {
            $exists = Notification::where('user_id', $userId)
                ->where('dedup_key', $data['dedup_key'])
                ->exists();

            if ($exists) {
                continue;
            }

            Notification::create(array_merge($data, ['user_id' => $userId]));
            $count++;
        }
        return $count;
    }
}
