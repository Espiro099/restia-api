<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/** Datos de un cliente en una sucursal: notas, preferencias y visitas. Sin perfil, la sucursal no lo ve. */
class CustomerBranchProfile extends Document
{
    protected $table = 'customer_branch_profiles';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'customer_id' => ObjectId::class,
        'visit_count' => 'integer',
        'last_visit_at' => 'datetime',
    ];
}
