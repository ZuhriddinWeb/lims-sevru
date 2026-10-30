<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Buyurtmachi spravochnigi: svodka bloki va uning ustunlari. Laboratoriyaga ko'rinmaydi. [Paket 29] */
class SvodkaSection extends Model
{
    public const LAYOUTS = ['shift', 'day', 'list'];

    protected $fillable = [
        'customer_department_id', 'title', 'layout', 'columns', 'sort_order', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'id'                     => 'integer',
        'customer_department_id' => 'integer',
        'columns'                => 'array',
        'sort_order'             => 'integer',
        'is_active'              => 'boolean',
    ];

    public function points(): HasMany
    {
        return $this->hasMany(SvodkaPoint::class, 'section_id')->orderBy('sort_order')->orderBy('id');
    }
}
