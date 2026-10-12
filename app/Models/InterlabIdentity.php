<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shu laboratoriya (tugun). Jadvalda bitta yozuv bo'ladi.
 * Yopiq kalitlar Laravel Crypt (APP_KEY) bilan shifrlangan holda saqlanadi.
 */
class InterlabIdentity extends Model
{
    protected $table = 'interlab_identity';

    protected $fillable = [
        'node_id', 'name', 'code', 'base_url',
        'enc_public', 'enc_private', 'sign_public', 'sign_private',
        'fingerprint', 'last_sync_at',
    ];

    protected $hidden = ['enc_private', 'sign_private'];

    protected $casts = [
        'enc_private'  => 'encrypted',
        'sign_private' => 'encrypted',
        'last_sync_at' => 'datetime',
    ];
}
