<?php

namespace App\Exceptions;

use RuntimeException;

/** Regla de negocio violada. La API responde con el estado y mensaje del catálogo para `errorCode` (casi siempre 409). */
class DomainConflict extends RuntimeException
{
    /** @param array<string, mixed>|null $details */
    public function __construct(public readonly string $errorCode, public readonly ?array $details = null)
    {
        parent::__construct($errorCode);
    }
}
