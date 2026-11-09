<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Grupo de modificadores con opciones internas (M-3). Las opciones se desactivan, nunca se borran. */
class Modifier extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $table = 'modifiers';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'min_selections' => 'integer',
        'max_selections' => 'integer',
    ];
}
