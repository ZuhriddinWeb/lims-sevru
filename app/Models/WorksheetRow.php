<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorksheetRow extends Model
{
    protected $fillable = [
        'worksheet_id', 'row_no', 'inputs', 'computed', 'excluded', 'note',
        'sample_id', 'parallel_no', 'is_extra', 'added_by',
    ];

    protected $casts = [
        'inputs'   => 'array',
        'computed' => 'array',
        'worksheet_id' => 'integer',
        'added_by'    => 'integer',
        'excluded'    => 'boolean',
        'row_no'      => 'integer',
        'sample_id'   => 'integer',
        'parallel_no' => 'integer',
        'is_extra'    => 'boolean',
    ];

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    /** Qo'shimcha parallelni qo'shgan foydalanuvchi */
    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by')->withTrashed();
    }
}
