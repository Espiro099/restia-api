<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;

/**
 * Cliente (CU-1). Cada sucursal solo ve los clientes con perfil en ella, y el mismo teléfono en otra
 * sucursal es otra identidad (CU-2). Sin datos fiscales guardados.
 */
class Customer extends Document
{
    use HasVersion;

    /** Nombre que queda al anonimizar (CU-4). */
    public const ANONYMIZED_NAME = 'Cliente anonimizado';

    protected $table = 'customers';

    protected $casts = [
        'anonymized_at' => 'datetime',
    ];
}
