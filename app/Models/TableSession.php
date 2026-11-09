<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Sesión de mesa (T-1). `activity_epoch` serializa una comanda con el cierre (sección 5 de Orders). */
class TableSession extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const PENDING_CLEANING = 'PENDING_CLEANING';

    public const CLOSED = 'CLOSED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'table_sessions';

    protected $hidden = ['activity_epoch'];

    protected $casts = [
        'branch_id' => ObjectId::class,
        'responsible_user_id' => ObjectId::class,
        'opened_by' => ObjectId::class,
        'opened_at' => 'datetime',
        'close_requested_at' => 'datetime',
        'closed_at' => 'datetime',
        'guest_count' => 'integer',
        'membership_version' => 'integer',
    ];
}
