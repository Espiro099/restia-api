<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/**
 * Aviso para un empleado en una sucursal: orden lista, cuenta solicitada, resultado de un pago o stock bajo.
 * Lo crea el consumidor de outbox (App\Notifications\Notifications); solo cambia `read_at`.
 */
class Notification extends Document
{
    public const ORDER_READY = 'ORDER_READY';

    public const CHECK_REQUESTED = 'CHECK_REQUESTED';

    public const PAYMENT_CONFIRMED = 'PAYMENT_CONFIRMED';

    public const PAYMENT_FAILED = 'PAYMENT_FAILED';

    public const PAYMENT_UNKNOWN = 'PAYMENT_UNKNOWN';

    public const LOW_STOCK = 'LOW_STOCK';

    protected $table = 'notifications';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'user_id' => ObjectId::class,
        'session_id' => ObjectId::class,
        'check_id' => ObjectId::class,
        'read_at' => 'datetime',
        'expires_at' => 'datetime',
        'count' => 'integer',
        'table_number' => 'integer',
    ];
}
