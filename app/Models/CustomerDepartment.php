<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Buyurtmachi bo'lim — zavoddagi laboratoriyaga proba topshiradigan bo'lim (sex, uchastka, xizmat).
 * users — shu bo'lim nomidan portalga kiradigan «Buyurtmachi» foydalanuvchilari.
 */
class CustomerDepartment extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'contact_person', 'phone', 'notes', 'is_active', 'created_by'];

    // SQL Server butun sonlarni satr qilib qaytaradi — JSON'da doim son bo'lsin
    protected $casts = [
        'id'         => 'integer',
        'is_active'  => 'boolean',
        'created_by' => 'integer',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_department_user')->withTimestamps();
    }

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class, 'customer_department_id');
    }
}
