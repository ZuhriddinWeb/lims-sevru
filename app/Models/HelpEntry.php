<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpEntry extends Model
{
    protected $table = 'help_entries';

    protected $guarded = [];

    protected $casts = [
        'is_top' => 'boolean',
        'is_page' => 'boolean',
        'is_active' => 'boolean',
        'is_custom' => 'boolean',
        'is_edited' => 'boolean',
        'keywords' => 'array',
        'alt_uz' => 'array',
        'alt_ru' => 'array',
        'alt_en' => 'array',
        
    ];
}
