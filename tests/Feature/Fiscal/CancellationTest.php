<?php

namespace Tests\Feature\Fiscal;

use App\Fiscal\Cancelling;
use App\Fiscal\Pac\CancelRequest;
use App\Fiscal\Pac\CancelResult;
use App\Fiscal\Pac\CsdInfo;
use App\Fiscal\Pac\Pac;
use App\Fiscal\Pac\PacUnreachable;
use App\Fiscal\Pac\SimulatedPac;
use App\Fiscal\Pac\StampRequest;
use App\Fiscal\Pac\StampResult;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Floor\FloorTestCase;

/** Cancelación y sustitución de facturas con el PAC simulado (Decisiones_Fiscal, sección 4). */
class CancellationTest extends FloorTestCase
{
    use ConfiguresIssuer;
    use MakesInvoices;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
        $this->readyIssuer();
    }

    private function cancel(array $invoice, array $body, array $headers = []): TestResponse
    {
        return $this->as('gerente', 'POST', "invoices/{$invoice['id']}/cancel", $body, ($headers ?: $this->v($invoice) + $this->key()));
    }

    /** La siguiente revisión ya venció: simula el paso del tiempo. */
    private function due(string $id): void
    {
        Invoice::query()->where('uuid', $id)->update(['cancel_next_at' => now()->subSecond()]);
    }

    public function test_cancelar_sin_aceptacion_libera_la_cuenta(): void
    {
        $check = $this->paidCheck();
        $draft = $this->create($check['id'])->json('data');
        $this->cancel($draft, ['reasonCode' => '02', 'reason' => 'RFC equivocado'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');
        $invoice = $this->stamped($draft);

        $body = ['reasonCode' => '02', 'reason' => 'RFC equivocado'];
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/cancel", $body, $this->v($invoice) + $this->key())->assertStatus(403);
        $this->cancel($invoice, $body, $this->key())->assertStatus(400);
        $this->cancel($invoice, ['reasonCode' => '05', 'reason' => 'Otro'])->assertStatus(422);
        $this->cancel($invoice, ['reasonCode' => '04', 'reason' => 'En global'])->assertStatus(422);
        $this->cancel($invoice, $body + ['replacementInvoiceId' => $invoice['id']])->assertStatus(422);
        $this->cancel($invoice, ['reasonCode' => '01', 'reason' => 'Sin sustituta'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_REPLACEMENT_REQUIRED');

        $key = $this->key();
        $requested = $this->cancel($invoice, $body, $this->v($invoice) + $key)->assertStatus(202)
            ->assertJsonPath('data.status', 'CANCELLATION_REQUESTED')->assertJsonPath('data.cancellation.reasonCode', '02')
            ->assertJsonPath('data.cancellation.replacementInvoiceId', null)->assertJsonPath('data.cancellation.satStatus', null)->json('data');
        $this->assertNotNull($requested['cancellation']['requestedAt']);
        // Reintento de red con la misma clave: la misma respuesta.
        $this->cancel($invoice, $body, $this->v($invoice) + $key)->assertStatus(202)->assertJsonPath('data.version', $requested['version']);
        $this->cancel($requested, $body)->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');
        // Mientras se cancela, la cuenta sigue facturada.
        $this->create($check['id'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_CHECK_ALREADY_INVOICED');

        $this->assertSame(1, Cancelling::run()['cancelled']);
        $cancelled = $this->invoice($invoice['id']);
        $this->assertSame(['CANCELLED', 'Cancelado sin aceptación'], [$cancelled['status'], $cancelled['cancellation']['satStatus']]);
        $this->assertSame(0, Cancelling::run()['cancelled']);

        // La cuenta queda libre y el lado comercial no cambia.
        $this->as('mesero', 'GET', "checks/{$check['id']}")->assertJsonPath('data.activeInvoiceId', null)->assertJsonPath('data.status', 'PAID');
        $this->create($check['id'])->assertCreated();
        $this->as('mesero', 'GET', "invoices/{$invoice['id']}/xml")->assertOk();

        $events = $this->as('gerente', 'GET', "invoices/{$invoice['id']}/events")->json('data');
        $this->assertSame([['STAMPED', 'CANCELLATION_REQUESTED'], ['CANCELLATION_REQUESTED', 'CANCELLED']],
            array_map(fn ($e) => [$e['fromStatus'], $e['toStatus']], array_slice($events, -2)));
        $this->assertSame('RFC equivocado', DB::connection('mongodb')->table('audit_log')->where('action', 'invoice.cancellation_requested')->first()->reason);
        foreach (['invoice.cancellation_requested', 'invoice.cancelled'] as $type) {
            $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', $type)->count());
        }
    }

    public function test_sustitucion_con_motivo_01(): void
    {
        $check = $this->paidCheck();
        $original = $this->stamped($this->create($check['id'])->json('data'));
        $other = $this->paidCheck(2);
        $this->create($other['id'], extra: ['replacesInvoiceId' => $original['id']])->assertStatus(422);

        // Una sustituta a la vez; al descartarla, la original admite otra.
        $first = $this->create($check['id'], ['cfdiUse' => 'G01'], extra: ['replacesInvoiceId' => $original['id']])->assertCreated()
            ->assertJsonPath('data.replacesInvoiceId', $original['id'])->assertJsonPath('data.status', 'DRAFT')->json('data');
        $this->create($check['id'], extra: ['replacesInvoiceId' => $original['id']])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');
        $this->as('mesero', 'POST', "invoices/{$first['id']}/discard", ['reason' => 'Uso equivocado'], $this->v($first))->assertOk();
        $replacement = $this->create($check['id'], ['cfdiUse' => 'G01'], extra: ['replacesInvoiceId' => $original['id']])->assertCreated()->json('data');

        // Con una sustituta en curso, la original solo se cancela con motivo 01 y la sustituta timbrada.
        $original = $this->invoice($original['id']);
        $this->cancel($original, ['reasonCode' => '02', 'reason' => 'Uso equivocado'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');
        $this->cancel($original, ['reasonCode' => '01', 'reason' => 'Uso equivocado', 'replacementInvoiceId' => $replacement['id']])
            ->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_REPLACEMENT_REQUIRED');
        $this->as('mesero', 'GET', "checks/{$check['id']}")->assertJsonPath('data.activeInvoiceId', $original['id']);

        // Timbrada, la sustituta se relaciona con la original (04) y toma la cuenta.
        $replacement = $this->stamped($replacement);
        $this->assertSame(['STAMPED', '2'], [$replacement['status'], $replacement['folio']]);
        $xml = $this->as('mesero', 'GET', "invoices/{$replacement['id']}/xml")->getContent();
        $this->assertStringContainsString('TipoRelacion="04"', $xml);
        $this->assertStringContainsString('UUID="'.$original['fiscalUuid'].'"', $xml);
        $this->as('mesero', 'GET', "checks/{$check['id']}")->assertJsonPath('data.activeInvoiceId', $replacement['id']);

        $this->cancel($original, ['reasonCode' => '01', 'reason' => 'Uso equivocado', 'replacementInvoiceId' => $replacement['id']])
            ->assertStatus(202)->assertJsonPath('data.cancellation.replacementInvoiceId', $replacement['id']);
        $this->assertSame(1, Cancelling::run()['cancelled']);
        $this->assertSame('CANCELLED', $this->invoice($original['id'])['status']);
        // La cuenta sigue con la sustituta.
        $this->as('mesero', 'GET', "checks/{$check['id']}")->assertJsonPath('data.activeInvoiceId', $replacement['id']);
        $this->as('mesero', 'GET', "invoices?checkId={$check['id']}&status=STAMPED")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $replacement['id']);
    }

    public function test_el_receptor_acepta_o_rechaza(): void
    {
        $rejects = $this->stamped($this->create($this->paidCheck()['id'], ['legalName' => 'EMPRESA SIMULAR RECEPTOR RECHAZA'])->json('data'));
        $this->cancel($rejects, ['reasonCode' => '02', 'reason' => 'Datos equivocados'])->assertStatus(202);
        $this->assertSame(1, Cancelling::run()['in_progress']);
        $waiting = $this->invoice($rejects['id']);
        $this->assertSame(['CANCELLATION_IN_PROGRESS', 'En proceso'], [$waiting['status'], $waiting['cancellation']['satStatus']]);
        // No se consulta antes de tiempo.
        $this->assertSame(['cancelled' => 0, 'in_progress' => 0, 'rejected' => 0, 'retry' => 0], Cancelling::run());

        $this->due($rejects['id']);
        $this->assertSame(1, Cancelling::run()['rejected']);
        $rejected = $this->invoice($rejects['id']);
        $this->assertSame(['CANCELLATION_REJECTED', 'Solicitud rechazada'], [$rejected['status'], $rejected['cancellation']['satStatus']]);
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'invoice.cancellation_rejected')->count());
        // Sigue vigente: se puede volver a solicitar.
        $this->cancel($rejected, ['reasonCode' => '02', 'reason' => 'Segundo intento'])->assertStatus(202)->assertJsonPath('data.cancellation.satStatus', null);

        $accepts = $this->stamped($this->create($this->paidCheck(2)['id'], ['legalName' => 'EMPRESA SIMULAR RECEPTOR ACEPTA'])->json('data'));
        $this->cancel($accepts, ['reasonCode' => '03', 'reason' => 'No se llevó a cabo'])->assertStatus(202);
        Cancelling::run();
        $this->due($accepts['id']);
        Cancelling::run();
        $cancelled = $this->invoice($accepts['id']);
        $this->assertSame(['CANCELLED', 'Cancelado con aceptación'], [$cancelled['status'], $cancelled['cancellation']['satStatus']]);
    }

    public function test_no_cancelable_y_sin_respuesta_del_pac(): void
    {
        $blocked = $this->stamped($this->create($this->paidCheck()['id'], ['legalName' => 'EMPRESA SIMULAR NO CANCELABLE'])->json('data'));
        $this->cancel($blocked, ['reasonCode' => '02', 'reason' => 'Datos equivocados'])->assertStatus(202);
        $this->assertSame(1, Cancelling::run()['rejected']);
        $rejected = $this->invoice($blocked['id']);
        $this->assertSame(['CANCELLATION_REJECTED', 'CANC_NO_CANCELABLE'], [$rejected['status'], $rejected['lastError']['code']]);

        // Sin respuesta, la solicitud se repite más tarde: cancelar dos veces el mismo folio es seguro.
        $invoice = $this->stamped($this->create($this->paidCheck(2)['id'])->json('data'));
        $this->cancel($invoice, ['reasonCode' => '02', 'reason' => 'Datos equivocados'])->assertStatus(202);
        $simulated = app(Pac::class);
        $this->app->instance(Pac::class, new class($simulated) implements Pac
        {
            public function __construct(private SimulatedPac $pac) {}

            public function registerCsd(string $rfc, string $certificate, string $privateKey, string $password): CsdInfo
            {
                return $this->pac->registerCsd($rfc, $certificate, $privateKey, $password);
            }

            public function stamp(StampRequest $request): StampResult
            {
                return $this->pac->stamp($request);
            }

            public function find(string $reference): ?StampResult
            {
                return $this->pac->find($reference);
            }

            public function cancel(CancelRequest $request): CancelResult
            {
                throw new PacUnreachable('Sin respuesta.');
            }

            public function cancellationStatus(CancelRequest $request): CancelResult
            {
                throw new PacUnreachable('Sin respuesta.');
            }
        });
        $this->assertSame(1, Cancelling::run()['retry']);
        $retry = $this->invoice($invoice['id']);
        $this->assertSame(['CANCELLATION_REQUESTED', 'PAC_UNREACHABLE'], [$retry['status'], $retry['lastError']['code']]);
        $this->assertSame(0, Cancelling::run()['retry']);

        $this->app->instance(Pac::class, $simulated);
        $this->due($invoice['id']);
        $this->assertSame(1, Cancelling::run()['cancelled']);
        $this->assertSame([null, 'CANCELLED'], [$this->invoice($invoice['id'])['lastError'], $this->invoice($invoice['id'])['status']]);
    }
}
