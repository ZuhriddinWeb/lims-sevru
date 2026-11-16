<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** [Paket 34] Buyurtmachi bo'linmasi xodimi (kim olib keladi) — F.I.Sh. va telefon */
class CustomerContact extends Model
{
    protected $fillable = [
        'customer_department_id', 'full_name', 'name_key', 'phone',
        'use_count', 'last_used_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'use_count'    => 'integer',
        'last_used_at' => 'datetime',
    ];
}
