<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentMaintenance extends Model
{
    use HasFactory;

    protected $fillable = [
        'equipment_id', 'type', 'scheduled_at', 'performed_at', 'performed_by',
        'description', 'components_replaced', 'cost', 'status', 'document_path', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'date',
        'performed_at' => 'date',
        'cost'         => 'decimal:2',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
