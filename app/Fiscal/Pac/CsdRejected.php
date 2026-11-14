<?php

namespace App\Fiscal\Pac;

use RuntimeException;

/** El certificado, la llave o la contraseña no son válidos: la API responde CSD_INVALID. */
class CsdRejected extends RuntimeException {}
