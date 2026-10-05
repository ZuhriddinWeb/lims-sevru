<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Equipment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'equipment';

    protected $fillable = [
        'laboratory_id', 'laboratory_group_id', 'name', 'model',
        'serial_number', 'inventory_number', 'manufacturer', 'supplier',
        'location', 'commissioned_at', 'status', 'is_blocked', 'block_reason',
        'calibration_interval_days', 'last_calibration_at', 'next_calibration_at',
        'usage_count', 'max_usage_before_calibration',
        'custom_condition', 'custom_condition_due',
        'maintenance_interval_days', 'last_maintenance_at', 'next_maintenance_at',
        'notes', 'created_by',
    ];

    protected $casts = [
        'commissioned_at'     => 'date',
        'last_calibration_at' => 'date',
        'next_calibration_at' => 'date',
        'custom_condition_due' => 'date',
        'last_maintenance_at' => 'date',
        'next_maintenance_at' => 'date',
        'is_blocked'          => 'boolean',
    ];

    protected $appends = ['calibration_days_left'];

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LaboratoryGroup::class, 'laboratory_group_id');
    }

    public function calibrations(): HasMany
    {
        return $this->hasMany(EquipmentCalibration::class)->latest('performed_at');
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(EquipmentMaintenance::class)->latest('created_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EquipmentDocument::class)->latest();
    }

    public function usages(): HasMany
    {
        return $this->hasMany(EquipmentUsage::class)->latest('used_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Kalibrovkagacha qolgan kunlar (manfiy = muddati o'tgan)
    public function getCalibrationDaysLeftAttribute(): ?int
    {
        if (! $this->next_calibration_at) {
            return null;
        }
        return now()->startOfDay()->diffInDays($this->next_calibration_at->startOfDay(), false);
    }
}
