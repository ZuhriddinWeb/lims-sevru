<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentUsage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Uskunadan sinovda foydalanishni nazorat qiladi — TZ 1.5, 1.7, 1.8.
 */
class EquipmentUsageService
{
    /**
     * Uskunani sinovda ishlatish mumkinmi? Mumkin bo'lmasa — 422 xato.
     */
    public function assertUsable(Equipment $equipment): void
    {
        if ($equipment->is_blocked) {
            throw ValidationException::withMessages([
                'equipment_id' => [
                    'Uskuna bloklangan: ' . ($equipment->block_reason ?: 'kalibrovkadan o\'tmagan'),
                ],
            ]);
        }

        if ($equipment->status === 'out_of_service') {
            throw ValidationException::withMessages([
                'equipment_id' => ['Uskuna ishdan chiqqan holatda'],
            ]);
        }

        if ($equipment->status === 'awaiting_calibration') {
            throw ValidationException::withMessages([
                'equipment_id' => ['Uskuna kalibrovka kutmoqda'],
            ]);
        }

        // Kalibrovka muddati o'tganmi?
        if ($equipment->next_calibration_at && $equipment->next_calibration_at->isPast()) {
            throw ValidationException::withMessages([
                'equipment_id' => [
                    'Kalibrovka muddati o\'tgan: ' . $equipment->next_calibration_at->format('d.m.Y'),
                ],
            ]);
        }
    }

    /**
     * Foydalanishni qayd etadi va hisoblagichni oshiradi.
     * Limitga yetganda uskuna avtomatik bloklanadi.
     */
    public function register(Equipment $equipment, Model $context, ?string $description = null): void
    {
        EquipmentUsage::create([
            'equipment_id' => $equipment->id,
            'context_type' => class_basename($context),
            'context_id'   => $context->getKey(),
            'description'  => $description,
            'user_id'      => auth()->id(),
            'used_at'      => now(),
        ]);

        $equipment->increment('usage_count');
        $equipment->refresh();

        // TZ 1.5 — ishlatilish soni bo'yicha kalibrovka talabi
        if ($equipment->max_usage_before_calibration
            && $equipment->usage_count >= $equipment->max_usage_before_calibration) {
            $equipment->update([
                'status'       => 'awaiting_calibration',
                'is_blocked'   => true,
                'block_reason' => 'Ishlatilish limiti tugadi ('
                    . $equipment->usage_count . '/' . $equipment->max_usage_before_calibration . ')',
            ]);
        }
    }
}
