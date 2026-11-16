<?php

namespace Tests\Feature\Fiscal;

use App\Fiscal\Cancelling;
use App\Fiscal\Stamping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Floor\FloorTestCase;

/** Factura global y exclusión de cuentas (F-6, F-7) con el PAC simulado. */
class GlobalInvoicesTest extends FloorTestCase
{
    use ConfiguresIssuer;
    use MakesInvoices;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
        $this->readyIssuer();
        $this->today = Carbon::now($this->branch->timezone ?: 'America/Mexico_City');
    }

    /** Lote del mes en curso, hasta hoy. */
    private function batch(array $headers = [], string $who = 'gerente', ?array $body = null): TestResponse
    {
        $body ??= ['periodFrom' => $this->today->copy()->startOfMonth()->toDateString(), 'periodTo' => $this->today->toDateString()];

        return $this->as($who, 'POST', 'invoice-batches', $body, $headers ?: $this->key());
    }

    private function exclude(array $batch, array $checks, array $headers = [], string $who = 'gerente'): TestResponse
    {
        return $this->as($who, 'POST', "invoice-batches/{$batch['id']}/exclude-checks", ['checkIds' => $checks, 'reason' => 'El cliente pidió su factura'],
            $headers ?: ['If-Match' => '"'.$batch['version'].'"'] + $this->key());
    }

    private function stampAs(string $who, array $invoice): TestResponse
    {
        return $this->as($who, 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($invoice) + $this->key());
    }

    /** Cuenta de 207.00 cobrada en dos pagos (107 y 100), con el de 100 reembolsado: quedan 107.00. */
    private function partiallyRefundedCheck(int $table): array
    {
        $session = $this->openSession($table);
        $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated();
        $check = $this->as('mesero', 'GET', "checks?sessionId=$session")->json('data.0');
        $shift = $this->cashShift();
        $pay = fn (string $amount) => $this->as('mesero', 'POST', 'payments', [
            'checkId' => $check['id'], 'cashShiftId' => $shift, 'method' => 'CASH',
            'amount' => ['amount' => $amount, 'currency' => 'MXN'], 'amountTendered' => ['amount' => $amount, 'currency' => 'MXN'],
        ], $this->key())->assertCreated()->json('data');
        $pay('107');
        $second = $pay('100');
        $this->as('gerente', 'POST', "payments/{$second['id']}/refunds", ['cashShiftId' => $shift, 'reason' => 'Cobro de más'], $this->key())->assertCreated();

        return $this->as('mesero', 'GET', "checks/{$check['id']}")->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.refundedTotal.amount', '100.00')->json('data');
    }

    public function test_global_del_periodo_con_reembolso_parcial_y_timbrado(): void
    {
        $full = $this->paidCheck(1);
        $partial = $this->partiallyRefundedCheck(2);
        $nominative = $this->paidCheck(3);
        $this->create($nominative['id'])->assertCreated();

        $this->batch(who: 'mesero')->assertStatus(403);
        $month = $this->today->copy()->startOfMonth();
        $this->batch(body: ['periodFrom' => $this->today->toDateString(), 'periodTo' => $month->copy()->subDay()->toDateString()])->assertStatus(422);
        $this->batch(body: ['periodFrom' => $this->today->copy()->addDay()->toDateString(), 'periodTo' => $this->today->copy()->addDay()->toDateString()])->assertStatus(422);
        $this->batch(body: ['periodFrom' => $month->copy()->subMonth()->toDateString(), 'periodTo' => $this->today->toDateString()])->assertStatus(422);
        $this->batch(body: ['periodFrom' => '2026/11/01', 'periodTo' => $this->today->toDateString()])->assertStatus(422);

        $key = $this->key();
        $batch = $this->batch($key)->assertCreated()->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.periodicity', 'MONTHLY')->assertJsonPath('data.supersededBy', null)
            ->assertJsonPath('data.checkIds', [$full['id'], $partial['id']])->json('data');
        // Reintento con la misma clave: la misma respuesta. Con otra, ya no quedan cuentas libres.
        $this->batch($key)->assertCreated()->assertJsonPath('data.id', $batch['id']);
        $this->batch()->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_BATCH_EMPTY');

        $global = $this->as('gerente', 'GET', "invoices/{$batch['invoiceId']}")->assertOk()
            ->assertJsonPath('data.scope', 'GLOBAL')->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.batchId', $batch['id'])
            ->assertJsonPath('data.receiver', null)->assertJsonPath('data.checkId', null)->assertJsonPath('data.satPaymentForm', '01')
            ->assertJsonPath('data.subtotal.amount', '270.68')->assertJsonPath('data.taxTotal.amount', '43.32')->assertJsonPath('data.total.amount', '314.00')
            ->json('data');
        // Un concepto por cuenta: la completa con la base y el IVA de la cuenta; la otra, por lo que quedó tras el reembolso.
        $this->assertSame([
            ['01010101', 'ACT', '1', '178.44', '28.56', 'Venta'],
            ['01010101', 'ACT', '1', '92.24', '14.76', 'Venta'],
        ], array_map(fn ($l) => [$l['satProductCode'], $l['satUnitCode'], $l['quantity'], $l['amount']['amount'], $l['taxAmount']['amount'], $l['description']],
            $global['lines']));

        // La cuenta está en la global en borrador.
        $this->create($full['id'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_CHECK_ALREADY_INVOICED');
        $this->as('mesero', 'GET', "checks/{$full['id']}")->assertJsonPath('data.activeInvoiceId', $global['id']);
        // La global la maneja el gerente: el mesero no la timbra ni la descarta, y no lleva receptor.
        $this->stampAs('mesero', $global)->assertStatus(403);
        $this->as('mesero', 'POST', "invoices/{$global['id']}/discard", ['reason' => 'Equivocado'], $this->v($global))->assertStatus(403);
        $this->as('gerente', 'PATCH', "invoices/{$global['id']}", ['receiver' => $this->receiver], $this->v($global))->assertStatus(422);

        $this->stampAs('gerente', $global)->assertStatus(202);
        Stamping::run();
        $this->as('gerente', 'GET', "invoices/{$global['id']}")->assertJsonPath('data.status', 'STAMPED');
        $xml = $this->as('gerente', 'GET', "invoices/{$global['id']}/xml")->assertOk()->getContent();
        $this->assertStringContainsString('Periodicidad="04" Meses="'.$this->today->format('m').'" Año="'.$this->today->year.'"', $xml);
        $this->assertStringContainsString('Rfc="XAXX010101000" Nombre="PUBLICO EN GENERAL"', $xml);
        $this->assertStringContainsString('NoIdentificacion="'.$full['number'].'"', $xml);
        $this->as('gerente', 'GET', "invoices/{$global['id']}/pdf")->assertOk();

        // Una cuenta de una global timbrada sale con excludeChecksFromBatch.
        $this->create($full['id'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_CHECK_IN_STAMPED_GLOBAL');
        $this->as('gerente', 'GET', 'invoice-batches?status=ACTIVE')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.id', $batch['id']);
        $this->as('gerente', 'GET', 'invoice-batches?status=SUPERSEDED')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->as('mesero', 'GET', 'invoice-batches')->assertStatus(403);
        $this->as('gerente', 'GET', "invoice-batches/{$batch['id']}")->assertOk()->assertHeader('ETag', '"1"')->assertJsonPath('data.invoiceId', $global['id']);
    }

    public function test_excluir_una_cuenta_cancela_con_motivo_04_y_reemite(): void
    {
        $leaving = $this->paidCheck(1);
        $staying = $this->paidCheck(2);
        $batch = $this->batch()->assertCreated()->json('data');
        $global = $this->invoice($batch['invoiceId']);
        $this->exclude($batch, [$leaving['id']])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');
        $this->stampAs('gerente', $global)->assertStatus(202);
        Stamping::run();

        $this->exclude($batch, [$leaving['id']], who: 'mesero')->assertStatus(403);
        $this->exclude($batch, [$leaving['id']], $this->key())->assertStatus(400);
        $this->exclude($batch, [$leaving['id']], ['If-Match' => '"9"'] + $this->key())->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $other = $this->paidCheck(3);
        $this->exclude($batch, [$other['id']])->assertStatus(422);

        $key = ['If-Match' => '"1"'] + $this->key();
        $replacement = $this->exclude($batch, [$leaving['id']], $key)->assertStatus(202)->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.checkIds', [$staying['id']])->json('data');
        $this->exclude($batch, [$leaving['id']], $key)->assertStatus(202)->assertJsonPath('data.id', $replacement['id']);
        $this->as('gerente', 'GET', "invoice-batches/{$batch['id']}")->assertJsonPath('data.status', 'SUPERSEDED')
            ->assertJsonPath('data.supersededBy', $replacement['id'])->assertHeader('ETag', '"2"');
        $this->exclude($this->as('gerente', 'GET', "invoice-batches/{$batch['id']}")->json('data'), [$staying['id']])
            ->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');
        $this->as('gerente', 'GET', "invoices/{$global['id']}")->assertJsonPath('data.status', 'CANCELLATION_REQUESTED')
            ->assertJsonPath('data.cancellation.reasonCode', '04');

        // La de reemplazo espera a que la original esté cancelada; mientras, la cuenta sigue en la original.
        $new = $this->invoice($replacement['invoiceId']);
        $this->assertSame(['GLOBAL', 'DRAFT', '207.00'], [$new['scope'], $new['status'], $new['total']['amount']]);
        $this->stampAs('gerente', $new)->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');
        $this->create($leaving['id'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_CHECK_IN_STAMPED_GLOBAL');

        $this->assertSame(1, Cancelling::run()['cancelled']);
        $this->as('gerente', 'GET', "invoices/{$global['id']}")->assertJsonPath('data.status', 'CANCELLED');
        // La que queda pasa a la de reemplazo; la excluida queda libre para su factura nominativa.
        $this->as('mesero', 'GET', "checks/{$staying['id']}")->assertJsonPath('data.activeInvoiceId', $new['id']);
        $this->as('mesero', 'GET', "checks/{$leaving['id']}")->assertJsonPath('data.activeInvoiceId', null);
        $this->create($leaving['id'])->assertCreated()->assertJsonPath('data.scope', 'NOMINATIVE');

        $this->stampAs('gerente', $this->invoice($new['id']))->assertStatus(202);
        Stamping::run();
        $this->as('gerente', 'GET', "invoices/{$new['id']}")->assertJsonPath('data.status', 'STAMPED');
    }

    public function test_excluir_todas_las_cuentas_no_crea_reemplazo(): void
    {
        $only = $this->paidCheck(1);
        $batch = $this->batch()->assertCreated()->json('data');
        $this->stampAs('gerente', $this->invoice($batch['invoiceId']))->assertStatus(202);
        Stamping::run();

        $this->exclude($batch, [$only['id']])->assertStatus(202)->assertJsonPath('data.id', $batch['id'])
            ->assertJsonPath('data.status', 'SUPERSEDED')->assertJsonPath('data.supersededBy', null);
        Cancelling::run();
        $this->as('mesero', 'GET', "checks/{$only['id']}")->assertJsonPath('data.activeInvoiceId', null);
        $this->create($only['id'])->assertCreated();
    }

    public function test_descartar_el_borrador_libera_las_cuentas(): void
    {
        $check = $this->paidCheck(1);
        $batch = $this->batch()->assertCreated()->json('data');
        $global = $this->invoice($batch['invoiceId']);

        $this->as('gerente', 'POST', "invoices/{$global['id']}/discard", ['reason' => 'Periodo equivocado'], $this->v($global))->assertOk()
            ->assertJsonPath('data.status', 'DISCARDED');
        $this->as('gerente', 'GET', "invoice-batches/{$batch['id']}")->assertJsonPath('data.status', 'SUPERSEDED');
        $this->as('mesero', 'GET', "checks/{$check['id']}")->assertJsonPath('data.activeInvoiceId', null);
        $this->batch()->assertCreated()->assertJsonPath('data.checkIds', [$check['id']]);
    }
}
