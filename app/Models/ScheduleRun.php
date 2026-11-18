<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** [Paket 36] Grafik bo'yicha bir kunlik partiya */
class ScheduleRun extends Model
{
    public const STATUSES = ['planned', 'skipped', 'received', 'partial', 'missed'];

    protected $fillable = [
        'schedule_id', 'customer_department_id', 'run_date', 'planned_time', 'status', 'payload', 'customized',
        'sample_request_id', 'arrived_at', 'received_by', 'received_count', 'missing_count', 'note',
        'late_notified_at', 'updated_by',
    ];

    protected $casts = [
        'payload'          => 'array',
        'customized'       => 'boolean',
        'run_date'         => 'date',
        'arrived_at'       => 'datetime',
        'late_notified_at' => 'datetime',
    ];

    public function schedule()
    {
        return $this->belongsTo(RequestSchedule::class, 'schedule_id');
    }

    public function department()
    {
        return $this->belongsTo(CustomerDepartment::class, 'customer_department_id');
    }
}
