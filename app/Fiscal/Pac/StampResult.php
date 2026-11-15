<?php

namespace App\Fiscal\Pac;

use Carbon\CarbonImmutable;

/** Comprobante timbrado: folio fiscal (UUID del SAT), fecha de timbrado y XML. */
final readonly class StampResult
{
    public function __construct(public string $fiscalUuid, public CarbonImmutable $stampedAt, public string $xml) {}
}
