<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Partiya varaqdagi bitta proba.
 *   status: active — natija hisoblanadi va tasdiqlashda probaga o'tadi
 *           retest — «takroriy tahlil»: natija o'tmaydi, proba navbatga qaytadi
 */
class WorksheetSample extends Model
{
    public const STATUSES = ['active', 'retest'];

    protected $fillable = [
        'worksheet_id', 'sample_id', 'position', 'parallels', 'status',
        'retest_reason', 'retest_by', 'retest_at', 'result_pushed_at',
    ];

    // SQL Server butun sonlarni satr qilib qaytaradi — JSON'da doim son bo'lsin
    protected $casts = [
        'worksheet_id'     => 'integer',
        'sample_id'        => 'integer',
        'retest_by'        => 'integer',
        'position'         => 'integer',
        'parallels'        => 'integer',
        'retest_at'        => 'datetime',
        'result_pushed_at' => 'datetime',
    ];

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class)->withTrashed();
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class)->withTrashed();
    }

    public function retestUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retest_by')->withTrashed();
    }

    public function isRetest(): bool
    {
        return $this->status === 'retest';
    }
}
