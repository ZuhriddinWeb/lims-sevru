<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Laboratoriya smenasi: «Kunduzgi 08:00–20:00», «Tungi 20:00–08:00» ...
 * Tugash soati boshlanishdan kichik yoki teng bo'lsa — smena yarim tundan o'tadi.
 */
class LabShift extends Model
{
    protected $fillable = ['name', 'start_time', 'end_time', 'sort_order', 'is_active'];

    protected $casts = [
        'id'         => 'integer',
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    public function crossesMidnight(): bool
    {
        return $this->end_time <= $this->start_time;
    }

    /** Davomiyligi, daqiqada */
    public function minutes(): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $this->start_time));
        [$eh, $em] = array_map('intval', explode(':', $this->end_time));
        $m = ($eh * 60 + $em) - ($sh * 60 + $sm);

        return $m <= 0 ? $m + 1440 : $m;
    }
}
