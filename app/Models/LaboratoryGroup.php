<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'laboratory_id', 'code', 'name_ru', 'name_uz', 'name_en',
        'kind', 'is_active', 'sort_order',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }
}
