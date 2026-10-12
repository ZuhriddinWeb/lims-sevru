<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tekshiruv yo'llanmasi. Ikkala serverda ham bir xil uuid va raqam bilan saqlanadi:
 *   out — biz jo'natdik (proba bizniki), in — bizga kelgan (biz tekshiruvchimiz).
 *
 * Holatlar: sending → sent → accepted → completed
 *                          ↘ rejected     ↘ cancelled (completed'gacha)
 */
class InterlabReferral extends Model
{
    public const STATUSES = ['sending', 'sent', 'accepted', 'rejected', 'completed', 'cancelled'];
    public const OPEN = ['sending', 'sent', 'accepted'];

    protected $fillable = [
        'uuid', 'number', 'direction', 'peer_id', 'status',
        'sample_id', 'sample_code', 'sample_number', 'hide_code', 'sample_info',
        'elements', 'description', 'note', 'deadline', 'tolerance_pct',
        'sender_name', 'sender_position', 'sender_lab', 'group_id', 'created_by',
        'sent_at', 'delivered_at', 'accepted_at', 'accepted_by_name', 'rejected_at', 'reject_reason',
        'completed_at', 'result_revision', 'result_note', 'revision_reason', 'performer_name',
        'cancelled_at', 'cancel_reason', 'reviewed_at', 'reviewed_by_name', 'last_event_at',
    ];

    protected $casts = [
        'peer_id'         => 'integer',
        'sample_id'       => 'integer',
        'group_id'        => 'integer',
        'created_by'      => 'integer',
        'result_revision' => 'integer',
        'hide_code'       => 'boolean',
        'sample_info'     => 'array',
        'elements'        => 'array',
        'deadline'        => 'date:Y-m-d',
        'tolerance_pct'   => 'float',
        'sent_at'         => 'datetime',
        'delivered_at'    => 'datetime',
        'accepted_at'     => 'datetime',
        'rejected_at'     => 'datetime',
        'completed_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
        'reviewed_at'     => 'datetime',
        'last_event_at'   => 'datetime',
    ];

    public function peer(): BelongsTo
    {
        return $this->belongsTo(InterlabPeer::class, 'peer_id');
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class)->withTrashed();
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LaboratoryGroup::class, 'group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function results(): HasMany
    {
        return $this->hasMany(InterlabResult::class, 'referral_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InterlabMessage::class, 'referral_uuid', 'uuid');
    }

    public function isOutgoing(): bool
    {
        return $this->direction === 'out';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
