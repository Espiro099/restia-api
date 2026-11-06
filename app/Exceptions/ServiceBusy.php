<?php

namespace App\Exceptions;

use RuntimeException;

/** Se agotaron los reintentos de una transacción. La API responde 503 SERVICE_BUSY con Retry-After. */
class ServiceBusy extends RuntimeException {}
