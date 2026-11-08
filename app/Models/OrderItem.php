<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;

/** Partida de una comanda, con copia de nombre, precio y modificadores (Menu, sección 5). */
class OrderItem extends Document
{
    use HasVersion;

    protected $table = 'order_items';

    protected $hidden = ['fiscal_snapshot'];

    protected $casts = [
        'branch_id' => ObjectId::class,
        'order_id' => ObjectId::class,
        'product_id' => ObjectId::class,
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
    ];
}
