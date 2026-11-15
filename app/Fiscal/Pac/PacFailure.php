<?php

namespace App\Fiscal\Pac;

use RuntimeException;

/** El PAC rechazó la operación: la API responde FISCAL_OPERATION_FAILED, con el código del PAC. */
class PacFailure extends RuntimeException
{
    public function __construct(public readonly string $providerCode, string $message = '')
    {
        parent::__construct($message ?: $providerCode);
    }
}
