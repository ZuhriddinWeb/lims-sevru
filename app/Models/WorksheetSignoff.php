<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorksheetSignoff extends Model
{
    protected $fillable = [
        'worksheet_id', 'stage', 'slot', 'performer_id', 'performer_name', 'performed_at', 'recorded_by',
    ];

    protected $casts = [
        'performed_at' => 'date:Y-m-d',
        'slot'         => 'integer',
        'worksheet_id' => 'integer',
        'performer_id' => 'integer',
        'recorded_by'  => 'integer',
    ];

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performer_id')->withTrashed();
    }
}
