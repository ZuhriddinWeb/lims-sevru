<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Laboratory extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name_ru', 'name_uz', 'name_en', 'description',
        'is_active', 'sort_order',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function groups(): HasMany
    {
        return $this->hasMany(LaboratoryGroup::class)->orderBy('sort_order');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }
}
