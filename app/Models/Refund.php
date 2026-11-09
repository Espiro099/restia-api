<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Reembolso de un pago completo (P-7), por el mismo método y en un turno abierto (P-9). FAILED deja de estar activo y permite otro intento. */
class Refund extends Document
{
    use HasVersion;

    public const PENDING = 'PENDING';

    public const CONFIRMED = 'CONFIRMED';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    public const UNKNOWN = 'UNKNOWN';

    protected $table = 'refunds';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'check_id' => ObjectId::class,
        'cash_shift_id' => ObjectId::class,
        'payment_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'reconciled_by' => ObjectId::class,
        'confirmed_at' => 'datetime',
    ];
}
