<?php

namespace Tests\Feature\Printing;

use App\Models\PrintJob;
use App\Printing\Simulator;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Floor\FloorTestCase;

/**
 * Criterio de salida de la fase 4: una comanda sale en cocina, con fallas de impresora simuladas.
 * Usa los comandos de demo y el agente simulado contra la API de la prueba.
 */
class SimulatorTest extends FloorTestCase
{
    private string $token;

    /** @var list<string> */
    private array $printed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
        $this->assertSame(0, Artisan::call('restia:demo-printing', ['--branch' => 'CENTRO']));
        preg_match('/rsv_[0-9a-f]{64}/', Artisan::output(), $m);
        $this->token = $m[0];
    }

    private function sim(string $mode, ?\Closure $random = null): Simulator
    {
        $send = function (string $method, string $path, array $body) {
            $this->app['auth']->forgetGuards();
            $r = $this->json($method, '/api/v1/'.$path, $body, ['Authorization' => 'Bearer '.$this->token]);

            return [$r->status(), $r->json()];
        };
        $sim = new Simulator($send, function (string $line) {
            $this->printed[] = $line;
        }, $mode, 0.5, $random);
        $this->assertTrue($sim->boot());

        return $sim;
    }

    private function order(): void
    {
        $this->assertSame(0, Artisan::call('restia:demo-order', ['--branch' => 'CENTRO']));
        $this->artisan('restia:printing')->assertSuccessful();
    }

    public function test_la_comanda_sale_en_cocina_y_en_barra(): void
    {
        $this->artisan('restia:demo-printing', ['--branch' => 'CENTRO'])->assertFailed();
        $this->order();
        $this->assertSame(2, PrintJob::query()->where('type', 'KITCHEN_TICKET')->count());

        $this->assertSame(['PRINTED', 'PRINTED'], $this->sim('none')->tick());
        $this->assertSame(2, PrintJob::query()->where('status', PrintJob::PRINTED)->count());
        $text = implode("\n", $this->printed);
        $this->assertStringContainsString('COMANDA', $text);
        $this->assertStringContainsString('2 X SOPA DEL DÍA', $text);
        $this->assertStringContainsString('1 X AGUA MINERAL', $text);
        $this->assertStringContainsString('+ Chico', $text);
        // Papel de 80 mm: 48 columnas dentro del marco.
        $this->assertSame(48 + 5, mb_strlen(collect($this->printed)->first(fn ($l) => str_starts_with($l, '   |'))));
    }

    public function test_fallas_de_impresora_simuladas(): void
    {
        $this->order();
        // La impresora no recibe nada: se reintenta sola y a la tercera queda FAILED.
        $sim = $this->sim('not-sent');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(['NOT_SENT', 'NOT_SENT'], $sim->tick());
            $this->travel(30)->seconds();
        }
        $this->assertSame(2, PrintJob::query()->where('status', PrintJob::FAILED)->count());

        // El agente se cae a media impresión: al vencer el plazo, UNKNOWN.
        $this->order();
        $this->assertSame(['CRASH', 'CRASH'], $this->sim('crash')->tick());
        $this->travel(61)->seconds();
        $this->artisan('restia:printing')->assertSuccessful();
        $this->assertSame(2, PrintJob::query()->where('status', PrintJob::UNKNOWN)->count());

        // Se corta la conexión: UNKNOWN de inmediato.
        $this->order();
        $this->assertSame(['UNCERTAIN', 'UNCERTAIN'], $this->sim('uncertain')->tick());
        $this->assertSame(4, PrintJob::query()->where('status', PrintJob::UNKNOWN)->count());

        // random: con 0.1 falla (y elige el primer modo); con 0.9 sale.
        $this->order();
        $rolls = [0.1, 0.0, 0.9];
        $this->assertSame(['NOT_SENT', 'PRINTED'], $this->sim('random', function () use (&$rolls) {
            return array_shift($rolls);
        })->tick());
    }

    public function test_token_invalido_no_arranca(): void
    {
        $this->token = 'rsv_'.str_repeat('0', 64);
        $send = function (string $method, string $path, array $body) {
            $r = $this->json($method, '/api/v1/'.$path, $body, ['Authorization' => 'Bearer '.$this->token]);

            return [$r->status(), $r->json()];
        };
        $this->assertFalse((new Simulator($send, fn () => null))->boot());
        $this->artisan('restia:print-agent-sim', ['--fail' => 'otro'])->assertFailed();
    }
}
