<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = ['id' => 'integer', 'updated_by' => 'integer'];
}
