<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Unidad de una partida: cada platillo tiene su estado (sección 3 de Orders). */
class OrderItemUnit extends Document
{
    use HasVersion;

    public const PENDING = 'PENDING';

    public const PREPARING = 'PREPARING';

    public const READY = 'READY';

    public const DELIVERED = 'DELIVERED';

    public const CANCELLED = 'CANCELLED';

    public const VOIDED = 'VOIDED';

    protected $table = 'order_item_units';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'order_id' => ObjectId::class,
        'order_item_id' => ObjectId::class,
        'station_id' => ObjectId::class,
        'unit_number' => 'integer',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'voided_at' => 'datetime',
    ];
}
