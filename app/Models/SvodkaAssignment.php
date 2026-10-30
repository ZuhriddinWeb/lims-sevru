<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Namuna ↔ nuqta (buyurtmachi qo'lda bog'lagani). Laboratoriyaga ko'rinmaydi. [Paket 29] */
class SvodkaAssignment extends Model
{
    protected $fillable = ['customer_department_id', 'sample_id', 'point_id', 'production_date', 'shift', 'assigned_by'];

    protected $casts = [
        'id'                     => 'integer',
        'customer_department_id' => 'integer',
        'sample_id'              => 'integer',
        'point_id'               => 'integer',
        'production_date'        => 'date',
        'shift'                  => 'integer',
        'assigned_by'            => 'integer',
    ];
}
