<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Xabar: chiquvchi (out) navbat va kiruvchi (in) jurnal.
 *   out: pending → sending → sent   (xato bo'lsa: pending + next_attempt_at, yoki failed)
 *   in:  processed | rejected
 */
class InterlabMessage extends Model
{
    protected $fillable = [
        'uuid', 'peer_id', 'peer_node_id', 'direction', 'type', 'referral_uuid',
        'body', 'envelope', 'status', 'attempts', 'next_attempt_at',
        'sent_at', 'processed_at', 'last_error',
    ];

    protected $hidden = ['envelope'];

    protected $casts = [
        'peer_id'         => 'integer',
        'attempts'        => 'integer',
        'body'            => 'array',
        'next_attempt_at' => 'datetime',
        'sent_at'         => 'datetime',
        'processed_at'    => 'datetime',
    ];

    public function peer(): BelongsTo
    {
        return $this->belongsTo(InterlabPeer::class, 'peer_id');
    }
}
