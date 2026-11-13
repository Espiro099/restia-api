<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Caja de una sucursal (CA-1). No se desactiva con un turno activo. */
class CashRegister extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $table = 'cash_registers';

    protected $casts = ['branch_id' => ObjectId::class];
}
