<?php

namespace Tests\Feature\Fiscal;

use App\Fiscal\Pac\CancelRequest;
use App\Fiscal\Pac\CancelResult;
use App\Fiscal\Pac\CsdRejected;
use App\Fiscal\Pac\Pac;
use App\Fiscal\Pac\PacFailure;
use App\Fiscal\Pac\PacUnreachable;
use App\Fiscal\Pac\StampRequest;
use App\Fiscal\Pac\SwSapienPac;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conector SW Sapien contra la API simulada con Http::fake: forma de cada petición (rutas, cabeceras,
 * token Bearer, JSON del CFDI) y traducción de cada respuesta. La prueba real contra el sandbox se hace
 * con `php artisan restia:sw-sandbox` y las credenciales en .env.
 */
class SwSapienPacTest extends TestCase
{
    private const URL = 'https://services.test.sw.com.mx';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function pac(array $config = []): SwSapienPac
    {
        return new SwSapienPac($config + ['url' => self::URL, 'user' => 'usuario@ejemplo.mx', 'password' => 'clave-sw', 'token' => null, 'timeout' => 5]);
    }

    private function authOk(): array
    {
        return [self::URL.'/v2/security/authenticate' => Http::response(['data' => ['token' => 'tok-123', 'expires_in' => 7200], 'status' => 'success'])];
    }

    private function request(): StampRequest
    {
        return new StampRequest(
            reference: 'inv-0001',
            type: 'INGRESO',
            issuer: ['rfc' => 'EKU9003173C9', 'legalName' => 'ESCUELA KEMPER URGATE', 'taxRegime' => '601', 'postalCode' => '42501', 'csdRef' => '30001000000500003416'],
            receiver: ['rfc' => 'URE180429TM6', 'legalName' => 'UNIVERSIDAD ROBOTICA ESPAÑOLA', 'taxRegime' => '601', 'postalCode' => '86991', 'cfdiUse' => 'G03'],
            series: 'A', folio: '15', paymentMethod: 'PUE', paymentForm: '01', currency: 'MXN',
            lines: [['description' => 'Tacos al pastor', 'satProductCode' => '90101501', 'satUnitCode' => 'H87', 'quantity' => '2', 'unitValue' => '50.000000',
                'amount' => '100.00', 'taxObject' => '02', 'taxRate' => '0.160000', 'taxAmount' => '16.00']],
            subtotal: '100.00', taxTotal: '16.00', total: '116.00',
            issuedAt: CarbonImmutable::parse('2026-11-16T18:00:00Z'),
        );
    }

    public function test_se_elige_por_configuracion_y_exige_https_y_credenciales(): void
    {
        config(['restia.fiscal.pac' => 'sw', 'services.sw' => ['url' => self::URL, 'token' => 'infinito', 'user' => null, 'password' => null]]);
        $this->assertInstanceOf(SwSapienPac::class, app(Pac::class));

        $this->expectExceptionMessage('https');
        $this->pac(['url' => 'http://services.test.sw.com.mx']);
    }

    public function test_timbra_con_token_bearer_customid_y_json_cfdi40(): void
    {
        Http::fake($this->authOk() + [self::URL.'/v4/cfdi33/issue/json/v4' => Http::response(['data' => [
            'uuid' => 'a1b2c3d4-0000-4000-8000-000000000001', 'fechaTimbrado' => '2026-11-16T12:00:05', 'cfdi' => '<cfdi:Comprobante/>',
        ], 'status' => 'success'])]);

        $result = $this->pac()->stamp($this->request());

        $this->assertSame('A1B2C3D4-0000-4000-8000-000000000001', $result->fiscalUuid);
        $this->assertSame('2026-11-16T18:00:05+00:00', $result->stampedAt->toIso8601String());
        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/v2/security/authenticate'
            && $r['user'] === 'usuario@ejemplo.mx' && $r['password'] === 'clave-sw');
        Http::assertSent(function (Request $r) {
            if ($r->url() !== self::URL.'/v4/cfdi33/issue/json/v4') {
                return false;
            }
            $cfdi = json_decode($r->body(), true);

            return $r->hasHeader('Authorization', 'Bearer tok-123')
                && $r->hasHeader('Content-Type', 'application/jsontoxml')
                && $r->hasHeader('customid', 'inv-0001')
                && $cfdi['Version'] === '4.0' && $cfdi['Sello'] === '' && $cfdi['Fecha'] === '2026-11-16T12:00:00'
                && $cfdi['Emisor']['Rfc'] === 'EKU9003173C9' && $cfdi['Receptor']['UsoCFDI'] === 'G03'
                && $cfdi['Conceptos'][0]['Impuestos']['Traslados'][0]['Importe'] === '16.00'
                && $cfdi['Impuestos']['TotalImpuestosTrasladados'] === '16.00';
        });
    }

    public function test_el_token_se_reutiliza_y_se_renueva_si_vence(): void
    {
        $ok = Http::response(['data' => ['uuid' => 'a1b2c3d4-0000-4000-8000-000000000001', 'fechaTimbrado' => '2026-11-16T12:00:05', 'cfdi' => '<x/>'], 'status' => 'success']);
        Http::fake($this->authOk() + [self::URL.'/v4/*' => Http::sequence()->pushResponse($ok)->push(['message' => 'AU4101 - token vencido', 'status' => 'error'], 401)->pushResponse($ok)]);

        $this->pac()->stamp($this->request());
        $this->pac()->stamp($this->request());

        // Una autenticación para la primera, ninguna para la segunda hasta el 401, y otra tras el 401.
        Http::assertSentCount(5);
        $this->assertSame('tok-123', Cache::get('restia:sw-sapien:token'));
    }

    public function test_con_token_infinito_no_se_autentica(): void
    {
        Http::fake([self::URL.'/v4/*' => Http::response(['data' => ['uuid' => 'a1b2c3d4-0000-4000-8000-000000000001', 'fechaTimbrado' => '2026-11-16T12:00:05', 'cfdi' => '<x/>'], 'status' => 'success'])]);

        $this->pac(['token' => 'jwt-infinito', 'user' => null, 'password' => null])->stamp($this->request());

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer jwt-infinito'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'authenticate'));
    }

    public function test_rechazo_del_sat_es_pac_failure_con_su_codigo(): void
    {
        Http::fake($this->authOk() + [self::URL.'/v4/*' => Http::response(['message' => 'CFDI40147 - El RFC del receptor no es válido', 'messageDetail' => 'Rfc', 'data' => null, 'status' => 'error'], 400)]);

        try {
            $this->pac()->stamp($this->request());
            $this->fail('Debió fallar');
        } catch (PacFailure $e) {
            $this->assertSame('CFDI40147', $e->providerCode);
        }
    }

    public function test_sin_respuesta_o_customid_repetido_queda_sin_saber(): void
    {
        Http::fake($this->authOk() + [self::URL.'/v4/*' => Http::sequence()
            ->pushFailedConnection()
            ->push(['message' => 'CFDI3307 - Timbre duplicado', 'status' => 'error'], 400)
            ->push(['message' => 'Error interno', 'status' => 'error'], 503)]);
        foreach (range(1, 3) as $i) {
            $this->assertThrows(fn () => $this->pac()->stamp($this->request()), PacUnreachable::class);
        }
    }

    public function test_credenciales_invalidas_no_timbran(): void
    {
        Http::fake([self::URL.'/v2/security/authenticate' => Http::response(['message' => 'AU2000 - El usuario y/o contraseña son inválidos', 'data' => null, 'status' => 'error'], 400)]);

        $this->assertThrows(fn () => $this->pac()->stamp($this->request()), PacFailure::class);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'issue'));
    }

    public function test_registra_el_csd_en_base64_y_lee_serie_y_vigencia(): void
    {
        Http::fake($this->authOk() + [self::URL.'/certificates/save' => Http::response(['data' => 'CSD Guardados Correctamente.', 'status' => 'success'])]);
        $cer = (string) file_get_contents(base_path('tests/Fixtures/csd/EKU9003173C9.cer'));
        $key = (string) file_get_contents(base_path('tests/Fixtures/csd/EKU9003173C9.key'));

        $info = $this->pac()->registerCsd('EKU9003173C9', $cer, $key, '12345678a');

        $this->assertMatchesRegularExpression('/^\d{20}$/', $info->serial);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/certificates/save'
            && $r['type'] === 'stamp' && $r['b64Cer'] === base64_encode($cer) && $r['b64Key'] === base64_encode($key));
    }

    public function test_csd_rechazado_por_sw(): void
    {
        Http::fake($this->authOk() + [self::URL.'/certificates/save' => Http::response(['message' => 'La contraseña de la llave es incorrecta', 'status' => 'error'], 400)]);
        $cer = (string) file_get_contents(base_path('tests/Fixtures/csd/EKU9003173C9.cer'));

        $this->assertThrows(fn () => $this->pac()->registerCsd('EKU9003173C9', $cer, 'llave', 'mala'), CsdRejected::class);
    }

    public function test_cancela_por_uuid_con_el_csd_de_la_cuenta(): void
    {
        $uuid = 'a1b2c3d4-0000-4000-8000-000000000001';
        Http::fake($this->authOk() + [self::URL.'/cfdi33/cancel/*' => Http::response(['data' => ['acuse' => '<Acuse/>', 'uuid' => [$uuid => '201']], 'status' => 'success'])]);

        $result = $this->pac()->cancel(new CancelRequest(strtoupper($uuid), 'EKU9003173C9', 'csd', 'URE180429TM6', '116.00', '02'));

        $this->assertSame(CancelResult::IN_PROGRESS, $result->status);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL."/cfdi33/cancel/EKU9003173C9/$uuid/02/" && $r['motivo'] === '02');
    }

    public function test_cancelacion_no_admitida_es_pac_failure(): void
    {
        $uuid = 'a1b2c3d4-0000-4000-8000-000000000001';
        Http::fake($this->authOk() + [self::URL.'/cfdi33/cancel/*' => Http::response(['data' => ['uuid' => [$uuid => '205']], 'status' => 'success'])]);

        $this->assertThrows(fn () => $this->pac()->cancel(new CancelRequest($uuid, 'EKU9003173C9', 'csd', 'URE180429TM6', '116.00', '02')), PacFailure::class);
    }

    public function test_estatus_de_cancelacion_desde_el_sat(): void
    {
        $soap = fn (string $estado, string $cancelacion) => Http::response("<s:Envelope><s:Body><ConsultaResponse><ConsultaResult><a:Estado>$estado</a:Estado><a:EstatusCancelacion>$cancelacion</a:EstatusCancelacion></ConsultaResult></ConsultaResponse></s:Body></s:Envelope>");
        $request = new CancelRequest('A1B2C3D4-0000-4000-8000-000000000001', 'EKU9003173C9', 'csd', 'URE180429TM6', '116.00', '02');

        Http::fake(['consultaqr.facturaelectronica.sat.gob.mx/*' => Http::sequence()
            ->pushResponse($soap('Cancelado', 'Cancelado sin aceptación'))
            ->pushResponse($soap('Vigente', 'Solicitud rechazada'))
            ->pushResponse($soap('Vigente', 'En proceso'))]);
        $this->assertSame(CancelResult::CANCELLED, $this->pac()->cancellationStatus($request)->status);
        $this->assertSame(CancelResult::REJECTED, $this->pac()->cancellationStatus($request)->status);
        $this->assertSame(CancelResult::IN_PROGRESS, $this->pac()->cancellationStatus($request)->status);
        Http::assertSent(fn (Request $r) => str_contains($r->body(), '?re=EKU9003173C9&rr=URE180429TM6&tt=116.00&id=A1B2C3D4'));
    }

    public function test_nada_se_envia_sin_tls(): void
    {
        $this->assertThrows(fn () => $this->pac(['url' => 'http://inseguro.example']), \RuntimeException::class);
        $this->assertTrue(class_exists(ConnectionException::class));
    }
}
