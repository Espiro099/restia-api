<?php

namespace App\Exceptions;

use RuntimeException;

/** La API la traduce a 503 SERVICE_BUSY. */
class ServiceBusy extends RuntimeException
{
}
