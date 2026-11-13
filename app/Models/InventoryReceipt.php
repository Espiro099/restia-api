<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/** Recepción de mercancía (I-6). Inmutable, con proveedor en texto libre; un error se corrige con un ajuste. */
class InventoryReceipt extends Document
{
    protected $table = 'inventory_receipts';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'received_at' => 'datetime',
    ];
}
