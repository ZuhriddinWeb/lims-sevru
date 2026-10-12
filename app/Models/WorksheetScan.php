<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorksheetScan extends Model
{
    protected $fillable = ['worksheet_id', 'path', 'original_name', 'mime', 'size', 'sha256', 'uploaded_by'];

    protected $hidden = ['path'];

    protected $casts = ['size' => 'integer'];

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
