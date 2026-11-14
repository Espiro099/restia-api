<?php

namespace App\Fiscal\Pac;

use Carbon\CarbonImmutable;

/**
 * Comprobante listo para timbrar, sin formato de ningún PAC: cada conector lo traduce. Importes como
 * texto con 2 decimales; valor unitario con hasta 6.
 *
 * @param  array{rfc: string, legalName: string, taxRegime: string, postalCode: string, csdRef: string}  $issuer
 * @param  array{rfc: string, legalName: string, taxRegime: string, postalCode: string, cfdiUse: string}|null  $receiver
 * @param  list<array{description: string, satProductCode: string, satUnitCode: string, quantity: string, unitValue: string, amount: string, taxObject: string, taxRate: string, taxAmount: string}>  $lines
 */
final readonly class StampRequest
{
    public function __construct(
        public string $reference,
        public string $type,
        public array $issuer,
        public ?array $receiver,
        public string $series,
        public string $folio,
        public string $paymentMethod,
        public string $paymentForm,
        public string $currency,
        public array $lines,
        public string $subtotal,
        public string $taxTotal,
        public string $total,
        public CarbonImmutable $issuedAt,
    ) {}
}
