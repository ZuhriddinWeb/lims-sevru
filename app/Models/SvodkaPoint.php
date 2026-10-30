<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Buyurtmachi spravochnigi: namuna nomi (nuqta). Laboratoriyaga ko'rinmaydi. [Paket 29] */
class SvodkaPoint extends Model
{
    protected $fillable = ['customer_department_id', 'section_id', 'name', 'sort_order', 'daily', 'is_active'];

    protected $casts = [
        'id'                     => 'integer',
        'customer_department_id' => 'integer',
        'section_id'             => 'integer',
        'sort_order'             => 'integer',
        'daily'                  => 'boolean',
        'is_active'              => 'boolean',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(SvodkaSection::class, 'section_id');
    }
}
