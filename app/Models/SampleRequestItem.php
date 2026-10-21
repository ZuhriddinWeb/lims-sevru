<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SampleRequestItem extends Model
{
    protected $fillable = [
        'sample_request_id', 'position', 'customer_code', 'state_of_matter', 'test_profile_id', 'element_ids',
        'quantity', 'quantity_unit', 'notes', 'status', 'sample_id',
    ];

    protected $casts = [
        'id'                => 'integer',
        'sample_request_id' => 'integer',
        'position'          => 'integer',
        'test_profile_id'   => 'integer',
        'sample_id'         => 'integer',
        'element_ids'       => 'array',
        'quantity'          => 'float',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(SampleRequest::class, 'sample_request_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TestProfile::class, 'test_profile_id');
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class)->withTrashed();
    }

    /** Aniqlanadigan elementlar: tanlanganlar, bo'lmasa — profildagilar */
    public function resolvedElementIds(): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($this->element_ids ?? []))));
        if ($ids === [] && $this->test_profile_id) {
            $ids = TestProfile::find($this->test_profile_id)?->elements()->pluck('elements.id')->map(fn ($v) => (int) $v)->all() ?? [];
        }

        return $ids;
    }
}
