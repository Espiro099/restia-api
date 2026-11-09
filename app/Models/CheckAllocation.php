<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/** Unidad asignada a una cuenta. Mover una unidad cierra su asignación y crea otra (D-16). */
class CheckAllocation extends Document
{
    protected $table = 'check_allocations';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'check_id' => ObjectId::class,
        'order_item_unit_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'closed_at' => 'datetime',
    ];
}
