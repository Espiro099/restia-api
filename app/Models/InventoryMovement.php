<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/** Movimiento de inventario (I-5). Inmutable: un error se corrige con otro movimiento. */
class InventoryMovement extends Document
{
    public const CONSUMPTION = 'CONSUMPTION';

    public const TYPES = ['CONSUMPTION', 'RECEIPT', 'ADJUSTMENT', 'WASTE', 'COUNT_ADJUSTMENT'];

    protected $table = 'inventory_movements';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'ingredient_id' => ObjectId::class,
        'source_id' => ObjectId::class,
        'approved_by' => ObjectId::class,
        'created_by' => ObjectId::class,
        'projected_at' => 'datetime',
    ];
}
