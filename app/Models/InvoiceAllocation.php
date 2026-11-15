<?php

namespace App\Models;

/** Cuenta asignada a una factura (F-3). El índice único parcial deja una sola activa por cuenta. */
class InvoiceAllocation extends Document
{
    protected $table = 'invoice_source_allocations';
}
