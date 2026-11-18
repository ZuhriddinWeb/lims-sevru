<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** [Paket 37] Grafik jadvalidagi kiritilgan qiymat (jadval × partiya × ustun) */
class ScheduleSheetValue extends Model
{
    protected $fillable = ['sheet_id', 'run_id', 'column_key', 'value', 'entered_by', 'entered_at'];

    protected $casts = ['entered_at' => 'datetime'];
}
