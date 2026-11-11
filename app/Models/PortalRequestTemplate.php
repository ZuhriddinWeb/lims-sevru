<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [Paket 33] Buyurtmachi bo'linmasining buyurtma shablonlari.
 * Shablon faqat o'z bo'linmasiga ko'rinadi (boshqa bo'linma o'zi uchun yaratadi).
 * payload — JSON: laboratory_group_id, delivery_shift_id, contact_name, contact_phone, notes, items[].
 */
class PortalRequestTemplate extends Model
{
    protected $fillable = ['customer_department_id', 'name', 'sort_order', 'payload', 'created_by', 'updated_by'];

    protected $casts = [
        'customer_department_id' => 'integer',
        'sort_order'             => 'integer',
        'payload'                => 'array',
    ];

    public function department()
    {
        return $this->belongsTo(CustomerDepartment::class, 'customer_department_id');
    }
}
