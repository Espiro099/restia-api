<?php

namespace App\Fiscal\Pac;

/**
 * Solicitud de cancelación de un CFDI timbrado. El SAT pide el RFC de emisor y receptor y el total para
 * consultar el estatus; con motivo 01, el folio fiscal de la sustituta.
 */
final readonly class CancelRequest
{
    public function __construct(
        public string $fiscalUuid,
        public string $issuerRfc,
        public string $csdRef,
        public string $receiverRfc,
        public string $total,
        public string $reasonCode,
        public ?string $replacementFiscalUuid = null,
    ) {}
}
