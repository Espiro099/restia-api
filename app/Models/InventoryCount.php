<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Conteo físico (I-7): OPEN, APPLIED o CANCELLED; uno solo OPEN por sucursal. */
class InventoryCount extends Document
{
    use HasVersion;

    public const OPEN = 'OPEN';

    public const APPLIED = 'APPLIED';

    public const CANCELLED = 'CANCELLED';

    public const STATUSES = [self::OPEN, self::APPLIED, self::CANCELLED];

    protected $table = 'inventory_counts';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'applied_by' => ObjectId::class,
        'cancelled_by' => ObjectId::class,
        'applied_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];
}
