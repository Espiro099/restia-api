<?php

namespace App\Fiscal\Pac;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Conector del PAC SW Sapien (F-1), sobre su API REST. Rutas y formatos tomados del SDK oficial
 * (github.com/lunasoft/sw-sdk-php):
 *
 * - Autenticación: POST /v2/security/authenticate {user, password} → token Bearer de 2 h, que se guarda
 *   en caché. Si se configura SW_TOKEN (token "infinito" del portal), se usa ese y no se pide otro.
 * - CSD: POST /certificates/save {type: stamp, is_active, b64Cer, b64Key, password}.
 * - Timbrado: POST /v4/cfdi33/issue/json/v4 con Content-Type application/jsontoxml. SW sella con el CSD
 *   guardado en la cuenta. La cabecera `customid` lleva nuestra referencia: SW rechaza repetirla.
 * - Cancelación: POST /cfdi33/cancel/{rfc}/{uuid}/{motivo}/{folioSustitucion}, con el CSD de la cuenta.
 * - Estatus: el servicio público de consulta del SAT.
 *
 * Toda llamada va por HTTPS con verificación de certificado; el token, la contraseña y el CSD nunca se
 * registran (el log además los filtra).
 */
final class SwSapienPac implements Pac
{
    private const TOKEN_CACHE_KEY = 'restia:sw-sapien:token';

    private const SAT_STATUS_URL = 'https://consultaqr.facturaelectronica.sat.gob.mx/ConsultaCFDIService.svc';

    public function __construct(private readonly array $config)
    {
        if (! str_starts_with((string) ($config['url'] ?? ''), 'https://')) {
            throw new RuntimeException('SW_URL debe ser https://.');
        }
        if (empty($config['token']) && (empty($config['user']) || empty($config['password']))) {
            throw new RuntimeException('Configura SW_TOKEN, o SW_USER y SW_PASSWORD, para usar el PAC SW Sapien.');
        }
    }

    public function registerCsd(string $rfc, string $certificate, string $privateKey, string $password): CsdInfo
    {
        $info = openssl_x509_parse(self::pem($certificate, 'CERTIFICATE'));
        if (! $info) {
            throw new CsdRejected('Certificado ilegible.');
        }
        $response = $this->call(fn (PendingRequest $http) => $http->asJson()->post('/certificates/save', [
            'type' => 'stamp',
            'is_active' => true,
            'b64Cer' => base64_encode(self::der($certificate)),
            'b64Key' => base64_encode(self::der($privateKey)),
            'password' => $password,
        ]));
        if (! self::ok($response)) {
            // Un CSD que SW no acepta es un CSD inválido para el usuario, no una falla del PAC.
            throw new CsdRejected(self::message($response));
        }
        $serial = self::serial($info);

        return new CsdInfo($serial, $serial, CarbonImmutable::createFromTimestampUTC((int) $info['validTo_time_t']));
    }

    public function stamp(StampRequest $request): StampResult
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->withHeaders(['customid' => $request->reference])
            ->withBody(json_encode(self::cfdi($request), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'application/jsontoxml')
            ->post('/v4/cfdi33/issue/json/v4'));

        if (self::ok($response)) {
            $data = $response->json('data');
            if (! is_array($data) || empty($data['uuid']) || empty($data['cfdi'])) {
                throw new PacUnreachable('Respuesta de SW sin folio fiscal.');
            }

            return new StampResult(strtoupper($data['uuid']), self::satTime((string) $data['fechaTimbrado']), (string) $data['cfdi']);
        }
        $code = self::code($response);
        // customid repetido: ya se timbró con esta referencia y no se sabe el folio. Queda en UNKNOWN (F-11).
        if ($code === 'CFDI3307' || $response->serverError()) {
            throw new PacUnreachable(self::message($response));
        }

        throw new PacFailure($code, self::message($response));
    }

    /**
     * SW no ofrece búsqueda por customid en su API. Mientras no se confirme con SW, una factura en UNKNOWN
     * se resuelve desde el Administrador de Timbres y no automáticamente.
     */
    public function find(string $reference): ?StampResult
    {
        throw new PacUnreachable('SW Sapien no permite consultar por referencia; revisar en el Administrador de Timbres.');
    }

    public function cancel(CancelRequest $request): CancelResult
    {
        $path = sprintf('/cfdi33/cancel/%s/%s/%s/%s', rawurlencode($request->issuerRfc), rawurlencode(strtolower($request->fiscalUuid)),
            rawurlencode($request->reasonCode), rawurlencode((string) ($request->reasonCode === '01' ? $request->replacementFiscalUuid : '')));
        $response = $this->call(fn (PendingRequest $http) => $http->asJson()->post($path, [
            'rfc' => $request->issuerRfc,
            'uuid' => $request->fiscalUuid,
            'motivo' => $request->reasonCode,
            'foliosustitucion' => $request->reasonCode === '01' ? $request->replacementFiscalUuid : null,
        ]));
        if ($response->serverError()) {
            throw new PacUnreachable(self::message($response));
        }
        $codes = (array) ($response->json('data.uuid') ?? []);
        $code = (string) (array_change_key_case($codes)[strtolower($request->fiscalUuid)] ?? self::code($response));

        return match ($code) {
            // 201: el SAT recibió la solicitud; si requiere aceptación del receptor se sabe al consultar.
            '201' => new CancelResult(CancelResult::IN_PROGRESS, 'Solicitud recibida'),
            '202' => new CancelResult(CancelResult::CANCELLED, 'Previamente cancelado'),
            default => throw new PacFailure($code === '' ? 'SW_CANCEL' : $code, self::message($response)),
        };
    }

    public function cancellationStatus(CancelRequest $request): CancelResult
    {
        $expression = sprintf('?re=%s&rr=%s&tt=%s&id=%s', $request->issuerRfc, $request->receiverRfc, $request->total, strtoupper($request->fiscalUuid));
        $soap = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">'
            .'<soapenv:Body><tem:Consulta><tem:expresionImpresa><![CDATA['.$expression.']]></tem:expresionImpresa></tem:Consulta></soapenv:Body></soapenv:Envelope>';
        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 30))
                ->withHeaders(['SOAPAction' => 'http://tempuri.org/IConsultaCFDIService/Consulta'])
                ->withBody($soap, 'text/xml; charset=utf-8')->post(self::SAT_STATUS_URL);
        } catch (ConnectionException $e) {
            throw new PacUnreachable('Sin respuesta del SAT: '.$e->getMessage());
        }
        if (! $response->successful()) {
            throw new PacUnreachable('El SAT respondió '.$response->status());
        }
        $body = $response->body();
        $field = fn (string $name) => preg_match('/<(?:\w+:)?'.$name.'>([^<]*)</', $body, $m) ? trim($m[1]) : '';
        $state = $field('Estado');
        $cancellation = $field('EstatusCancelacion');

        return match (true) {
            $state === 'Cancelado' => new CancelResult(CancelResult::CANCELLED, $cancellation ?: $state),
            stripos($cancellation, 'rechaz') !== false => new CancelResult(CancelResult::REJECTED, $cancellation),
            default => new CancelResult(CancelResult::IN_PROGRESS, $cancellation ?: $state),
        };
    }

    /**
     * CFDI 4.0 en el JSON que recibe SW: las mismas llaves que los atributos del XML. Sello, Certificado y
     * NoCertificado van vacíos: SW los llena con el CSD de la cuenta.
     *
     * @return array<string, mixed>
     */
    public static function cfdi(StampRequest $r): array
    {
        $receiver = $r->receiver ?? ['rfc' => 'XAXX010101000', 'legalName' => 'PUBLICO EN GENERAL', 'postalCode' => $r->issuer['postalCode'], 'taxRegime' => '616', 'cfdiUse' => 'S01'];
        $cfdi = [
            'Version' => '4.0',
            'Serie' => $r->series,
            'Folio' => $r->folio,
            'Fecha' => $r->issuedAt->setTimezone('America/Mexico_City')->format('Y-m-d\TH:i:s'),
            'Sello' => '',
            'NoCertificado' => '',
            'Certificado' => '',
            'FormaPago' => $r->paymentForm,
            'SubTotal' => $r->subtotal,
            'Moneda' => $r->currency,
            'Total' => $r->total,
            'TipoDeComprobante' => $r->type === 'EGRESO' ? 'E' : 'I',
            'Exportacion' => '01',
            'MetodoPago' => $r->paymentMethod,
            'LugarExpedicion' => $r->issuer['postalCode'],
        ];
        if ($r->global) {
            $cfdi['InformacionGlobal'] = ['Periodicidad' => $r->global['periodicity'], 'Meses' => $r->global['months'], 'Año' => $r->global['year']];
        }
        if ($r->replaces) {
            $cfdi['CfdiRelacionados'] = [['TipoRelacion' => '04', 'CfdiRelacionado' => [['UUID' => $r->replaces]]]];
        }
        $cfdi['Emisor'] = ['Rfc' => $r->issuer['rfc'], 'Nombre' => $r->issuer['legalName'], 'RegimenFiscal' => $r->issuer['taxRegime']];
        $cfdi['Receptor'] = ['Rfc' => $receiver['rfc'], 'Nombre' => $receiver['legalName'], 'DomicilioFiscalReceptor' => $receiver['postalCode'],
            'RegimenFiscalReceptor' => $receiver['taxRegime'], 'UsoCFDI' => $receiver['cfdiUse']];

        $byRate = [];
        $cfdi['Conceptos'] = [];
        foreach ($r->lines as $line) {
            $concept = array_filter([
                'ClaveProdServ' => $line['satProductCode'], 'NoIdentificacion' => $line['identification'] ?? null, 'Cantidad' => $line['quantity'],
                'ClaveUnidad' => $line['satUnitCode'], 'Descripcion' => $line['description'], 'ValorUnitario' => $line['unitValue'],
                'Importe' => $line['amount'], 'ObjetoImp' => $line['taxObject'],
            ], fn ($v) => $v !== null && $v !== '');
            if ($line['taxObject'] === '02') {
                $concept['Impuestos'] = ['Traslados' => [[
                    'Base' => $line['amount'], 'Impuesto' => '002', 'TipoFactor' => 'Tasa', 'TasaOCuota' => $line['taxRate'], 'Importe' => $line['taxAmount'],
                ]]];
                $sum = $byRate[$line['taxRate']] ?? ['base' => '0', 'tax' => '0'];
                $byRate[$line['taxRate']] = ['base' => bcadd($sum['base'], $line['amount'], 2), 'tax' => bcadd($sum['tax'], $line['taxAmount'], 2)];
            }
            $cfdi['Conceptos'][] = $concept;
        }
        if ($byRate) {
            $cfdi['Impuestos'] = ['TotalImpuestosTrasladados' => $r->taxTotal, 'Traslados' => array_map(
                fn ($rate, $sum) => ['Base' => $sum['base'], 'Impuesto' => '002', 'TipoFactor' => 'Tasa', 'TasaOCuota' => $rate, 'Importe' => $sum['tax']],
                array_keys($byRate), $byRate,
            )];
        }

        return $cfdi;
    }

    /** Llamada autenticada; si el token venció (401), se pide otro y se repite una vez. */
    private function call(\Closure $send): Response
    {
        try {
            $response = $send($this->http($this->token()));
            if ($response->status() === 401 && empty($this->config['token'])) {
                Cache::forget(self::TOKEN_CACHE_KEY);
                $response = $send($this->http($this->token()));
            }
        } catch (ConnectionException $e) {
            throw new PacUnreachable('Sin respuesta de SW Sapien: '.$e->getMessage());
        }

        return $response;
    }

    private function http(string $token): PendingRequest
    {
        return Http::baseUrl(rtrim($this->config['url'], '/'))
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson()
            ->withToken($token);
    }

    private function token(): string
    {
        if (! empty($this->config['token'])) {
            return $this->config['token'];
        }
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        try {
            $response = Http::baseUrl(rtrim($this->config['url'], '/'))->timeout((int) ($this->config['timeout'] ?? 30))->acceptJson()
                ->asJson()->post('/v2/security/authenticate', ['user' => $this->config['user'], 'password' => $this->config['password']]);
        } catch (ConnectionException $e) {
            throw new PacUnreachable('Sin respuesta de SW Sapien al autenticar: '.$e->getMessage());
        }
        $token = $response->json('data.token');
        if (! self::ok($response) || ! is_string($token) || $token === '') {
            Log::error('SW Sapien rechazó la autenticación', ['code' => self::code($response)]);
            throw new PacFailure(self::code($response) ?: 'AU2000', 'No se pudo autenticar con SW Sapien.');
        }
        // Se renueva un minuto antes de que venza (expires_in en segundos; 2 h si no viene).
        $ttl = max(60, (int) ($response->json('data.expires_in') ?? 7200) - 60);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    private static function ok(Response $response): bool
    {
        return $response->successful() && $response->json('status') === 'success';
    }

    /** El código va al inicio del mensaje: "CFDI40147 - ..." o "AU2000 - ...". */
    private static function code(Response $response): string
    {
        $message = (string) ($response->json('message') ?? '');

        return preg_match('/^\s*([A-Z]*\d+)\b/', $message, $m) ? $m[1] : ($response->successful() ? '' : 'HTTP'.$response->status());
    }

    private static function message(Response $response): string
    {
        $detail = $response->json('messageDetail');

        return trim((string) ($response->json('message') ?? 'Respuesta sin mensaje').($detail ? ' — '.$detail : ''));
    }

    /** SW responde la fecha de timbrado en hora del centro de México, sin zona. */
    private static function satTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'America/Mexico_City')->utc();
    }

    private static function der(string $content): string
    {
        if (! str_contains($content, '-----BEGIN')) {
            return $content;
        }

        return (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $content));
    }

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
