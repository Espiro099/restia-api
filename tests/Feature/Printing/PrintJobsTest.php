<?php

namespace Tests\Feature\Printing;

use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Station;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Floor\FloorTestCase;

/** Trabajos de impresión: disparadores desde outbox, reclamo del agente, plazos y decisiones de una persona. */
class PrintJobsTest extends FloorTestCase
{
    private string $token;

    private string $agent;

    /** @var array<string, string> impresora por estación */
    private array $printers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
        $identity = $this->as('admin', 'POST', '/service-identities', ['name' => 'Agente', 'type' => 'PRINTING_AGENT', 'branchIds' => [$this->branch->uuid]])->assertCreated();
        $this->token = $identity->json('data.token');
        $this->agent = $this->as('gerente', 'POST', 'print-agents', ['serviceIdentityId' => $identity->json('data.identity.id'), 'name' => 'PC'])->assertCreated()->json('data.id');
        foreach (['COCINA', 'BARRA'] as $code) {
            $this->printers[$code] = $this->as('gerente', 'POST', 'printers', [
                'agentId' => $this->agent, 'code' => "IMP-$code", 'name' => "Impresora $code",
                'connection' => ['type' => 'NETWORK', 'host' => '10.0.0.'.(count($this->printers) + 10)], 'paperWidth' => 80,
            ])->assertCreated()->json('data.id');
            $this->as('gerente', 'POST', 'print-stations', [
                'code' => "COMANDA-$code", 'name' => "Comandas $code", 'stationId' => $this->station($code),
                'jobTypes' => ['KITCHEN_TICKET', 'VOID_NOTICE'], 'printerId' => $this->printers[$code],
            ])->assertCreated();
        }
    }

    private function station(string $code): string
    {
        return Station::query()->where('code', $code)->firstOrFail()->uuid;
    }

    private function agentCall(string $method, string $path, array $body = []): TestResponse
    {
        return $this->bearer($this->token, $method, '/api/v1/print-agent/'.$path, $body);
    }

    private function work(): void
    {
        $this->artisan('restia:printing')->assertSuccessful();
    }

    /** Comanda con 2 aguas (barra) y unos tacos (cocina), ya procesada por el disparador. */
    private function order(): array
    {
        $order = $this->as('mesero', 'POST', 'orders', $this->round($this->openSession()), $this->key())->assertCreated()->json('data');
        $this->work();

        return $order;
    }

    private function job(string $id): PrintJob
    {
        return PrintJob::query()->where('uuid', $id)->firstOrFail();
    }

    private function events(string $type): int
    {
        return DB::connection('mongodb')->table('outbox_events')->where('type', $type)->count();
    }

    public function test_la_comanda_sale_por_estacion_y_el_agente_la_imprime(): void
    {
        $order = $this->order();
        $jobs = $this->as('cocina', 'GET', 'print-jobs?type=KITCHEN_TICKET')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing(array_values($this->printers), array_column($jobs, 'printerId'));
        $this->assertSame(['PENDING', 'PENDING'], array_column($jobs, 'status'));
        $this->assertSame('HIGH', $jobs[0]['priority']);
        $this->assertSame($order['id'], $jobs[0]['sourceId']);
        $this->assertNotNull($jobs[0]['expiresAt']);
        $this->assertNull($jobs[0]['createdBy']);

        // El evento repetido no duplica comandas.
        DB::connection('mongodb')->table('outbox_events')->where('type', 'order.created')->update(['$pull' => ['handled_by' => 'printing']]);
        $this->work();
        $this->assertSame(2, PrintJob::query()->count());

        $this->agentCall('POST', 'jobs/claim', ['limit' => 0])->assertStatus(422);
        $claims = $this->agentCall('POST', 'jobs/claim', ['limit' => 5])->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->agentCall('POST', 'jobs/claim')->assertOk()->assertJsonCount(0, 'data');
        $bar = collect($claims)->firstWhere('printerId', $this->printers['BARRA']);
        $text = implode("\n", array_column($bar['document'], 'text'));
        $this->assertStringContainsString('COMANDA', $text);
        $this->assertStringContainsString('2 x Agua mineral', $text);
        $this->assertStringContainsString('+ Grande', $text);
        $this->assertStringContainsString('Nota: Sin hielo', $text);
        $this->assertStringNotContainsString('Tacos', $text);
        $this->assertSame('CUT', end($bar['document'])['kind']);
        $this->assertSame('CLAIMED', $bar['job']['status']);
        $this->assertSame(1, $bar['job']['attempts']);

        $attempt = $bar['attemptId'];
        $this->agentCall('POST', "attempts/$attempt/result", ['outcome' => 'PRINTED'])->assertStatus(409)->assertJsonPath('error.code', 'PRINT_JOB_LEASE_LOST');
        $this->agentCall('POST', "attempts/$attempt/start")->assertOk()->assertJsonPath('data.status', 'PRINTING');
        $this->agentCall('POST', "attempts/$attempt/start")->assertOk();
        $this->agentCall('POST', "attempts/$attempt/result", ['outcome' => 'PRINTED'])->assertOk()->assertJsonPath('data.status', 'PRINTED');
        $this->agentCall('POST', "attempts/$attempt/result", ['outcome' => 'PRINTED'])->assertOk();
        $this->assertNotNull($this->as('cocina', 'GET', "print-jobs/{$bar['job']['id']}")->assertOk()->json('data.printedAt'));
        $this->as('cocina', 'GET', "print-jobs/{$bar['job']['id']}/attempts")->assertOk()->assertJsonPath('data.0.status', 'PRINTED')
            ->assertJsonPath('data.0.agentId', $this->agent);
        $this->agentCall('POST', 'attempts/00000000-0000-4000-8000-000000000000/start')->assertStatus(404);
        // Un empleado no entra a las rutas del agente.
        $this->as('cocina', 'POST', '/print-agent/jobs/claim')->assertStatus(401);
    }

    public function test_no_enviado_se_reintenta_tres_veces_y_luego_lo_decide_una_persona(): void
    {
        $this->order();
        $kitchen = $this->printers['COCINA'];
        // Solo la impresora de cocina queda activa para el agente.
        $this->as('gerente', 'POST', "printers/{$this->printers['BARRA']}/status", ['status' => 'INACTIVE', 'reason' => 'Fuera de servicio'], ['If-Match' => '"1"'])->assertOk();

        for ($i = 1; $i <= 3; $i++) {
            $claim = $this->agentCall('POST', 'jobs/claim')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
            $this->assertSame($kitchen, $claim['printerId']);
            $this->agentCall('POST', "attempts/{$claim['attemptId']}/start")->assertOk();
            $job = $this->agentCall('POST', "attempts/{$claim['attemptId']}/result", ['outcome' => 'NOT_SENT', 'errorCode' => 'CONNECTION_REFUSED'])->assertOk()->json('data');
            if ($i < 3) {
                $this->assertSame('PENDING', $job['status']);
                // Espera antes del siguiente intento.
                $this->agentCall('POST', 'jobs/claim')->assertJsonCount(0, 'data');
                $this->travel(30)->seconds();
            }
        }
        $this->assertSame('FAILED', $job['status']);
        $this->assertSame('CONNECTION_REFUSED', $job['lastError']['code']);
        $this->assertSame(1, $this->events('print_job.failed'));
        $this->as('cocina', 'GET', "print-jobs/{$job['id']}/attempts")->assertJsonCount(3, 'data');

        $path = "print-jobs/{$job['id']}";
        $this->as('cocina', 'POST', "$path/retry", [], ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->as('cocina', 'POST', "$path/resolve", ['outcome' => 'PRINTED', 'reason' => 'Ya salió'], ['If-Match' => '"'.$job['version'].'"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PRINT_JOB_INVALID_STATE');
        $this->as('cocina', 'POST', "$path/retry", ['printerId' => $this->printers['BARRA']], ['If-Match' => '"'.$job['version'].'"'])->assertStatus(422);
        $retried = $this->as('cocina', 'POST', "$path/retry", [], ['If-Match' => '"'.$job['version'].'"'])->assertOk()->json('data');
        $this->assertSame(['PENDING', 0, null, null], [$retried['status'], $retried['attempts'], $retried['lastError'], $retried['expiresAt']]);
        $this->agentCall('POST', 'jobs/claim')->assertJsonCount(1, 'data');
        $this->assertSame(1, $this->auditCount('print_job.retried'));
    }

    public function test_plazos_vencidos_resolver_reimprimir_y_cancelar(): void
    {
        $this->order();
        $claims = $this->agentCall('POST', 'jobs/claim', ['limit' => 2])->json('data');
        [$first, $second] = $claims;
        $this->agentCall('POST', "attempts/{$second['attemptId']}/start")->assertOk();

        // Vence el plazo: el reclamado vuelve a PENDING; el que imprimía queda UNKNOWN.
        $this->travel(61)->seconds();
        $this->agentCall('POST', "attempts/{$first['attemptId']}/start")->assertStatus(409)->assertJsonPath('error.code', 'PRINT_JOB_LEASE_LOST');
        $this->work();
        $this->assertSame('PENDING', $this->job($first['job']['id'])->status);
        $this->assertSame('UNKNOWN', $this->job($second['job']['id'])->status);
        $this->assertSame(1, $this->events('print_job.unknown'));
        $this->agentCall('POST', "attempts/{$first['attemptId']}/start")->assertStatus(409);

        // El resultado tardío del intento que imprimía se acepta.
        $this->agentCall('POST', "attempts/{$second['attemptId']}/result", ['outcome' => 'PRINTED'])->assertOk()->assertJsonPath('data.status', 'PRINTED');

        // El otro vuelve a salir, se corta a medias y una persona decide.
        $again = $this->agentCall('POST', 'jobs/claim')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(2, $again['job']['attempts']);
        $this->agentCall('POST', "attempts/{$again['attemptId']}/start")->assertOk();
        $unknown = $this->agentCall('POST', "attempts/{$again['attemptId']}/result", ['outcome' => 'UNCERTAIN'])->assertOk()->assertJsonPath('data.status', 'UNKNOWN')->json('data');
        $path = "print-jobs/{$unknown['id']}";
        $v = ['If-Match' => '"'.$unknown['version'].'"'];
        $this->as('cocina', 'POST', "$path/retry", [], $v)->assertStatus(409)->assertJsonPath('error.code', 'PRINT_JOB_UNKNOWN');
        $resolved = $this->as('cocina', 'POST', "$path/resolve", ['outcome' => 'NOT_PRINTED', 'reason' => 'No salió el papel'], $v)->assertOk()
            ->assertJsonPath('data.status', 'FAILED')->json('data');
        // Ya resuelto, el resultado tardío del agente queda fuera.
        $this->agentCall('POST', "attempts/{$again['attemptId']}/result", ['outcome' => 'PRINTED'])->assertStatus(409)->assertJsonPath('error.code', 'PRINT_JOB_LEASE_LOST');
        $this->as('mesero', 'POST', "$path/cancel", ['reason' => 'Se resolvió a mano'], ['If-Match' => '"'.$resolved['version'].'"'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->as('mesero', 'POST', "$path/reprint", ['reason' => 'Otra copia'], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'PRINT_JOB_INVALID_STATE');

        // Reimprimir el que salió: trabajo nuevo, con el contenido original y la marca.
        $key = $this->key();
        $copy = $this->as('cocina', 'POST', "print-jobs/{$second['job']['id']}/reprint", ['reason' => 'Se manchó'], $key)->assertCreated()->json('data');
        $this->as('cocina', 'POST', "print-jobs/{$second['job']['id']}/reprint", ['reason' => 'Se manchó'], $key)->assertCreated()->assertJsonPath('data.id', $copy['id']);
        $this->as('cocina', 'POST', "print-jobs/{$second['job']['id']}/reprint", ['reason' => 'Otro motivo'], $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame([$second['job']['id'], 'Se manchó', $this->staff['cocina']['user']->uuid], [$copy['originalJobId'], $copy['reason'], $copy['createdBy']]);
        $printed = $this->agentCall('POST', 'jobs/claim')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('*** REIMPRESIÓN ***', $printed['document'][0]['text']);
        $this->assertSame(array_slice($printed['document'], 2), $second['document']);
    }

    public function test_comanda_vencida_cancelada_anulada_y_sin_ruta(): void
    {
        $order = $this->order();
        // PR-10: si todo lo de cocina se cancela antes de imprimir, su comanda no sale.
        $this->transition('mesero', 'CANCEL', $this->unitsOf($order, 1), 'Se equivocó')->assertOk();
        $this->work();
        $tickets = PrintJob::query()->where('type', 'KITCHEN_TICKET')->get()->keyBy(fn ($j) => (string) $j->station_id);
        $kitchenId = (string) Station::query()->where('code', 'COCINA')->first()->getKey();
        $this->assertSame('CANCELLED', $tickets[$kitchenId]->status);

        // PR-9: la comanda de barra nunca se reclama y vence a los 15 minutos.
        $this->travel(16)->minutes();
        $this->work();
        $bar = $tickets->first(fn ($j) => (string) $j->station_id !== $kitchenId)->refresh();
        $this->assertSame(['FAILED', 'PRINT_JOB_EXPIRED'], [$bar->status, $bar->last_error['code']]);
        $this->assertSame(1, $this->events('print_job.failed'));
        $this->travelBack();

        // Una anulación imprime su aviso donde se preparaba.
        $order = $this->as('mesero', 'POST', 'orders', $this->round($this->as('mesero', 'GET', 'table-sessions')->json('data.0.id')), $this->key())->json('data');
        $tacos = $this->unitsOf($order, 1);
        $this->transition('cocina', 'START', $tacos)->assertOk();
        $this->transition('gerente', 'VOID', $this->bump($tacos), 'Cliente se fue')->assertOk();
        $this->work();
        $notice = PrintJob::query()->where('type', 'VOID_NOTICE')->firstOrFail();
        $this->assertSame($this->printers['COCINA'], Printer::query()->find((string) $notice->printer_id)->uuid);
        $this->assertStringContainsString('Motivo: Cliente se fue', implode("\n", array_column($notice->document, 'text')));

        // Sin ruta para una estación queda una alerta, y la comanda sigue su curso.
        $this->as('gerente', 'POST', 'print-stations/'.$this->as('gerente', 'GET', 'print-stations')->json('data.0.id').'/status', ['status' => 'INACTIVE', 'reason' => 'Se quita'], ['If-Match' => '"1"'])->assertOk();
        $this->as('mesero', 'POST', 'orders', $this->round($this->as('mesero', 'GET', 'table-sessions')->json('data.0.id')), $this->key())->assertCreated();
        $this->work();
        $this->assertSame(1, $this->events('print_job.route_missing'));
    }

    public function test_peticion_a_mano_y_agente_sin_latido(): void
    {
        $body = ['type' => 'TEST', 'sourceId' => $this->printers['COCINA']];
        $this->as('gerente', 'POST', 'print-jobs', $body)->assertStatus(422);
        $this->as('mesero', 'POST', 'print-jobs', $body, $this->key())->assertStatus(403);
        $this->as('cocina', 'POST', 'print-jobs', $body, $this->key())->assertStatus(403);
        $this->as('gerente', 'POST', 'print-jobs', ['type' => 'PRE_CHECK', 'sourceId' => $this->printers['COCINA']], $this->key())->assertStatus(422);
        $test = $this->as('gerente', 'POST', 'print-jobs', $body, $this->key())->assertCreated()->assertJsonPath('data.priority', 'LOW')
            ->assertJsonPath('data.createdBy', $this->staff['gerente']['user']->uuid)->json('data');
        $claim = $this->agentCall('POST', 'jobs/claim')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame($test['id'], $claim['job']['id']);
        $this->assertSame('PRUEBA DE IMPRESIÓN', $claim['document'][0]['text']);
        $this->assertSame(1, $this->auditCount('print_job.requested'));

        // Sin latido por 2 minutos se avisa una sola vez, y el latido limpia el aviso.
        $this->agentCall('POST', 'heartbeat')->assertOk();
        $this->travel(3)->minutes();
        $this->work();
        $this->work();
        $this->assertSame(1, $this->events('print_agent.offline'));
        $this->agentCall('POST', 'heartbeat')->assertOk();
        $this->travel(3)->minutes();
        $this->work();
        $this->assertSame(2, $this->events('print_agent.offline'));
    }

    public function test_precuenta_recibo_y_reporte_de_caja(): void
    {
        $this->order();
        $session = $this->as('mesero', 'GET', 'table-sessions')->json('data.0.id');
        $check = $this->as('mesero', 'GET', "checks?sessionId=$session")->json('data.0');
        $text = fn (string $id) => implode("\n", array_map(fn ($l) => trim(($l['text'] ?? '').' '.($l['right'] ?? '')), $this->job($id)->document));

        // Sin una ruta para el tipo no hay a dónde mandarlo.
        $this->as('mesero', 'POST', 'print-jobs', ['type' => 'PRE_CHECK', 'sourceId' => $check['id']], $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'PRINT_ROUTE_NOT_CONFIGURED');
        $route = $this->as('gerente', 'POST', 'print-stations', [
            'code' => 'CAJA', 'name' => 'Caja', 'jobTypes' => ['PRE_CHECK', 'PAYMENT_RECEIPT', 'CASH_SHIFT_REPORT'], 'printerId' => $this->printers['BARRA'],
        ])->assertCreated()->json('data.id');
        $kitchenRoute = collect($this->as('gerente', 'GET', 'print-stations')->json('data'))->firstWhere('code', 'COMANDA-COCINA')['id'];

        $this->as('mesero', 'POST', 'print-jobs', ['type' => 'PRE_CHECK', 'sourceId' => (string) Str::uuid()], $this->key())->assertStatus(422);
        $this->as('mesero', 'POST', 'print-jobs', ['type' => 'PRE_CHECK', 'sourceId' => $check['id'], 'printStationId' => $kitchenRoute], $this->key())->assertStatus(422);
        $pre = $this->as('mesero', 'POST', 'print-jobs', ['type' => 'PRE_CHECK', 'sourceId' => $check['id'], 'printStationId' => $route], $this->key())->assertCreated()
            ->assertJsonPath('data.priority', 'NORMAL')->assertJsonPath('data.sourceType', 'check')->assertJsonPath('data.sourceId', $check['id'])
            ->assertJsonPath('data.printerId', $this->printers['BARRA'])->assertJsonPath('data.printStationId', $route)->json('data');
        $doc = $text($pre['id']);
        $this->assertStringContainsString('PRECUENTA', $doc);
        $this->assertStringContainsString('TOTAL $'.number_format((float) $check['total']['amount'], 2), $doc);
        $this->assertStringContainsString('2 x ', $doc);

        // Un recibo solo existe para un pago confirmado, y no lleva datos de la tarjeta más allá de la marca.
        $shift = $this->cashShift();
        $half = bcdiv($check['balance']['amount'], '2', 2);
        $card = $this->as('mesero', 'POST', 'payments', [
            'checkId' => $check['id'], 'cashShiftId' => $shift, 'method' => 'CARD_CREDIT', 'amount' => ['amount' => $half, 'currency' => 'MXN'],
            'tip' => ['amount' => '20.00', 'currency' => 'MXN'],
        ], $this->key())->assertCreated()->json('data');
        $this->as('mesero', 'POST', 'print-jobs', ['type' => 'PAYMENT_RECEIPT', 'sourceId' => $card['id']], $this->key())->assertStatus(422);
        $this->as('mesero', 'POST', "payments/{$card['id']}/result", [
            'outcome' => 'CONFIRMED', 'reference' => 'TERM-1', 'card' => ['brand' => 'VISA', 'last4' => '4242', 'authCode' => 'A1B2'],
        ], ['If-Match' => '"1"'] + $this->key())->assertOk();
        $receipt = $this->as('mesero', 'POST', 'print-jobs', ['type' => 'PAYMENT_RECEIPT', 'sourceId' => $card['id']], $this->key())->assertCreated()
            ->assertJsonPath('data.sourceType', 'payment')->json('data');
        $doc = $text($receipt['id']);
        $this->assertStringContainsString('Tarjeta de crédito VISA', $doc);
        $this->assertStringContainsString('Propina $20.00', $doc);
        $this->assertStringContainsString('no es un comprobante fiscal', $doc);
        $this->assertStringNotContainsString('4242', $doc);
        $this->assertStringNotContainsString('A1B2', $doc);

        // El reporte de caja lleva importes: exige cash.read. Con el turno abierto sale como parcial.
        $this->as('mesero', 'POST', 'print-jobs', ['type' => 'CASH_SHIFT_REPORT', 'sourceId' => $shift], $this->key())->assertStatus(403);
        $report = $this->as('gerente', 'POST', 'print-jobs', ['type' => 'CASH_SHIFT_REPORT', 'sourceId' => $shift], $this->key())->assertCreated()
            ->assertJsonPath('data.priority', 'LOW')->assertJsonPath('data.sourceType', 'cash_shift')->json('data');
        $doc = $text($report['id']);
        $this->assertStringContainsString('PARCIAL', $doc);
        $this->assertStringContainsString('Ventas $'.number_format((float) $half, 2), $doc);
        $this->assertStringContainsString('Propinas $20.00', $doc);

        // El agente los recibe como cualquier otro trabajo.
        $claimed = collect($this->agentCall('POST', 'jobs/claim', ['limit' => 10])->assertOk()->json('data'))->pluck('job.id')->all();
        $this->assertContains($pre['id'], $claimed);
        $this->assertSame(3, $this->auditCount('print_job.requested'));
    }
}
