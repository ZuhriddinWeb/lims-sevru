<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Buyurtma so'rovi (qoralama buyurtma). Proba emas — qabul qilingach probalar yaratiladi.
 */
class SampleRequest extends Model
{
    public const STATUSES = ['draft', 'submitted', 'partial', 'received', 'rejected', 'cancelled', 'expired'];
    /** Laboratoriya kutayotgan holatlar (namuna hali to'liq kelmagan) */
    public const WAITING = ['submitted', 'partial', 'expired'];

    protected $fillable = [
        'number', 'customer_department_id', 'origin', 'status', 'delivery_date', 'contact_name', 'contact_phone',
        'notes', 'created_by', 'updated_by', 'submitted_at', 'submitted_by', 'expires_at', 'received_at',
        'received_by', 'handed_over_by', 'closed_at', 'closed_by', 'close_reason',
        'delivery_shift_id', 'delivery_shift', // [P28] qaysi smenada olib kelinadi
        'svodka_date', 'svodka_shift',          // [P29] namuna olingan sana va navbatchilik (buyurtmachi svodkasi uchun)
        'laboratory_group_id',                  // [P30] buyurtmachi tanlagan laboratoriya guruhi
        'handed_over_phone',                    // [P33] olib kelgan shaxs telefoni (qabulda)
        'scheduled_at', 'late_entry', 'received_entered_at', 'close_reason_id', 'auto_closed', // [P39]
        'split_key', 'schedule_run_id',         // [P42] guruhlar bo'yicha bo'lingan; grafik partiyasi
    ];

    // SQL Server butun sonlarni satr qilib qaytaradi — JSON'da doim son bo'lsin
    protected $casts = [
        'id'                     => 'integer',
        'customer_department_id' => 'integer',
        'created_by'             => 'integer',
        'updated_by'             => 'integer',
        'submitted_by'           => 'integer',
        'received_by'            => 'integer',
        'closed_by'              => 'integer',
        'delivery_shift_id'      => 'integer',
        'svodka_date'            => 'date',
        'svodka_shift'           => 'integer',
        'laboratory_group_id'    => 'integer',
        'delivery_date'          => 'date',
        'submitted_at'           => 'datetime',
        'expires_at'             => 'datetime',
        'received_at'            => 'datetime',
        'closed_at'              => 'datetime',
        'scheduled_at'           => 'datetime', // [P39] grafik vaqti
        'received_entered_at'    => 'datetime', // [P39] qabul dasturga kiritilgan vaqt
        'late_entry'             => 'boolean',
        'auto_closed'            => 'boolean',
        'close_reason_id'        => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SampleRequestItem::class)->orderBy('position')->orderBy('id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(CustomerDepartment::class, 'customer_department_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed();
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    /** [P39] yopish sababi */
    public function closeReason(): BelongsTo
    {
        return $this->belongsTo(CloseReason::class, 'close_reason_id');
    }

    /** [P30] Namunani qabul qiladigan laboratoriya guruhi */
    public function group(): BelongsTo
    {
        return $this->belongsTo(LaboratoryGroup::class, 'laboratory_group_id');
    }
}
