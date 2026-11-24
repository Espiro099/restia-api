<?php

namespace App\PublicQr;

use RuntimeException;

/** La API de Claude no respondió o rechazó la petición; el cliente ve SERVICE_BUSY y puede reintentar. */
final class AssistantUnavailable extends RuntimeException {}
