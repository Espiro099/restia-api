<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/** Cortesía de una unidad completa (C-7). Revertirla la deja con `active = false`. */
class CheckAdjustment extends Document
{
    public const COURTESY = 'COURTESY';

    protected $table = 'check_adjustments';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'check_id' => ObjectId::class,
        'order_item_unit_id' => ObjectId::class,
        'approved_by' => ObjectId::class,
        'created_by' => ObjectId::class,
        'reverted_at' => 'datetime',
    ];
}
