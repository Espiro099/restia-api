<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/** Depósito o retiro manual del cajón (CA-5). Inmutable: un error se corrige con otro movimiento. */
class CashMovement extends Document
{
    public const DEPOSIT = 'DEPOSIT';

    public const WITHDRAWAL = 'WITHDRAWAL';

    protected $table = 'cash_movements';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'cash_shift_id' => ObjectId::class,
        'created_by' => ObjectId::class,
    ];
}
