<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ulangan boshqa laboratoriya.
 *   pending_out — biz ulanish so'rovini yubordik, ular tasdiqlashini kutyapmiz
 *   pending_in  — ular so'rov yubordi, bizning admin tasdiqlashi kerak
 *   active      — ikki tomon tasdiqlagan, xabar almashish mumkin
 *   rejected    — so'rov rad etilgan
 *   disabled    — vaqtincha o'chirilgan (xabar qabul qilinmaydi va yuborilmaydi)
 */
class InterlabPeer extends Model
{
    public const STATUSES = ['pending_out', 'pending_in', 'active', 'rejected', 'disabled'];

    /** Shuncha vaqt ichida javob bergan bo'lsa — «onlayn» */
    public const ONLINE_WINDOW_SECONDS = 300;

    protected $fillable = [
        'node_id', 'name', 'code', 'base_url', 'enc_public', 'sign_public', 'fingerprint',
        'status', 'approved_by', 'approved_at', 'last_seen_at', 'last_ok_at', 'last_ping_at',
        'last_error', 'last_error_at', 'notes',
    ];

    protected $casts = [
        'approved_by'   => 'integer',
        'approved_at'   => 'datetime',
        'last_seen_at'  => 'datetime',
        'last_ok_at'    => 'datetime',
        'last_ping_at'  => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected $appends = ['online'];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Onlayn: oxirgi muvaffaqiyatli aloqa (biz ularga yetdik yoki ular bizga murojaat qildi)
     * yaqinda bo'lgan va undan keyin xato bo'lmagan.
     */
    public function getOnlineAttribute(): bool
    {
        $contact = collect([$this->last_ok_at, $this->last_seen_at])->filter()->max();
        if (! $contact || $contact->lte(now()->subSeconds(self::ONLINE_WINDOW_SECONDS))) {
            return false;
        }

        return $this->last_error_at === null || $this->last_error_at->lte($contact);
    }

    /** Xavfsiz ko'rinish (kalitlarsiz) */
    public function toPublicArray(): array
    {
        return [
            'id'           => $this->id,
            'node_id'      => $this->node_id,
            'name'         => $this->name,
            'code'         => $this->code,
            'base_url'     => $this->base_url,
            'fingerprint'  => $this->fingerprint,
            'status'       => $this->status,
            'online'       => $this->online,
            'approved_at'  => $this->approved_at,
            'last_seen_at' => $this->last_seen_at,
            'last_ok_at'   => $this->last_ok_at,
            'last_error'   => $this->last_error,
            'last_error_at'=> $this->last_error_at,
            'notes'        => $this->notes,
        ];
    }
}
