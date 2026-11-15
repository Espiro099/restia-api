<?php

namespace App\Fiscal\Pac;

use Carbon\CarbonImmutable;

/** Lo único que Restia guarda del CSD: referencia en el PAC, número de serie y vigencia. */
final readonly class CsdInfo
{
    public function __construct(public string $providerRef, public string $serial, public CarbonImmutable $validUntil) {}
}
