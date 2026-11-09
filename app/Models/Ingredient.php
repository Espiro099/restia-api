<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/**
 * Ingrediente de una sucursal (I-8), con unidad base fija. `stock_actual` es una proyección que un
 * proceso actualiza después de cada movimiento (I-1); la verdad está en inventory_movements.
 */
class Ingredient extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    public const UNITS = ['G', 'KG', 'ML', 'L', 'PIECE'];

    protected $table = 'ingredients';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'stock_as_of' => 'datetime',
    ];
}
