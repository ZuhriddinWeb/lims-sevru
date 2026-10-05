<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WaterOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number', 'source_document', 'division', 'assigned_group_id',
        'monitoring_point_id', 'status', 'period_month',
        'registered_by', 'registered_at', 'started_at', 'started_by',
        'completed_at', 'notes',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
    ];

    public function assignedGroup(): BelongsTo
    {
        return $this->belongsTo(LaboratoryGroup::class, 'assigned_group_id');
    }

    public function monitoringPoint(): BelongsTo
    {
        return $this->belongsTo(MonitoringPoint::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(WaterOrderResult::class);
    }

    // Nomi 'starter' — 'started_by' ustuni bilan to'qnashmasligi uchun
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
