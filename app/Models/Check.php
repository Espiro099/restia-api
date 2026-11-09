<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/**
 * Cuenta de una sesión (D-16, D-17). Sus totales se recalculan en la misma transacción que cualquier
 * cambio de sus unidades (C-6). OPEN siempre significa sin pagos (C-3); REQUESTED no recibe unidades nuevas.
 */
class Check extends Document
{
    use HasVersion;

    public const OPEN = 'OPEN';

    public const REQUESTED = 'REQUESTED';

    public const PARTIALLY_PAID = 'PARTIALLY_PAID';

    public const PAID = 'PAID';

    public const CANCELLED = 'CANCELLED';

    public const REFUNDED = 'REFUNDED';

    protected $table = 'checks';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'session_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'requested_at' => 'datetime',
        'paid_at' => 'datetime',
    ];
}
