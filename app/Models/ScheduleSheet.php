<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** [Paket 37] Grafik jadvali shabloni (admin tuzadi) */
class ScheduleSheet extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_department_id', 'name', 'schedule_ids', 'columns', 'is_active', 'sort_order', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'schedule_ids' => 'array',
        'columns'      => 'array',
        'is_active'    => 'boolean',
    ];

    public function department()
    {
        return $this->belongsTo(CustomerDepartment::class, 'customer_department_id');
    }
}
