<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/**
 * Turno de una caja (CA-2): es de la caja, no de una persona. `active` vale true en OPEN y CLOSING (CA-12).
 * `activity_epoch` lo escriben los cobros y movimientos, sin tocar `version`, para chocar con el cierre.
 */
class CashShift extends Document
{
    use HasVersion;

    public const OPEN = 'OPEN';

    public const CLOSING = 'CLOSING';

    public const CLOSED = 'CLOSED';

    protected $table = 'cash_shifts';

    protected $hidden = ['activity_epoch'];

    protected $casts = [
        'branch_id' => ObjectId::class,
        'cash_register_id' => ObjectId::class,
        'opened_by' => ObjectId::class,
        'closed_by' => ObjectId::class,
        'opened_at' => 'datetime',
        'closing_started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
