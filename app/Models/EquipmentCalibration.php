<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentCalibration extends Model
{
    use HasFactory;

    protected $fillable = [
        'equipment_id', 'type', 'performed_at', 'performed_by', 'result',
        'certificate_number', 'valid_until', 'document_path', 'notes', 'created_by',
    ];

    protected $casts = [
        'performed_at' => 'date',
        'valid_until'  => 'date',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
