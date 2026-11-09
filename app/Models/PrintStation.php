<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Ruta de impresión (PR-2): une un destino, estación de producción o tipo de documento, con una impresora. */
class PrintStation extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    /** Tipos que salen solos desde outbox y necesitan una estación de producción (PR-3). */
    public const STATION_TYPES = ['KITCHEN_TICKET', 'VOID_NOTICE'];

    public const TYPES = ['KITCHEN_TICKET', 'VOID_NOTICE', 'PRE_CHECK', 'PAYMENT_RECEIPT', 'CASH_SHIFT_REPORT', 'TEST'];

    protected $table = 'print_stations';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'station_id' => ObjectId::class,
        'printer_id' => ObjectId::class,
    ];
}
