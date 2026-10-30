<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laboratoriya boshlig'i chiqargan davr: shu paytdagi tasdiqlangan natijalar nusxasi (payload).
 * Hech qachon o'zgartirilmaydi — tuzatish bo'lsa yangi tahrir (revision) yoziladi. [Paket 29]
 */
class SvodkaRelease extends Model
{
    protected $fillable = [
        'customer_department_id', 'production_date', 'shift', 'revision', 'payload', 'samples_count', 'values_count',
        'released_by', 'released_at', 'note',
    ];

    protected $casts = [
        'id'                     => 'integer',
        'customer_department_id' => 'integer',
        'production_date'        => 'date',
        'shift'                  => 'integer',
        'revision'               => 'integer',
        'payload'                => 'array',
        'samples_count'          => 'integer',
        'values_count'           => 'integer',
        'released_by'            => 'integer',
        'released_at'            => 'datetime',
    ];

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by')->withTrashed();
    }
}
