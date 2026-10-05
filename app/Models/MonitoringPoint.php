<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'type', 'location', 'latitude', 'longitude',
        'laboratory_group_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'latitude'  => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(LaboratoryGroup::class, 'laboratory_group_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(WaterOrder::class);
    }

    // Shu nuqta uchun standart ko'rsatkichlar va me'yorlar
    public function parameters(): HasMany
    {
        return $this->hasMany(MonitoringPointParameter::class)->orderBy('sort_order');
    }
}
