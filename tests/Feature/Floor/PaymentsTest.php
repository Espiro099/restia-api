<?php

namespace Tests\Feature\Floor;

use App\Payments\Payments;
use Illuminate\Testing\TestResponse;

/** Pagos y reembolsos (Decisiones_Payments): efectivo, terminal, saldo reservado, UNKNOWN y reembolsos. */
class PaymentsTest extends FloorTestCase
{
    private function money(string $amount): array
    {
        return ['amount' => $amount, 'currency' => 'MXN'];
    }

    /** Sesión con una ronda de 207.00 y su cuenta. @return array{0: string, 1: array, 2: array} */
    private function served(int $table = 1): array
    {
        $session = $this->openSession($table);
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->json('data');

        return [$session, $this->as('mesero', 'GET', "checks?sessionId=$session")->json('data.0'), $order];
    }

    private function pay(string $check, string $method, string $amount, array $extra = [], string $who = 'mesero'): TestResponse
    {
        return $this->as($who, 'POST', 'payments', ['checkId' => $check, 'cashShiftId' => $this->cashShift(), 'method' => $method, 'amount' => $this->money($amount)] + $extra, $this->key());
    }

    private function check(string $id): array
    {
        return $this->as('mesero', 'GET', "checks/$id")->json('data');
    }

    private function v(array $doc): array
    {
        return ['If-Match' => '"'.$doc['version'].'"'] + $this->key();
    }

    public function test_efectivo_con_cambio_y_propina_liquida_la_cuenta(): void
    {
        [$session, $check, $order] = $this->served();
        $this->as('cocina', 'POST', 'payments', [])->assertStatus(403);
        $this->pay($check['id'], 'CASH', '207')->assertStatus(422);
        $this->pay($check['id'], 'CASH', '207', ['amountTendered' => $this->money('210'), 'tip' => $this->money('20')])->assertStatus(422);
        $this->pay($check['id'], 'CARD_DEBIT', '207', ['amountTendered' => $this->money('250')])->assertStatus(422);
        $this->pay($check['id'], 'CASH', '207.01', ['amountTendered' => $this->money('300')])->assertStatus(409)
            ->assertJsonPath('error.code', 'PAYMENT_EXCEEDS_BALANCE')->assertJsonPath('error.details.available.amount', '207.00');

        $payment = $this->pay($check['id'], 'CASH', '100', ['tip' => $this->money('10'), 'amountTendered' => $this->money('200')])->assertCreated()->json('data');
        $this->assertSame(['CONFIRMED', '90.00', '10.00'], [$payment['status'], $payment['change']['amount'], $payment['tip']['amount']]);
        $check = $this->check($check['id']);
        // El primer pago la pasa a REQUESTED y, con saldo, a PARTIALLY_PAID; la propina no cuenta en el total.
        $this->assertSame(['PARTIALLY_PAID', '100.00', '107.00', '10.00', '207.00'], [$check['status'], $check['paidTotal']['amount'], $check['balance']['amount'], $check['tipTotal']['amount'], $check['total']['amount']]);
        $this->as('mesero', 'GET', 'tables/'.$this->table(1)->uuid)->assertJsonPath('data.operationalState', 'CUENTA_SOLICITADA');

        $this->pay($check['id'], 'CASH', '107', ['amountTendered' => $this->money('107')])->assertCreated();
        $check = $this->check($check['id']);
        $this->assertSame(['PAID', '0.00'], [$check['status'], $check['balance']['amount']]);
        $this->assertNotNull($check['paidAt']);
        $this->pay($check['id'], 'CASH', '1', ['amountTendered' => $this->money('1')])->assertStatus(409)->assertJsonPath('error.code', 'CHECK_INVALID_STATE');
        $this->as('mesero', 'GET', "payments?checkId={$check['id']}")->assertJsonCount(2, 'data');

        // Pagada y entregada, la sesión cierra (T-4) y la caja ve el efectivo.
        $all = [...$this->unitsOf($order), ...$this->unitsOf($order, 1)];
        $this->transition('cocina', 'START', $all)->assertOk();
        $this->transition('cocina', 'READY', $this->bump($all))->assertOk();
        $this->transition('mesero', 'DELIVER', $this->bump($this->bump($all)))->assertOk();
        $version = $this->as('mesero', 'GET', "table-sessions/$session")->json('data.version');
        $this->as('mesero', 'POST', "table-sessions/$session/close", [], ['If-Match' => '"'.$version.'"'])->assertOk();
        $summary = $this->as('gerente', 'GET', 'cash-shifts/'.$this->cashShift().'/summary')->json('data');
        $this->assertSame('217.00', $summary['expectedCash']['amount']);
    }

    public function test_terminal_reserva_el_saldo_y_unknown_bloquea_hasta_conciliar(): void
    {
        [, $check] = $this->served();
        $card = $this->pay($check['id'], 'CARD_CREDIT', '150')->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data');
        $this->assertSame(['REQUESTED', '150.00'], [$this->check($check['id'])['status'], $this->check($check['id'])['pendingTotal']['amount']]);
        // Lo pendiente reserva: solo quedan 57.00 para otro pago.
        $this->pay($check['id'], 'CARD_DEBIT', '60')->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_EXCEEDS_BALANCE');
        $debit = $this->pay($check['id'], 'CARD_DEBIT', '57')->assertCreated()->json('data');

        $path = "payments/{$card['id']}/result";
        $this->as('mesero', 'POST', $path, ['outcome' => 'CONFIRMED'], $this->v($card))->assertStatus(422);
        $this->as('mesero', 'POST', $path, ['outcome' => 'CONFIRMED', 'reference' => 'AUT-1', 'card' => ['number' => '4111111111111111']], $this->v($card))->assertStatus(422);
        $card = $this->as('mesero', 'POST', $path, ['outcome' => 'CONFIRMED', 'reference' => 'AUT-1', 'card' => ['brand' => 'VISA', 'last4' => '1111']], $this->v($card))->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED')->assertJsonPath('data.card.last4', '1111')->json('data');
        $this->as('mesero', 'POST', $path, ['outcome' => 'FAILED'], $this->v($card))->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_INVALID_STATE');
        $this->assertSame('PARTIALLY_PAID', $this->check($check['id'])['status']);

        // La terminal no mostró el resultado: UNKNOWN, y la cuenta no acepta más intentos.
        $debit = $this->as('mesero', 'POST', "payments/{$debit['id']}/result", ['outcome' => 'UNKNOWN', 'note' => 'Se apagó'], $this->v($debit))->assertOk()->json('data');
        $this->pay($check['id'], 'CASH', '1', ['amountTendered' => $this->money('1')])->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_REQUIRES_RECONCILIATION');
        $reconcile = "payments/{$debit['id']}/reconcile";
        $this->as('mesero', 'POST', $reconcile, ['outcome' => 'FAILED', 'reason' => 'No pasó'], $this->v($debit))->assertStatus(403);
        $this->as('gerente', 'POST', $reconcile, ['outcome' => 'CONFIRMED', 'reason' => 'Aparece en el lote'], $this->v($debit))->assertStatus(422);
        $this->as('gerente', 'POST', $reconcile, ['outcome' => 'FAILED', 'reason' => 'No aparece en el lote'], $this->v($debit))->assertOk()->assertJsonPath('data.status', 'FAILED');
        $check = $this->check($check['id']);
        $this->assertSame(['PARTIALLY_PAID', '0.00', '57.00'], [$check['status'], $check['pendingTotal']['amount'], $check['balance']['amount']]);

        // P-4: un PENDING sin resultado por 15 minutos pasa a UNKNOWN.
        $late = $this->pay($check['id'], 'TRANSFER', '57')->assertCreated()->json('data');
        $this->travel(16)->minutes();
        $this->assertSame(1, Payments::expire());
        $this->travelBack();
        $this->as('mesero', 'GET', "payments/{$late['id']}")->assertJsonPath('data.status', 'UNKNOWN');
        $this->as('gerente', 'POST', "payments/{$late['id']}/reconcile", ['outcome' => 'CONFIRMED', 'reference' => 'SPEI-9', 'reason' => 'Llegó la transferencia'], ['If-Match' => '"2"'] + $this->key())->assertOk();
        $this->assertSame('PAID', $this->check($check['id'])['status']);
    }

    public function test_reembolso_completo_y_nuevo_cobro(): void
    {
        [$session, $check, $order] = $this->served();
        $cash = $this->pay($check['id'], 'CASH', '207', ['amountTendered' => $this->money('207'), 'tip' => $this->money('0')])->assertCreated()->json('data');
        $body = ['cashShiftId' => $this->cashShift(), 'reason' => 'Platillo en mal estado'];
        $this->as('mesero', 'POST', "payments/{$cash['id']}/refunds", $body, $this->key())->assertStatus(403);

        $refund = $this->as('gerente', 'POST', "payments/{$cash['id']}/refunds", $body, $this->key())->assertCreated()
            ->assertJsonPath('data.status', 'CONFIRMED')->assertJsonPath('data.amount.amount', '207.00')->json('data');
        $this->as('gerente', 'POST', "payments/{$cash['id']}/refunds", $body, $this->key())->assertStatus(409)->assertJsonPath('error.code', 'REFUND_ALREADY_EXISTS');
        $this->as('mesero', 'GET', "payments/{$cash['id']}")->assertJsonPath('data.refundId', $refund['id']);
        $check = $this->check($check['id']);
        $this->assertSame(['REFUNDED', '207.00'], [$check['status'], $check['refundedTotal']['amount']]);

        // Una cuenta REFUNDED puede pasar sus unidades a otra OPEN para volver a cobrarlas (P-8).
        $again = $this->as('mesero', 'POST', 'checks', ['sessionId' => $session], $this->key())->json('data');
        $units = array_column($check['units'], 'unitId');
        $this->as('mesero', 'POST', "checks/{$check['id']}/units/move", ['unitIds' => $units, 'targetCheckId' => $again['id'], 'targetCheckVersion' => $again['version']], ['If-Match' => '"'.$check['version'].'"'])
            ->assertOk()->assertJsonPath('data.total.amount', '0.00');
        $this->assertSame('207.00', $this->check($again['id'])['total']['amount']);

        // Con tarjeta queda PENDING; un FAILED permite otro intento.
        [, $other] = $this->served(2);
        $card = $this->pay($other['id'], 'CARD_DEBIT', '207')->assertCreated()->json('data');
        $card = $this->as('mesero', 'POST', "payments/{$card['id']}/result", ['outcome' => 'CONFIRMED', 'reference' => 'AUT-7'], $this->v($card))->json('data');
        $pending = $this->as('gerente', 'POST', "payments/{$card['id']}/refunds", $body, $this->key())->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data');
        $this->as('gerente', 'POST', "refunds/{$pending['id']}/result", ['outcome' => 'FAILED'], $this->v($pending))->assertOk()->assertJsonPath('data.status', 'FAILED');
        $retry = $this->as('gerente', 'POST', "payments/{$card['id']}/refunds", $body, $this->key())->assertCreated()->json('data');
        $this->as('gerente', 'POST', "refunds/{$retry['id']}/result", ['outcome' => 'UNKNOWN'], $this->v($retry))->assertOk();

        // Un reembolso sin resolver bloquea el cierre de caja.
        $shift = $this->as('gerente', 'GET', 'cash-shifts/'.$this->cashShift())->json('data');
        $shift = $this->as('gerente', 'POST', "cash-shifts/{$shift['id']}/start-closing", [], ['If-Match' => '"'.$shift['version'].'"'])->json('data');
        $this->as('gerente', 'POST', "cash-shifts/{$shift['id']}/close", ['countedCash' => $this->money('0'), 'cashLeftInDrawer' => $this->money('0')], $this->v($shift))
            ->assertStatus(409)->assertJsonPath('error.code', 'CASH_SHIFT_HAS_UNRESOLVED_OPERATIONS')->assertJsonPath('error.details.unknownRefunds', 1);
        $this->as('gerente', 'POST', "refunds/{$retry['id']}/reconcile", ['outcome' => 'CONFIRMED', 'reference' => 'REV-1', 'reason' => 'El banco lo confirma'], ['If-Match' => '"2"'] + $this->key())->assertOk();
        $this->assertSame('REFUNDED', $this->check($other['id'])['status']);
        $this->as('gerente', 'GET', "refunds?checkId={$other['id']}")->assertJsonCount(2, 'data');
        $this->as('mesero', 'GET', 'refunds')->assertStatus(403);
        // En CLOSING no entran cobros nuevos.
        $this->as('mesero', 'POST', 'payments', ['checkId' => $again['id'], 'cashShiftId' => $shift['id'], 'method' => 'CASH', 'amount' => $this->money('1'), 'amountTendered' => $this->money('1')], $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'CASH_SHIFT_NOT_OPEN');
    }
}
