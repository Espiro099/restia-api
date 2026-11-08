<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Casts\ObjectId;

/** Limpieza pendiente o confirmada de una mesa, ligada a la versión de membresía de la sesión (T-2). */
class TableCleaningConfirmation extends Document
{
    public const PENDING = 'PENDING';

    public const CONFIRMED = 'CONFIRMED';

    protected $table = 'table_cleaning_confirmations';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'session_id' => ObjectId::class,
        'table_id' => ObjectId::class,
        'confirmed_by' => ObjectId::class,
        'session_membership_version' => 'integer',
        'confirmed_at' => 'datetime',
    ];
}
