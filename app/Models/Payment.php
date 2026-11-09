<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Pago de una cuenta (P-2): el efectivo se confirma al crear; tarjeta y transferencia esperan su resultado. Nunca guarda número de tarjeta ni CVV (P-10). */
class Payment extends Document
{
    use HasVersion;

    public const PENDING = 'PENDING';

    public const CONFIRMED = 'CONFIRMED';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    public const UNKNOWN = 'UNKNOWN';

    protected $table = 'payments';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'check_id' => ObjectId::class,
        'cash_shift_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'reconciled_by' => ObjectId::class,
        'confirmed_at' => 'datetime',
    ];
}
