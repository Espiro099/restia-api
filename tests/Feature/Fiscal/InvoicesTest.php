<?php

namespace Tests\Feature\Fiscal;

use App\Fiscal\Stamping;
use App\Models\Check;
use App\Models\Invoice;
use App\Models\InvoiceAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use MongoDB\BSON\ObjectId;
use Tests\Feature\Floor\FloorTestCase;

/** Facturas nominativas PUE con el PAC simulado (F-2 a F-4, F-9 a F-11). */
class InvoicesTest extends FloorTestCase
{
    use ConfiguresIssuer;

    private array $receiver = ['rfc' => 'XIA190128J61', 'legalName' => 'XENON INDUSTRIAL ARTICLES', 'taxRegime' => '601', 'postalCode' => '76343', 'cfdiUse' => 'G03'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
    }

    /** Cuenta de una ronda de 207.00, cobrada en efectivo. */
    private function paidCheck(int $table = 1): array
    {
        $session = $this->openSession($table);
        $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated();
        $this->payAll($session);

        return $this->as('mesero', 'GET', "checks?sessionId=$session")->json('data.0');
    }

    private function create(string $check, array $receiver = [], array $headers = []): TestResponse
    {
        return $this->as('mesero', 'POST', 'invoices', ['checkId' => $check, 'receiver' => $receiver + $this->receiver], ($headers ?: $this->key()));
    }

    private function v(array $invoice): array
    {
        return ['If-Match' => '"'.$invoice['version'].'"'];
    }

    private function invoice(string $id): array
    {
        return $this->as('mesero', 'GET', "invoices/$id")->assertOk()->json('data');
    }

    public function test_borrador_desde_una_cuenta_pagada(): void
    {
        $check = $this->paidCheck();
        $this->create($check['id'])->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_ISSUER_NOT_CONFIGURED');
        $issuer = $this->readyIssuer();

        $session = $this->openSession(2);
        $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated();
        $open = $this->as('mesero', 'GET', "checks?sessionId=$session")->json('data.0');
        $this->create($open['id'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_CHECK_NOT_ELIGIBLE');
        $this->as('cocina', 'POST', 'invoices', ['checkId' => $check['id'], 'receiver' => $this->receiver], $this->key())->assertStatus(403);
        $this->as('mesero', 'POST', 'invoices', ['checkId' => $check['id'], 'receiver' => $this->receiver, 'satPaymentMethod' => 'PPD'], $this->key())->assertStatus(422);
        $this->create($check['id'], ['rfc' => 'xia190128j61'])->assertStatus(422);
        $this->create($check['id'], ['regimen' => '601'])->assertStatus(422);

        $key = $this->key();
        $invoice = $this->create($check['id'], headers: $key)->assertCreated()->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.issuerId', $issuer)->assertJsonPath('data.checkId', $check['id'])
            ->assertJsonPath('data.type', 'INGRESO')->assertJsonPath('data.scope', 'NOMINATIVE')
            ->assertJsonPath('data.satPaymentMethod', 'PUE')->assertJsonPath('data.satPaymentForm', '01')
            ->assertJsonPath('data.subtotal.amount', '178.44')->assertJsonPath('data.taxTotal.amount', '28.56')->assertJsonPath('data.total.amount', '207.00')
            ->assertJsonPath('data.receiver', $this->receiver)->assertJsonPath('data.fiscalUuid', null)->json('data');
        // Un concepto por partida, con base e impuesto por unidad como en la cuenta (C-6).
        $this->assertSame([
            ['2', '43.10', '86.20', '13.80', '90101501', 'E48', '02', '0.160000'],
            ['1', '92.24', '92.24', '14.76', '90101501', 'E48', '02', '0.160000'],
        ], array_map(fn ($l) => [$l['quantity'], $l['unitValue'], $l['amount']['amount'], $l['taxAmount']['amount'], $l['satProductCode'], $l['satUnitCode'], $l['taxObject'], $l['taxRate']],
            $invoice['lines']));
        $this->assertStringContainsString('(Queso extra)', $invoice['lines'][1]['description']);

        // La misma clave devuelve lo mismo; otra clave choca con la factura activa (F-3).
        $this->create($check['id'], headers: $key)->assertCreated()->assertJsonPath('data.id', $invoice['id']);
        $this->create($check['id'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_CHECK_ALREADY_INVOICED');
        $this->as('mesero', 'GET', "checks/{$check['id']}")->assertJsonPath('data.activeInvoiceId', $invoice['id']);
        $this->as('mesero', 'GET', "invoices?checkId={$check['id']}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 1);
        $this->as('mesero', 'GET', 'invoices?status=STAMPED')->assertJsonCount(0, 'data');
        $this->assertSame(1, DB::connection('mongodb')->table('audit_log')->where('action', 'invoice.created')->count());
    }

    public function test_sin_cortesias_y_una_asignacion_por_cuenta(): void
    {
        $this->readyIssuer();
        $session = $this->openSession();
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->json('data');
        $check = $this->as('mesero', 'GET', "checks?sessionId=$session")->json('data.0');
        $tacos = $order['items'][1]['units'][0]['id'];
        $this->as('gerente', 'POST', "checks/{$check['id']}/adjustments", ['unitId' => $tacos, 'type' => 'COURTESY', 'reason' => 'Cumpleaños'],
            ['If-Match' => '"'.$check['version'].'"'])->assertCreated();
        $this->payAll($session);

        // F-9: la unidad en cortesía no entra al CFDI y lo facturado cuadra con lo cobrado.
        $this->create($check['id'])->assertCreated()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.total.amount', '100.00');

        // Dos altas a la vez: el índice parcial deja pasar una sola (sección 5).
        $other = $this->paidCheck(2);
        InvoiceAllocation::query()->create(['branch_id' => new ObjectId($this->branch->getKey()), 'check_id' => new ObjectId(Check::query()->where('uuid', $other['id'])->first()->getKey()),
            'invoice_id' => new ObjectId, 'active' => true, 'closed_at' => null]);
        $this->create($other['id'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_CHECK_ALREADY_INVOICED');
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_corregir_y_descartar_libera_la_cuenta(): void
    {
        $this->readyIssuer();
        $check = $this->paidCheck();
        $invoice = $this->create($check['id'])->json('data');

        $this->as('mesero', 'PATCH', "invoices/{$invoice['id']}", ['receiver' => ['cfdiUse' => 'G01'] + $this->receiver])->assertStatus(400);
        $this->as('mesero', 'PATCH', "invoices/{$invoice['id']}", ['receiver' => ['cfdiUse' => 'G01'] + $this->receiver, 'lines' => []], $this->v($invoice))->assertStatus(422);
        $invoice = $this->as('mesero', 'PATCH', "invoices/{$invoice['id']}", ['receiver' => ['cfdiUse' => 'G01'] + $this->receiver], $this->v($invoice))
            ->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.receiver.cfdiUse', 'G01')->json('data');
        $this->as('mesero', 'PATCH', "invoices/{$invoice['id']}", ['receiver' => $this->receiver], ['If-Match' => '"1"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $discarded = $this->as('mesero', 'POST', "invoices/{$invoice['id']}/discard", ['reason' => 'RFC equivocado'], $this->v($invoice))
            ->assertOk()->assertJsonPath('data.status', 'DISCARDED')->json('data');
        // Repetir no cambia nada; corregir un descartado no se puede.
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/discard", ['reason' => 'RFC equivocado'], $this->v($discarded))->assertOk()->assertJsonPath('data.version', $discarded['version']);
        $this->as('mesero', 'PATCH', "invoices/{$invoice['id']}", ['receiver' => $this->receiver], $this->v($discarded))
            ->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');
        $this->as('mesero', 'GET', "checks/{$check['id']}")->assertJsonPath('data.activeInvoiceId', null);
        $this->create($check['id'])->assertCreated();
        $this->assertSame('RFC equivocado', DB::connection('mongodb')->table('audit_log')->where('action', 'invoice.discarded')->first()->reason);
    }

    public function test_timbrado_asincrono_y_archivos(): void
    {
        $this->readyIssuer();
        $invoice = $this->create($this->paidCheck()['id'])->json('data');
        $this->as('cocina', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($invoice) + $this->key())->assertStatus(403);
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($invoice))->assertStatus(422);
        $key = $this->key();
        $pending = $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($invoice) + $key)
            ->assertStatus(202)->assertJsonPath('data.status', 'PENDING_STAMP')->json('data');
        // Reintento de red con la misma clave: la misma respuesta.
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($invoice) + $key)->assertStatus(202)->assertJsonPath('data.version', $pending['version']);
        $this->as('mesero', 'GET', "invoices/{$invoice['id']}/xml")->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');

        $this->assertSame(1, Stamping::run()['stamped']);
        $stamped = $this->invoice($invoice['id']);
        $this->assertSame(['STAMPED', 'A', '1'], [$stamped['status'], $stamped['series'], $stamped['folio']]);
        $this->assertMatchesRegularExpression('/^[0-9A-F-]{36}$/', $stamped['fiscalUuid']);
        $this->assertNotNull($stamped['stampedAt']);

        $xml = $this->as('mesero', 'GET', "invoices/{$invoice['id']}/xml")->assertOk()->assertHeader('Content-Type', 'application/xml')
            ->assertHeader('Cache-Control', 'no-store, private')->getContent();
        foreach (['UUID="'.$stamped['fiscalUuid'].'"', 'Total="207.00"', 'SubTotal="178.44"', 'TotalImpuestosTrasladados="28.56"', 'Rfc="XIA190128J61"', 'Folio="1"'] as $needle) {
            $this->assertStringContainsString($needle, $xml);
        }
        $pdf = $this->as('mesero', 'GET', "invoices/{$invoice['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('SIN VALIDEZ FISCAL', $pdf);

        // Lo timbrado ya no se corrige, ni se descarta, ni se vuelve a timbrar.
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($stamped) + $this->key())->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/discard", ['reason' => 'Ya no'], $this->v($stamped))->assertStatus(409);

        $this->as('mesero', 'GET', "invoices/{$invoice['id']}/events")->assertStatus(403);
        $events = $this->as('gerente', 'GET', "invoices/{$invoice['id']}/events")->assertOk()->json('data');
        $this->assertSame([[null, 'DRAFT'], ['DRAFT', 'PENDING_STAMP'], ['PENDING_STAMP', 'STAMPING'], ['STAMPING', 'STAMPED']],
            array_map(fn ($e) => [$e['fromStatus'], $e['toStatus']], $events));
        $this->assertSame($this->staff['mesero']['user']->uuid, $events[1]['actorId']);
        $this->assertNull($events[3]['actorId']);

        // La siguiente factura del emisor toma el folio 2.
        $second = $this->create($this->paidCheck(2)['id'])->json('data');
        $this->as('mesero', 'POST', "invoices/{$second['id']}/stamp", [], $this->v($second) + $this->key())->assertStatus(202);
        Stamping::run();
        $this->assertSame('2', $this->invoice($second['id'])['folio']);
    }

    public function test_rechazo_del_pac_y_reintento(): void
    {
        $issuer = $this->readyIssuer();
        $invoice = $this->create($this->paidCheck()['id'], ['legalName' => 'EMPRESA SIMULAR RECHAZO'])->json('data');
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($invoice) + $this->key())->assertStatus(202);
        $this->assertSame(1, Stamping::run()['failed']);
        $failed = $this->invoice($invoice['id']);
        $this->assertSame(['STAMP_FAILED', 'CFDI40999', '1'], [$failed['status'], $failed['lastError']['code'], $failed['folio']]);

        // Se corrige y vuelve a DRAFT; el folio asignado se conserva.
        $fixed = $this->as('mesero', 'PATCH', "invoices/{$invoice['id']}", ['receiver' => $this->receiver], $this->v($failed))
            ->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.lastError', null)->json('data');

        // Con el emisor desactivado no se timbra.
        $this->as('admin', 'POST', "/fiscal-issuers/$issuer/status", ['status' => 'INACTIVE', 'reason' => 'Prueba'], ['If-Match' => '"3"'])->assertOk();
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($fixed) + $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_ISSUER_NOT_CONFIGURED');
        $this->as('admin', 'POST', "/fiscal-issuers/$issuer/status", ['status' => 'ACTIVE', 'reason' => 'Prueba'], ['If-Match' => '"4"'])->assertOk();

        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($fixed) + $this->key())->assertStatus(202);
        Stamping::run();
        $this->assertSame(['STAMPED', '1'], [$this->invoice($invoice['id'])['status'], $this->invoice($invoice['id'])['folio']]);
    }

    public function test_sin_respuesta_queda_unknown_y_se_reconcilia(): void
    {
        $this->readyIssuer();
        $invoice = $this->create($this->paidCheck()['id'], ['legalName' => 'EMPRESA SIMULAR SIN RESPUESTA'])->json('data');
        $this->as('gerente', 'POST', "invoices/{$invoice['id']}/reconcile")->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_INVALID_STATE');
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($invoice) + $this->key())->assertStatus(202);
        $this->assertSame(1, Stamping::run()['unknown']);
        $unknown = $this->invoice($invoice['id']);
        $this->assertSame(['UNKNOWN', 'PAC_UNREACHABLE'], [$unknown['status'], $unknown['lastError']['code']]);

        // F-11: nunca se reenvía a ciegas.
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($unknown) + $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'FISCAL_STATUS_UNKNOWN');
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/reconcile")->assertStatus(403);
        $this->as('gerente', 'POST', "invoices/{$invoice['id']}/reconcile")->assertStatus(202)->assertJsonPath('data.status', 'UNKNOWN');
        $this->assertSame(1, Stamping::run()['reconciled']);
        $reconciled = $this->invoice($invoice['id']);
        $this->assertSame('STAMPED', $reconciled['status']);
        $this->assertSame(DB::connection('mongodb')->table('simulated_pac_documents')->first()->fiscal_uuid, $reconciled['fiscalUuid']);
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'invoice.reconciled')->count());
    }

    public function test_un_proceso_caido_deja_unknown_y_la_consulta_permite_reintentar(): void
    {
        $this->readyIssuer();
        $invoice = $this->create($this->paidCheck()['id'])->json('data');
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($invoice) + $this->key())->assertStatus(202);
        // El proceso tomó la factura y se cayó antes de hablar con el PAC.
        Invoice::query()->where('uuid', $invoice['id'])->update(['status' => Invoice::STAMPING, 'lease_until' => now()->subMinute()]);
        $this->assertSame(1, Stamping::run()['expired']);
        $this->assertSame('LEASE_EXPIRED', $this->invoice($invoice['id'])['lastError']['code']);

        // El PAC no lo tiene: queda en STAMP_FAILED y se puede timbrar de nuevo.
        $this->as('gerente', 'POST', "invoices/{$invoice['id']}/reconcile")->assertStatus(202);
        Stamping::run();
        $failed = $this->invoice($invoice['id']);
        $this->assertSame(['STAMP_FAILED', 'NOT_STAMPED'], [$failed['status'], $failed['lastError']['code']]);
        $this->as('mesero', 'POST', "invoices/{$invoice['id']}/stamp", [], $this->v($failed) + $this->key())->assertStatus(202);
        Stamping::run();
        $this->assertSame('STAMPED', $this->invoice($invoice['id'])['status']);
    }

    public function test_con_factura_activa_la_cuenta_no_cambia(): void
    {
        $session = $this->openSession();
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->json('data');
        $check = $this->as('mesero', 'GET', "checks?sessionId=$session")->json('data.0');
        Check::query()->where('uuid', $check['id'])->update(['active_invoice_id' => new ObjectId]);
        $this->as('gerente', 'POST', "checks/{$check['id']}/adjustments", ['unitId' => $order['items'][1]['units'][0]['id'], 'type' => 'COURTESY', 'reason' => 'Cumpleaños'],
            ['If-Match' => '"'.$check['version'].'"'])->assertStatus(409)->assertJsonPath('error.code', 'CHECK_HAS_ACTIVE_INVOICE');
    }
}
