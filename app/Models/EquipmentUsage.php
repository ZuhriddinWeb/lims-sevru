<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'equipment_id', 'context_type', 'context_id',
        'description', 'user_id', 'used_at',
    ];

    protected $casts = ['used_at' => 'datetime'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
