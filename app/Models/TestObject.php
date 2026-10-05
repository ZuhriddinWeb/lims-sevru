<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TestObject extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'identifier', 'type', 'name', 'code', 'batch', 'description',
        'physical_characteristics', 'chemical_composition', 'source_supplier',
        'received_at', 'storage_conditions', 'transport_conditions',
        'accompanying_documents', 'status', 'storage_location', 'storage_expiry',
        'disposed_at', 'disposal_act', 'laboratory_id', 'test_profile_id', 'created_by',
    ];

    protected $casts = [
        'received_at'    => 'date',
        'storage_expiry' => 'date',
        'disposed_at'    => 'date',
    ];

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }

    public function elements(): BelongsToMany
    {
        return $this->belongsToMany(Element::class, 'test_object_element');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TestProfile::class, 'test_profile_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TestObjectDocument::class)->latest();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
