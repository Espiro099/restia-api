<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/**
 * Trabajo de impresión (sección 14 del documento maestro). `document` es una copia inmutable del
 * contenido; una reimpresión crea otro trabajo con `original_job_id`.
 */
class PrintJob extends Document
{
    use HasVersion;

    public const PENDING = 'PENDING';

    public const CLAIMED = 'CLAIMED';

    public const PRINTING = 'PRINTING';

    public const PRINTED = 'PRINTED';

    public const FAILED = 'FAILED';

    public const UNKNOWN = 'UNKNOWN';

    public const CANCELLED = 'CANCELLED';

    /** Prioridad de cada tipo (PR-3) y su orden al reclamar. */
    public const PRIORITY = [
        'KITCHEN_TICKET' => 'HIGH', 'VOID_NOTICE' => 'HIGH', 'PRE_CHECK' => 'NORMAL',
        'PAYMENT_RECEIPT' => 'NORMAL', 'CASH_SHIFT_REPORT' => 'LOW', 'TEST' => 'LOW',
    ];

    public const RANK = ['HIGH' => 0, 'NORMAL' => 1, 'LOW' => 2];

    protected $table = 'print_jobs';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'printer_id' => ObjectId::class,
        'print_station_id' => ObjectId::class,
        'station_id' => ObjectId::class,
        'source_id' => ObjectId::class,
        'original_job_id' => ObjectId::class,
        'claimed_agent_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'next_attempt_at' => 'datetime',
        'lease_until' => 'datetime',
        'expires_at' => 'datetime',
        'printed_at' => 'datetime',
    ];
}
