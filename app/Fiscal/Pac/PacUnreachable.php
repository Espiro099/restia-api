<?php

namespace App\Fiscal\Pac;

use RuntimeException;

/** Sin respuesta del PAC, o una respuesta que no se entiende: no se sabe si timbró (F-11). */
class PacUnreachable extends RuntimeException {}
