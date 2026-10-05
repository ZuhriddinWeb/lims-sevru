<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Element extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol', 'name_ru', 'name_uz', 'name_en',
        'default_unit', 'is_active', 'sort_order',
    ];

    protected $casts = ['is_active' => 'boolean'];
}
