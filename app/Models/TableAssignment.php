<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Casts\ObjectId;

/** Mesa dentro de una sesión. `active` sigue en true hasta confirmar la limpieza, para que el índice parcial bloquee la mesa. */
class TableAssignment extends Document
{
    protected $table = 'table_assignments';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'table_id' => ObjectId::class,
        'session_id' => ObjectId::class,
        'assigned_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
