<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpUnanswered extends Model
{
    protected $table = 'help_unanswered';

    protected $guarded = [];

    protected $casts = [
        'best_score' => 'float',
        'last_asked_at' => 'datetime',
    ];
}
