<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;

/** Programa local que imprime (PR-1). Se autentica con una identidad de servicio PRINTING_AGENT. */
class PrintAgent extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $table = 'print_agents';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'service_identity_id' => ObjectId::class,
        'last_seen_at' => 'datetime',
    ];
}
