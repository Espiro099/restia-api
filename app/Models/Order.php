<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;

/** Comanda: una ronda (O-2). `status` se deriva de sus unidades (O-1). */
class Order extends Document
{
    use HasVersion;

    public const OPEN = 'OPEN';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'orders';

    protected $hidden = ['request_hash'];

    protected $casts = [
        'branch_id' => ObjectId::class,
        'session_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'total' => 'decimal:2',
        'captured_at' => 'datetime',
    ];
}
