<?php

namespace App\Fiscal\Pac;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

/**
 * PAC simulado para desarrollo y pruebas, mientras no se elige proveedor. Revisa el CSD como lo haría
 * un PAC (certificado legible y vigente, del mismo RFC, llave que abre con la contraseña y corresponde
 * al certificado), pero no habla con nadie. Nunca corre en producción.
 *
 * Timbra con un sello de mentira y guarda lo timbrado en `simulated_pac_documents`, para poder
 * consultarlo por nuestra referencia. Para probar los caminos difíciles decide la razón social del
 * receptor: con "SIMULAR RECHAZO" el PAC rechaza, y con "SIMULAR SIN RESPUESTA" timbra pero no
 * contesta (la factura queda en UNKNOWN y la reconciliación la encuentra timbrada).
 */
final class SimulatedPac implements Pac
{
    private const CFDI = 'http://www.sat.gob.mx/cfd/4';

    private const TFD = 'http://www.sat.gob.mx/TimbreFiscalDigital';

    public function __construct()
    {
        if (app()->isProduction()) {
            throw new RuntimeException('El PAC simulado no se usa en producción: configura RESTIA_PAC.');
        }
    }

    public function registerCsd(string $rfc, string $certificate, string $privateKey, string $password): CsdInfo
    {
        $cert = openssl_x509_read(self::pem($certificate, 'CERTIFICATE'));
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (! $info) {
            throw new CsdRejected('Certificado ilegible.');
        }
        // El SAT pone el RFC en x500UniqueIdentifier ("RFC / RFC del representante").
        $owner = (string) ($info['subject']['x500UniqueIdentifier'] ?? '');
        if ($owner !== '' && strtoupper(trim(explode('/', $owner)[0])) !== strtoupper($rfc)) {
            throw new CsdRejected('El certificado es de otro RFC.');
        }
        $validUntil = CarbonImmutable::createFromTimestampUTC((int) $info['validTo_time_t']);
        if ($validUntil->isPast() || CarbonImmutable::createFromTimestampUTC((int) $info['validFrom_time_t'])->isFuture()) {
            throw new CsdRejected('El certificado no está vigente.');
        }
        $key = openssl_pkey_get_private(self::pem($privateKey, 'ENCRYPTED PRIVATE KEY'), $password);
        if (! $key || ! openssl_x509_check_private_key($cert, $key)) {
            throw new CsdRejected('La llave no abre con la contraseña o no corresponde al certificado.');
        }

        return new CsdInfo('sim-'.substr(hash('sha256', $certificate), 0, 24), self::serial($info), $validUntil);
    }

    public function stamp(StampRequest $request): StampResult
    {
        $receiver = $request->receiver;
        if ($receiver && in_array($receiver['rfc'], ['XAXX010101000', 'XEXX010101000'], true)) {
            throw new PacFailure('CFDI40147', 'Una factura nominativa no lleva el RFC genérico.');
        }
        $name = $receiver['legalName'] ?? '';
        if (str_contains($name, 'SIMULAR RECHAZO')) {
            throw new PacFailure('CFDI40999', 'Rechazo simulado.');
        }
        // El PAC no timbra dos veces la misma referencia: devuelve lo que ya timbró.
        $result = $this->find($request->reference) ?? $this->save($request);
        if (str_contains($name, 'SIMULAR SIN RESPUESTA')) {
            throw new PacUnreachable('Sin respuesta simulada.');
        }

        return $result;
    }

    public function find(string $reference): ?StampResult
    {
        $doc = DB::connection('mongodb')->table('simulated_pac_documents')->where('reference', $reference)->first();
        if (! $doc) {
            return null;
        }
        $doc = (array) $doc;

        return new StampResult($doc['fiscal_uuid'], CarbonImmutable::instance($doc['stamped_at']->toDateTime()), $doc['xml']);
    }

    private function save(StampRequest $request): StampResult
    {
        $uuid = strtoupper((string) Str::uuid());
        $stampedAt = CarbonImmutable::now()->startOfSecond();
        $result = new StampResult($uuid, $stampedAt, self::xml($request, $uuid, $stampedAt));
        DB::connection('mongodb')->table('simulated_pac_documents')->insert([
            'reference' => $request->reference, 'fiscal_uuid' => $uuid, 'xml' => $result->xml, 'stamped_at' => new UTCDateTime($stampedAt),
        ]);

        return $result;
    }

    /** CFDI 4.0 con la forma del real; el sello y los certificados son de mentira. */
    private static function xml(StampRequest $r, string $fiscalUuid, CarbonImmutable $stampedAt): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $el = function (DOMElement|DOMDocument $parent, string $name, array $attrs, string $ns = self::CFDI) use ($doc): DOMElement {
            $node = $doc->createElementNS($ns, $name);
            foreach ($attrs as $k => $v) {
                if ($v !== null && $v !== '') {
                    $node->setAttribute($k, (string) $v);
                }
            }
            $parent->appendChild($node);

            return $node;
        };
        $local = fn (CarbonImmutable $t) => $t->setTimezone('America/Mexico_City')->format('Y-m-d\TH:i:s');
        $root = $el($doc, 'cfdi:Comprobante', [
            'Version' => '4.0', 'Serie' => $r->series, 'Folio' => $r->folio, 'Fecha' => $local($r->issuedAt),
            'Sello' => 'SIMULADO', 'FormaPago' => $r->paymentForm, 'NoCertificado' => '00000000000000000000', 'Certificado' => 'SIMULADO',
            'SubTotal' => $r->subtotal, 'Moneda' => $r->currency, 'Total' => $r->total, 'TipoDeComprobante' => $r->type === 'EGRESO' ? 'E' : 'I',
            'Exportacion' => '01', 'MetodoPago' => $r->paymentMethod, 'LugarExpedicion' => $r->issuer['postalCode'],
        ]);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:tfd', self::TFD);
        $el($root, 'cfdi:Emisor', ['Rfc' => $r->issuer['rfc'], 'Nombre' => $r->issuer['legalName'], 'RegimenFiscal' => $r->issuer['taxRegime']]);
        $receiver = $r->receiver ?? ['rfc' => 'XAXX010101000', 'legalName' => 'PUBLICO EN GENERAL', 'postalCode' => $r->issuer['postalCode'], 'taxRegime' => '616', 'cfdiUse' => 'S01'];
        $el($root, 'cfdi:Receptor', ['Rfc' => $receiver['rfc'], 'Nombre' => $receiver['legalName'], 'DomicilioFiscalReceptor' => $receiver['postalCode'],
            'RegimenFiscalReceptor' => $receiver['taxRegime'], 'UsoCFDI' => $receiver['cfdiUse']]);
        $concepts = $el($root, 'cfdi:Conceptos', []);
        $byRate = [];
        foreach ($r->lines as $line) {
            $c = $el($concepts, 'cfdi:Concepto', ['ClaveProdServ' => $line['satProductCode'], 'Cantidad' => $line['quantity'], 'ClaveUnidad' => $line['satUnitCode'],
                'Descripcion' => $line['description'], 'ValorUnitario' => $line['unitValue'], 'Importe' => $line['amount'], 'ObjetoImp' => $line['taxObject']]);
            if ($line['taxObject'] !== '02') {
                continue;
            }
            $el($el($el($c, 'cfdi:Impuestos', []), 'cfdi:Traslados', []), 'cfdi:Traslado',
                ['Base' => $line['amount'], 'Impuesto' => '002', 'TipoFactor' => 'Tasa', 'TasaOCuota' => $line['taxRate'], 'Importe' => $line['taxAmount']]);
            $sum = $byRate[$line['taxRate']] ?? ['base' => '0', 'tax' => '0'];
            $byRate[$line['taxRate']] = ['base' => bcadd($sum['base'], $line['amount'], 2), 'tax' => bcadd($sum['tax'], $line['taxAmount'], 2)];
        }
        if ($byRate) {
            $traslados = $el($el($root, 'cfdi:Impuestos', ['TotalImpuestosTrasladados' => $r->taxTotal]), 'cfdi:Traslados', []);
            foreach ($byRate as $rate => $sum) {
                $el($traslados, 'cfdi:Traslado', ['Base' => $sum['base'], 'Impuesto' => '002', 'TipoFactor' => 'Tasa', 'TasaOCuota' => $rate, 'Importe' => $sum['tax']]);
            }
        }
        $el($el($root, 'cfdi:Complemento', []), 'tfd:TimbreFiscalDigital', [
            'Version' => '1.1', 'UUID' => $fiscalUuid, 'FechaTimbrado' => $local($stampedAt), 'RfcProvCertif' => 'SPR190613I52',
            'SelloCFD' => 'SIMULADO', 'NoCertificadoSAT' => '00000000000000000000', 'SelloSAT' => 'SIMULADO',
        ], self::TFD);

        return (string) $doc->saveXML();
    }

    /** Los archivos del SAT vienen en DER; también se aceptan en PEM. */
    private static function pem(string $content, string $label): string
    {
        return str_contains($content, '-----BEGIN')
            ? $content
            : "-----BEGIN $label-----\n".chunk_split(base64_encode($content), 64, "\n")."-----END $label-----\n";
    }

    /** El número del SAT son 20 dígitos codificados como texto dentro del número de serie. */
    private static function serial(array $info): string
    {
        $hex = (string) ($info['serialNumberHex'] ?? '');
        $text = strlen($hex) % 2 === 0 ? (string) hex2bin($hex) : '';

        return ctype_digit($text) && $text !== '' ? $text : $hex;
    }
}
