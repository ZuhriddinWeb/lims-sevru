<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** [Paket 39] Buyurtmani yopish sababi («Texnologik jarayon to'xtagan», «Boshqa» …) */
class CloseReason extends Model
{
    protected $fillable = ['name_uz', 'name_ru', 'name_en', 'needs_note', 'sort_order', 'is_active'];

    protected $casts = [
        'id'         => 'integer',
        'needs_note' => 'boolean',
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    public function label(string $locale = 'uz'): string
    {
        return ($locale === 'ru' ? $this->name_ru : ($locale === 'en' ? $this->name_en : null)) ?: $this->name_uz;
    }
}
