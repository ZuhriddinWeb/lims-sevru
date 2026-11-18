<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** [Paket 36] Buyurtmachi grafigi qatori: vaqt + hafta kunlari + namunalar */
class RequestSchedule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_department_id', 'name', 'planned_time', 'weekdays', 'payload', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'payload'   => 'array',
        'is_active' => 'boolean',
    ];

    /** @return int[] ISO hafta kunlari */
    public function days(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $this->weekdays)), fn ($d) => $d >= 1 && $d <= 7));
    }

    public function department()
    {
        return $this->belongsTo(CustomerDepartment::class, 'customer_department_id');
    }
}
