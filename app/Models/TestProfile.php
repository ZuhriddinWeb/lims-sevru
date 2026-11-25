<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TestProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name_ru', 'name_uz', 'name_en', 'object_type',
        'laboratory_id', 'is_default', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function elements(): BelongsToMany
    {
        return $this->belongsToMany(Element::class, 'test_profile_element');
    }

    /** [P42] Mas'ul guruhlar — tartib bo'yicha (1-si sukut bo'yicha tanlanadi) */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(LaboratoryGroup::class, 'test_profile_groups')
            ->withPivot('sort_order')->withTimestamps()->orderBy('test_profile_groups.sort_order');
    }

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }
}
