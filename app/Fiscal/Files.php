<?php

namespace App\Fiscal;

use App\Fiscal\Pac\StampResult;
use App\Models\FiscalIssuer;
use App\Models\Invoice;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * F-14: XML y PDF en almacenamiento de objetos, nunca en MongoDB, y se descargan solo por la API. En
 * local, el disco privado; en producción, el que indique RESTIA_FISCAL_DISK.
 */
final class Files
{
    public static function disk(): Filesystem
    {
        return Storage::disk(config('restia.fiscal.disk'));
    }

    /** @return array{xml: string, pdf: string} rutas dentro del disco */
    public static function store(Invoice $invoice, StampResult $result): array
    {
        $base = 'fiscal/'.$invoice->branch_id.'/'.$invoice->uuid;
        $paths = ['xml' => "$base.xml", 'pdf' => "$base.pdf"];
        self::disk()->put($paths['xml'], $result->xml);
        self::disk()->put($paths['pdf'], self::pdf($invoice, $result));

        return $paths;
    }

    /** Representación impresa: datos del comprobante, conceptos y totales. */
    private static function pdf(Invoice $invoice, StampResult $result): string
    {
        $issuer = FiscalIssuer::query()->find((string) $invoice->issuer_id);
        $receiver = (array) $invoice->receiver;
        $money = fn ($v) => '$'.number_format((float) (string) $v, 2);
        $lines = [
            ['FACTURA '.$invoice->series.'-'.$invoice->folio, 14],
            ['Folio fiscal: '.$result->fiscalUuid],
            ['Fecha de timbrado: '.$result->stampedAt->setTimezone('America/Mexico_City')->format('Y-m-d H:i:s')],
            [''],
            ['Emisor: '.$issuer->legal_name],
            ['RFC '.$issuer->rfc.'  ·  Régimen '.$issuer->tax_regime.'  ·  Lugar de expedición '.$issuer->postal_code],
            [''],
            ['Receptor: '.$receiver['legalName']],
            ['RFC '.$receiver['rfc'].'  ·  Régimen '.$receiver['taxRegime'].'  ·  C.P. '.$receiver['postalCode'].'  ·  Uso CFDI '.$receiver['cfdiUse']],
            ['Método de pago '.$invoice->sat_payment_method.'  ·  Forma de pago '.$invoice->sat_payment_form.'  ·  Moneda '.$invoice->currency],
            [''],
            ['Cant.  Clave     Descripción                                   Valor unitario      Importe'],
        ];
        foreach (Invoices::lines($invoice) as $l) {
            $lines[] = [sprintf('%5s  %-8s  %-44s  %14s  %11s', $l['quantity'], $l['satProductCode'], mb_strimwidth($l['description'], 0, 44, '…'),
                $money($l['unitValue']), $money($l['amount']))];
        }
        array_push($lines, [''], ['Subtotal: '.$money($invoice->subtotal)], ['IVA trasladado: '.$money($invoice->tax_total)], ['Total: '.$money($invoice->total), 12], [''],
            ['Este documento es una representación impresa de un CFDI.']);
        if (config('restia.fiscal.pac') === 'simulated') {
            $lines[] = ['TIMBRADO SIMULADO: SIN VALIDEZ FISCAL.', 12];
        }

        return SimplePdf::render($lines);
    }
}
