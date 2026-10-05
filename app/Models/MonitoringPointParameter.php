<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringPointParameter extends Model
{
    use HasFactory;

    protected $fillable = [
        'monitoring_point_id', 'parameter', 'unit',
        'norm_min', 'norm_max', 'sort_order',
    ];

    protected $casts = [
        'norm_min' => 'decimal:6',
        'norm_max' => 'decimal:6',
    ];

    public function point(): BelongsTo
    {
        return $this->belongsTo(MonitoringPoint::class, 'monitoring_point_id');
    }
}
