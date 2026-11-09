<?php

namespace App\Models;

use App\Models\Casts\ObjectId;

/** Un reclamo de un trabajo por un agente (PR-5). Solo el intento vigente puede avanzar el trabajo. */
class PrintJobAttempt extends Document
{
    public const CLAIMED = 'CLAIMED';

    public const PRINTING = 'PRINTING';

    public const PRINTED = 'PRINTED';

    public const NOT_SENT = 'NOT_SENT';

    public const UNCERTAIN = 'UNCERTAIN';

    public const EXPIRED = 'EXPIRED';

    protected $table = 'print_job_attempts';

    protected $casts = [
        'job_id' => ObjectId::class,
        'agent_id' => ObjectId::class,
        'claimed_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
