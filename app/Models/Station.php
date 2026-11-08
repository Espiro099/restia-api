<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;

/** Estación de preparación (M-2: una por unidad). */
class Station extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $table = 'stations';

    protected $casts = [
        'branch_id' => ObjectId::class,
    ];
}
