<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Producto del menú de una sucursal (M-1). Precio con impuestos incluidos (M-7). */
class Product extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $table = 'products';

    protected $hidden = ['fiscal', 'current_recipe_version'];

    protected $casts = [
        'branch_id' => ObjectId::class,
        'price' => 'decimal:2',
        'display_order' => 'integer',
    ];
}
