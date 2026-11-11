<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/** Reconstrucción manual de los reportes de un rango (RP-3). Solo una activa por sucursal. */
class ReportRebuild extends Document
{
    public const PENDING = 'PENDING';

    public const RUNNING = 'RUNNING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    protected $table = 'report_rebuilds';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'finished_at' => 'datetime',
    ];
}
