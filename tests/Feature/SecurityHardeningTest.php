<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Logging\RedactSensitiveData;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use RuntimeException;
use Tests\TestCase;

/** Cabeceras de seguridad, HTTPS obligatorio, errores sin trazas y logs sin datos sensibles (Actividad 3.2). */
class SecurityHardeningTest extends TestCase
{
    public function test_toda_respuesta_lleva_las_cabeceras_de_seguridad(): void
    {
        $this->get('/up')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', SecurityHeaders::API_CSP)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Strict-Transport-Security');

        // También en errores.
        $this->getJson('/api/v1/no-existe')->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_hsts_solo_por_https(): void
    {
        $this->get('https://localhost/up')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_detras_del_proxy_tls_confia_en_x_forwarded_proto(): void
    {
        config(['restia.security.trusted_proxies' => '127.0.0.1']);
        $this->get('/up', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security');
    }

    public function test_un_cliente_no_confiable_no_se_hace_pasar_por_https(): void
    {
        config(['restia.security.trusted_proxies' => '10.0.0.9']);
        $this->get('/up', ['X-Forwarded-Proto' => 'https'])->assertHeaderMissing('Strict-Transport-Security');
        config(['restia.security.trusted_proxies' => null]);
        $this->get('/up', ['X-Forwarded-Proto' => 'https'])->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_con_https_obligatorio_http_redirige_con_308(): void
    {
        config(['restia.security.force_https' => true]);

        $this->get('http://localhost/up?x=1')->assertStatus(308)->assertHeader('Location', 'https://localhost/up?x=1');
        $this->postJson('http://localhost/api/v1/auth/mobile/login', [])->assertStatus(308);
        $this->get('https://localhost/up')->assertOk();
    }

    public function test_la_pagina_de_contrasena_conserva_su_propia_csp(): void
    {
        $csp = $this->get('/contrasena')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'nonce-", $csp);
    }

    public function test_con_debug_apagado_un_error_no_muestra_trazas(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/__falla', fn () => throw new RuntimeException('SELECT secreto FROM tabla en C:\\ruta\\interna.php'));

        $r = $this->getJson('/api/v1/__falla')->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        $body = $r->getContent();
        $this->assertStringNotContainsString('secreto', $body);
        $this->assertStringNotContainsString('trace', $body);
        $this->assertStringNotContainsString('.php', $body);
    }

    public function test_el_log_nunca_guarda_credenciales_ni_tarjetas(): void
    {
        $handler = new TestHandler(Level::Debug);
        $logger = new Logger('prueba', [$handler]);
        $handler->pushProcessor(fn ($record) => RedactSensitiveData::redactRecord($record));

        $logger->warning('Cobro con tarjeta 4111 1111 1111 1111 de ana@correo.mx; Authorization: Bearer 12|abcDEF123', [
            'password' => 'MiClave123', 'refreshToken' => 'r-123', 'X-XSRF-TOKEN' => 'x', 'deviceCredential' => 'd',
            'csd' => ['privateKey' => '-----BEGIN'], 'branch' => 'CENTRO', 'nested' => ['apiKey' => 'sk_test_1'],
        ]);

        $record = $handler->getRecords()[0];
        $all = $record->message.json_encode($record->context);
        foreach (['4111 1111 1111 1111', 'ana@correo.mx', 'abcDEF123', 'MiClave123', 'r-123', '-----BEGIN', 'sk_test_1'] as $secret) {
            $this->assertStringNotContainsString($secret, $all, "El log contiene $secret");
        }
        $this->assertSame('CENTRO', $record->context['branch']);
        $this->assertStringContainsString('…@correo.mx', $record->message);
    }

    public function test_los_canales_de_log_aplican_el_filtro(): void
    {
        foreach (['single', 'daily', 'stderr'] as $channel) {
            $this->assertContains(RedactSensitiveData::class, config("logging.channels.$channel.tap"), "Canal $channel sin filtro");
        }
    }
}
