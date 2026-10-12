<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ishchi varaq — partiya: bitta yoki bir nechta proba (worksheet_samples).
 * sample_id — partiyadagi birinchi proba (eski varaqlar va tez qidiruv uchun).
 */
class Worksheet extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'printed', 'in_progress', 'completed', 'approved'];

    protected $fillable = [
        'template_id', 'sample_id', 'series', 'year', 'seq', 'number',
        'template_snapshot', 'worksheet_date', 'status', 'header_values', 'results',
        'has_warnings', 'notes', 'created_by', 'updated_by', 'printed_at', 'completed_at',
        'approved_by', 'approved_at', 'results_pushed_at',
    ];

    // SQL Server butun sonlarni satr qilib qaytaradi — JSON'da doim son bo'lsin
    protected $casts = [
        'template_id'       => 'integer',
        'sample_id'         => 'integer',
        'year'              => 'integer',
        'seq'               => 'integer',
        'created_by'        => 'integer',
        'updated_by'        => 'integer',
        'approved_by'       => 'integer',
        'template_snapshot' => 'array',
        'header_values'     => 'array',
        'results'           => 'array',
        'has_warnings'      => 'boolean',
        'worksheet_date'    => 'date:Y-m-d',
        'printed_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'approved_at'       => 'datetime',
        'results_pushed_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorksheetTemplate::class, 'template_id')->withTrashed();
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class)->withTrashed();
    }

    /**
     * Partiyadagi probalar (pivot yozuvlari).
     * DIQQAT: bu yerda orderBy yo'q — SQL Server count()/exists() so'rovlarida ORDER BY'ni
     * qabul qilmaydi. Tartib kerak joyda ->orderBy('position') alohida yoziladi.
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorksheetSample::class);
    }

    public function samples(): BelongsToMany
    {
        return $this->belongsToMany(Sample::class, 'worksheet_samples')
            ->withPivot(['id', 'position', 'parallels', 'status', 'retest_reason', 'retest_by', 'retest_at', 'result_pushed_at'])
            ->withTimestamps()
            ->withTrashed();
    }

    public function rows(): HasMany
    {
        return $this->hasMany(WorksheetRow::class)->orderBy('row_no')->orderBy('id');
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(WorksheetSignoff::class)->orderBy('stage')->orderBy('slot');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(WorksheetScan::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function isLocked(): bool
    {
        return $this->status === 'approved';
    }

    /** Hisoblagich uchun ta'rif — doim yaratilgan paytdagi nusxa */
    public function definition(): array
    {
        return $this->template_snapshot ?? [];
    }
}
