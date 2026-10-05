<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SampleElement extends Model
{
    use HasFactory;

    protected $fillable = [
        'sample_id', 'element_id', 'equipment_id', 'result_value', 'result_unit',
        'method', 'status', 'result_entered_by', 'result_entered_at', 'notes',
    ];

    protected $casts = [
        'result_entered_at' => 'datetime',
        'result_value'      => 'decimal:6',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    public function element(): BelongsTo
    {
        return $this->belongsTo(Element::class);
    }

    // TZ 1.8 — natija qaysi uskunada olingani
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function resultEnteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'result_entered_by');
    }
}
