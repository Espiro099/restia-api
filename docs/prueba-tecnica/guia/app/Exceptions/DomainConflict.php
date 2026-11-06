<?php

namespace App\Exceptions;

use RuntimeException;

/** La API la traduce a 409 con el código del contrato. */
class DomainConflict extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
