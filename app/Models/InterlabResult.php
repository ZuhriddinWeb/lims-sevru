<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tekshiruv natijasi (element bo'yicha). Har bir yuborilgan tahrir (revision) alohida saqlanadi.
 * Tekshiruvchi tomonda is_draft=true — hali yuborilmagan qoralama.
 */
class InterlabResult extends Model
{
    protected $fillable = [
        'referral_id', 'revision', 'is_draft', 'symbol', 'value_text', 'value', 'unit',
        'method', 'notes', 'entered_by', 'entered_by_name', 'entered_at',
    ];

    protected $casts = [
        'referral_id' => 'integer',
        'revision'    => 'integer',
        'entered_by'  => 'integer',
        'is_draft'    => 'boolean',
        'value'       => 'float',
        'entered_at'  => 'datetime',
    ];

    public function referral(): BelongsTo
    {
        return $this->belongsTo(InterlabReferral::class, 'referral_id');
    }
}
