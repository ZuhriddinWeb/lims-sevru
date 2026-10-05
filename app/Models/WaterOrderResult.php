<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaterOrderResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'water_order_id', 'parameter', 'value', 'unit', 'equipment_id',
        'norm_min', 'norm_max', 'assessment', 'measured_at', 'entered_by',
    ];

    protected $casts = [
        'measured_at' => 'datetime',
        'value'       => 'decimal:6',
        'norm_min'    => 'decimal:6',
        'norm_max'    => 'decimal:6',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(WaterOrder::class, 'water_order_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    // Me'yorga qarab baholashni avtomatik aniqlash
    public function evaluateAssessment(): string
    {
        if ($this->value === null) {
            return 'unknown';
        }
        $v = (float) $this->value;
        if ($this->norm_min !== null && $v < (float) $this->norm_min) {
            return 'critical';
        }
        if ($this->norm_max !== null && $v > (float) $this->norm_max) {
            return 'critical';
        }
        // Chegaraga yaqin bo'lsa (10%) ogohlantirish
        if ($this->norm_max !== null && $v > (float) $this->norm_max * 0.9) {
            return 'warning';
        }
        return 'normal';
    }
}
