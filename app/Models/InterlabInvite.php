<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir martalik ulanish kodi. Kodning o'zi bazada shifrlangan holda saqlanadi
 * va faqat yaratilgan paytda bir marta ko'rsatiladi.
 */
class InterlabInvite extends Model
{
    protected $fillable = [
        'lookup', 'secret', 'note', 'expires_at', 'created_by',
        'used_at', 'used_by_peer_id', 'used_by_name', 'used_ip', 'revoked_at',
    ];

    protected $hidden = ['secret', 'lookup'];

    protected $casts = [
        'secret'          => 'encrypted',
        'expires_at'      => 'datetime',
        'used_at'         => 'datetime',
        'revoked_at'      => 'datetime',
        'created_by'      => 'integer',
        'used_by_peer_id' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** active | used | expired | revoked */
    public function state(): string
    {
        if ($this->used_at) {
            return 'used';
        }
        if ($this->revoked_at) {
            return 'revoked';
        }

        return $this->expires_at->isPast() ? 'expired' : 'active';
    }

    public function toPublicArray(): array
    {
        return [
            'id'           => $this->id,
            'note'         => $this->note,
            'state'        => $this->state(),
            'expires_at'   => $this->expires_at,
            'created_at'   => $this->created_at,
            'created_by'   => $this->creator?->name,
            'used_at'      => $this->used_at,
            'used_by_name' => $this->used_by_name,
            'used_ip'      => $this->used_ip,
            'revoked_at'   => $this->revoked_at,
        ];
    }
}
