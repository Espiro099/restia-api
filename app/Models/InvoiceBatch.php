<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;

/**
 * Lote de factura global (F-6): las cuentas PAID de un periodo sin factura nominativa. Excluir cuentas
 * (F-7) lo deja SUPERSEDED y crea un lote de reemplazo con las que quedan.
 */
class InvoiceBatch extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const SUPERSEDED = 'SUPERSEDED';

    /** Periodicidad del SAT (c_Periodicidad) por la de Restia. */
    public const SAT_PERIODICITY = ['DAILY' => '01', 'WEEKLY' => '02', 'BIWEEKLY' => '03', 'MONTHLY' => '04', 'BIMONTHLY' => '05'];

    protected $table = 'invoice_batches';
}
