<?php

namespace App\Fiscal\Pac;

/**
 * Estado de una cancelación: CANCELLED, IN_PROGRESS (el receptor tiene tres días hábiles para
 * responder) o REJECTED (el receptor la rechazó; el CFDI sigue vigente). `satStatus` es el texto del SAT.
 */
final readonly class CancelResult
{
    public const CANCELLED = 'CANCELLED';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const REJECTED = 'REJECTED';

    public function __construct(public string $status, public ?string $satStatus = null) {}
}
