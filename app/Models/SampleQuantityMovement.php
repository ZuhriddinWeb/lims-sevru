<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Proba miqdorining qo'lda yoziladigan harakati.
 *   other      — boshqa sarf (masalan, namlik, qo'shimcha tekshiruv)  → delta < 0
 *   return     — buyurtmachiga qaytarildi                             → delta < 0
 *   dispose    — utilizatsiya / hisobdan chiqarildi                   → delta < 0
 *   correction — tuzatish (qayta tortish natijasi)                    → delta ±
 * Ishchi varaqlardagi sarf bu jadvalga yozilmaydi — varaqdan to'g'ridan-to'g'ri hisoblanadi.
 */
class SampleQuantityMovement extends Model
{
    use SoftDeletes;

    public const KINDS = ['other', 'return', 'dispose', 'correction'];
    public const OUTGOING = ['other', 'return', 'dispose'];

    protected $fillable = ['sample_id', 'kind', 'delta', 'unit', 'note', 'created_by'];

    protected $casts = [
        'id'         => 'integer',
        'sample_id'  => 'integer',
        'delta'      => 'float',
        'created_by' => 'integer',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
