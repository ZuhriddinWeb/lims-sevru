<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bo'lim uchun svodka rejimi (laboratoriya yoqadi). [Paket 29] */
class SvodkaSetting extends Model
{
    protected $fillable = [
        'customer_department_id', 'enabled', 'release_mode', 'shift1_label', 'shift2_label',
        'shift1_start', 'shift2_start', 'title', 'updated_by',
    ];

    protected $casts = [
        'id'                     => 'integer',
        'customer_department_id' => 'integer',
        'enabled'                => 'boolean',
        'updated_by'             => 'integer',
    ];

    public const DEFAULTS = [
        'enabled' => false, 'release_mode' => 'day',
        'shift1_label' => 'I-navbatchilik', 'shift2_label' => 'II-navbatchilik',
        'shift1_start' => '20:00', 'shift2_start' => '08:00', 'title' => null,
    ];

    /** Bo'lim sozlamasi; yo'q bo'lsa — standart (saqlanmagan) nusxa */
    public static function forDepartment(int $deptId): self
    {
        return static::where('customer_department_id', $deptId)->first()
            ?? new static(['customer_department_id' => $deptId] + self::DEFAULTS);
    }
}
