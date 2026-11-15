<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;

/** Factura (D-15, Decisiones_Fiscal). El ciclo fiscal va aparte del comercial: la cuenta nunca espera al SAT. */
class Invoice extends Document
{
    use HasVersion;

    public const DRAFT = 'DRAFT';

    public const DISCARDED = 'DISCARDED';

    public const PENDING_STAMP = 'PENDING_STAMP';

    public const STAMPING = 'STAMPING';

    public const STAMPED = 'STAMPED';

    public const STAMP_FAILED = 'STAMP_FAILED';

    public const CANCELLATION_REQUESTED = 'CANCELLATION_REQUESTED';

    public const CANCELLATION_IN_PROGRESS = 'CANCELLATION_IN_PROGRESS';

    public const CANCELLED = 'CANCELLED';

    public const CANCELLATION_REJECTED = 'CANCELLATION_REJECTED';

    public const UNKNOWN = 'UNKNOWN';

    /** Se pueden corregir, descartar y mandar a timbrar: nunca existieron ante el SAT. */
    public const EDITABLE = [self::DRAFT, self::STAMP_FAILED];

    protected $table = 'invoices';

    protected $casts = ['stamped_at' => 'datetime', 'lease_until' => 'datetime', 'reconcile_requested_at' => 'datetime'];
}
