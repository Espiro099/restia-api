<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Casts\ObjectId;

/** Estación alternativa de un producto, por prioridad (M-2, opción A). */
class ProductPreparationRoute extends Document
{
    protected $table = 'product_preparation_routes';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'product_id' => ObjectId::class,
        'station_id' => ObjectId::class,
        'priority' => 'integer',
    ];
}
