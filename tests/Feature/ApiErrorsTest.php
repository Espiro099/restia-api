<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflict;
use App\Exceptions\ServiceBusy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ApiErrorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::prefix('api/v1/_test')->group(function () {
            Route::get('conflict', fn () => throw new DomainConflict('VERSION_CONFLICT', ['currentVersion' => 4]));
            Route::get('unknown-code', fn () => throw new DomainConflict('NO_EXISTE'));
            Route::get('busy', fn () => throw new ServiceBusy);
            Route::get('crash', fn () => throw new RuntimeException('detalle interno secreto'));
            Route::post('validate', fn (Request $r) => $r->validate(['email' => 'required|email']));
            Route::get('ok', fn () => ['data' => ['ok' => true]]);
        });
    }

    private function assertErrorShape($response, int $status, string $code): array
    {
        $response->assertStatus($status)->assertHeader('Content-Type', 'application/json');
        $error = $response->json('error');
        $this->assertSame($code, $error['code']);
        $this->assertNotEmpty($error['message']);
        $this->assertTrue(Str::isUuid($error['requestId']));
        $this->assertSame($error['requestId'], $response->headers->get('X-Request-ID'));

        return $error;
    }

    public function test_ruta_inexistente_responde_404_del_contrato(): void
    {
        $this->assertErrorShape($this->getJson('/api/v1/no-existe'), 404, 'RESOURCE_NOT_FOUND');
    }

    public function test_respuesta_correcta_lleva_x_request_id(): void
    {
        $response = $this->getJson('/api/v1/_test/ok')->assertOk();
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
    }

    public function test_respeta_el_x_request_id_valido_del_cliente(): void
    {
        $id = (string) Str::uuid();
        $response = $this->getJson('/api/v1/no-existe', ['X-Request-ID' => strtoupper($id)]);
        $this->assertSame($id, $response->json('error.requestId'));
        $response->assertHeader('X-Request-ID', $id);
    }

    public function test_reemplaza_un_x_request_id_invalido(): void
    {
        $response = $this->getJson('/api/v1/_test/ok', ['X-Request-ID' => "x\r\nSet-Cookie: a=b"]);
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
    }

    public function test_conflicto_de_dominio_usa_estado_y_mensaje_del_catalogo(): void
    {
        $error = $this->assertErrorShape($this->getJson('/api/v1/_test/conflict'), 409, 'VERSION_CONFLICT');
        $this->assertSame(config('errors.VERSION_CONFLICT.message'), $error['message']);
        $this->assertSame(['currentVersion' => 4], $error['details']);
    }

    public function test_codigo_fuera_del_catalogo_no_llega_al_cliente(): void
    {
        $error = $this->assertErrorShape($this->getJson('/api/v1/_test/unknown-code'), 500, 'INTERNAL_ERROR');
        $this->assertArrayNotHasKey('details', $error);
    }

    public function test_reintentos_agotados_responden_503_con_retry_after(): void
    {
        $response = $this->getJson('/api/v1/_test/busy');
        $this->assertErrorShape($response, 503, 'SERVICE_BUSY');
        $response->assertHeader('Retry-After', '1');
    }

    public function test_validacion_responde_422_con_campos(): void
    {
        $error = $this->assertErrorShape($this->postJson('/api/v1/_test/validate', ['email' => 'no-es-correo']), 422, 'VALIDATION_FAILED');
        $this->assertArrayHasKey('email', $error['details']['fields']);
    }

    public function test_error_inesperado_no_filtra_detalles_aunque_haya_debug(): void
    {
        config(['app.debug' => true]);
        $response = $this->getJson('/api/v1/_test/crash');
        $this->assertErrorShape($response, 500, 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('secreto', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
    }

    public function test_metodo_no_permitido_mantiene_el_formato(): void
    {
        $response = $this->postJson('/api/v1/_test/ok');
        $this->assertErrorShape($response, 405, 'HTTP_405');
        $this->assertNotNull($response->headers->get('Allow'));
    }

    public function test_peticion_sin_json_tambien_responde_json(): void
    {
        $response = $this->get('/api/v1/no-existe', ['Accept' => 'text/html']);
        $this->assertErrorShape($response, 404, 'RESOURCE_NOT_FOUND');
    }

    public function test_catalogo_tiene_los_102_codigos_del_contrato(): void
    {
        $this->assertCount(102, config('errors'));
        foreach (['AUTHENTICATION_REQUIRED', 'FORBIDDEN', 'RESOURCE_NOT_FOUND', 'TOO_MANY_ATTEMPTS', 'INTERNAL_ERROR'] as $code) {
            $this->assertArrayHasKey($code, config('errors'));
        }
    }
}
