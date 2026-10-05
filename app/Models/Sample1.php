<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sample extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'sample_number', 'source_document', 'source_division',
        'customer_group_id', 'registering_lab_id', 'registering_group_id',
        'assigned_group_id', 'state_of_matter', 'status', 'period_month',
        'registered_by', 'registered_at', 'assigned_at', 'completed_at', 'notes',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'assigned_at'   => 'datetime',
        'completed_at'  => 'datetime',
    ];

    protected $appends = ['progress'];

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(LaboratoryGroup::class, 'customer_group_id');
    }

    public function registeringLab(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class, 'registering_lab_id');
    }

    public function registeringGroup(): BelongsTo
    {
        return $this->belongsTo(LaboratoryGroup::class, 'registering_group_id');
    }

    public function assignedGroup(): BelongsTo
    {
        return $this->belongsTo(LaboratoryGroup::class, 'assigned_group_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function sampleElements(): HasMany
    {
        return $this->hasMany(SampleElement::class);
    }

    // Natijalar bajarilishi foizi
    public function getProgressAttribute(): int
    {
        $total = $this->sampleElements->count();
        if ($total === 0) {
            return 0;
        }
        $done = $this->sampleElements->where('status', 'done')->count();
        return (int) round($done / $total * 100);
    }

    // Natija kiritilganda umumiy statusni yangilash
    public function refreshStatus(): void
    {
        $this->loadMissing('sampleElements');
        $total = $this->sampleElements->count();
        $done  = $this->sampleElements->where('status', 'done')->count();

        if ($total > 0 && $done === $total) {
            $this->status = 'completed';
            $this->completed_at = $this->completed_at ?: now();
        } elseif ($done > 0) {
            $this->status = 'in_progress';
        }
        $this->save();
    }
}
