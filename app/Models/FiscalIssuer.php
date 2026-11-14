<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;

/** Emisor fiscal (F-13): catálogo global; cada sucursal apunta a uno. Del CSD solo guarda referencia, serie y vigencia (F-12). */
class FiscalIssuer extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    public const PERIODICITIES = ['DAILY', 'WEEKLY', 'BIWEEKLY', 'MONTHLY', 'BIMONTHLY'];

    protected $table = 'fiscal_issuers';

    protected $casts = ['csd_valid_until' => 'datetime'];

    /** Activo y con CSD vigente: puede emitir. */
    public function canIssue(): bool
    {
        return $this->status === self::ACTIVE && $this->hasValidCsd();
    }

    public function hasValidCsd(): bool
    {
        return $this->csd_provider_ref && $this->csd_valid_until && $this->csd_valid_until->isFuture();
    }
}
