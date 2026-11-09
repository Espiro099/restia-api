<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Impresora de una sucursal, conectada a un agente (Decisiones_Printing, PR-1). */
class Printer extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $table = 'printers';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'agent_id' => ObjectId::class,
    ];
}
