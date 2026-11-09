<?php

namespace Tests\Feature\Floor;

use App\Cash\Cash;
use App\Models\CashShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;

/** Caja (Decisiones_Cash): cajas, apertura, movimientos, resumen y cierre en dos pasos. */
class CashTest extends FloorTestCase
{
    private function money(string $amount): array
    {
        return ['amount' => $amount, 'currency' => 'MXN'];
    }

    private function register(string $code = 'CAJA-1', string $tolerance = '0'): array
    {
        return $this->as('gerente', 'POST', 'cash-registers', ['code' => $code, 'name' => 'Caja principal', 'differenceTolerance' => $this->money($tolerance)])
            ->assertCreated()->json('data');
    }

    private function open(string $register, string $amount = '500', array $extra = []): TestResponse
    {
        return $this->as('gerente', 'POST', 'cash-shifts', ['cashRegisterId' => $register, 'openingAmount' => $this->money($amount)] + $extra, $this->key());
    }

    private function v(array $doc): array
    {
        return ['If-Match' => '"'.$doc['version'].'"'];
    }

    public function test_cajas_y_permisos(): void
    {
        $this->as('mesero', 'POST', 'cash-registers', ['code' => 'CAJA-1', 'name' => 'Caja'])->assertStatus(403);
        $this->as('cocina', 'GET', 'cash-registers')->assertStatus(403);
        $register = $this->register('CAJA-1', '20');
        $this->assertSame('20.00', $register['differenceTolerance']['amount']);
        $this->as('gerente', 'POST', 'cash-registers', ['code' => 'CAJA-1', 'name' => 'Otra'])->assertStatus(409)->assertJsonPath('error.code', 'CATALOG_CODE_TAKEN');
        $this->as('gerente', 'POST', 'cash-registers', ['code' => 'CAJA-2', 'name' => 'Otra', 'differenceTolerance' => $this->money('-1')])->assertStatus(422);
        // El mesero ve las cajas, sin la tolerancia.
        $this->as('mesero', 'GET', 'cash-registers')->assertOk()->assertJsonMissingPath('data.0.differenceTolerance')->assertJsonPath('data.0.code', 'CAJA-1');
        $this->as('gerente', 'PATCH', "cash-registers/{$register['id']}", ['differenceTolerance' => $this->money('50')], $this->v($register))->assertOk()
            ->assertJsonPath('data.differenceTolerance.amount', '50.00');

        // Con un turno activo no se desactiva.
        $this->open($register['id'])->assertCreated();
        $this->as('gerente', 'POST', "cash-registers/{$register['id']}/status", ['status' => 'INACTIVE', 'reason' => 'Se cambia'], ['If-Match' => '"2"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CASH_REGISTER_HAS_ACTIVE_SHIFT');
        $other = $this->register('CAJA-2');
        $this->as('gerente', 'POST', "cash-registers/{$other['id']}/status", ['status' => 'INACTIVE', 'reason' => 'Descompuesta'], $this->v($other))->assertOk();
        $this->open($other['id'])->assertStatus(409)->assertJsonPath('error.code', 'CASH_REGISTER_INACTIVE');
    }

    public function test_turno_completo_con_movimientos_y_diferencia(): void
    {
        $register = $this->register('CAJA-1', '10');
        $this->as('mesero', 'POST', 'cash-shifts', ['cashRegisterId' => $register['id'], 'openingAmount' => $this->money('500')], $this->key())->assertStatus(403);
        $shift = $this->open($register['id'])->assertCreated()->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.openingAmount.amount', '500.00')->assertJsonPath('data.openingDifference', null)->json('data');
        $this->open($register['id'])->assertStatus(409)->assertJsonPath('error.code', 'CASH_SHIFT_ALREADY_OPEN');

        // El mesero ve el turno abierto, sin montos.
        $this->as('mesero', 'GET', 'cash-shifts')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.openingAmount');
        $this->as('mesero', 'GET', "cash-shifts/{$shift['id']}/summary")->assertStatus(403);

        $path = "cash-shifts/{$shift['id']}";
        $key = $this->key();
        $deposit = ['type' => 'DEPOSIT', 'amount' => $this->money('200'), 'reason' => 'Cambio del banco'];
        $first = $this->as('gerente', 'POST', "$path/movements", $deposit, $key)->assertCreated()->assertJsonPath('data.amount.amount', '200.00')->json('data');
        $this->as('gerente', 'POST', "$path/movements", $deposit, $key)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->as('gerente', 'POST', "$path/movements", ['amount' => $this->money('0')] + $deposit, $this->key())->assertStatus(422);
        $this->as('gerente', 'POST', "$path/movements", ['type' => 'WITHDRAWAL', 'amount' => $this->money('50.50'), 'reason' => 'Pago a proveedor'], $this->key())->assertCreated();
        // Un pago en efectivo con propina (llega con Payments) también entra al esperado.
        $shiftId = new ObjectId(CashShift::query()->where('uuid', $shift['id'])->first()->getKey());
        DB::connection('mongodb')->table('payments')->insert(['cash_shift_id' => $shiftId, 'status' => 'CONFIRMED', 'method' => 'CASH', 'amount' => new Decimal128('207.00'), 'tip' => new Decimal128('20.00')]);
        DB::connection('mongodb')->table('payments')->insert(['cash_shift_id' => $shiftId, 'status' => 'CONFIRMED', 'method' => 'CARD_DEBIT', 'amount' => new Decimal128('100.00'), 'tip' => new Decimal128('0')]);

        $summary = $this->as('gerente', 'GET', "$path/summary")->assertOk()->json('data');
        // 500 + 200 − 50.50 + 207 + 20 = 876.50; la tarjeta no entra al efectivo.
        $this->assertSame(['876.50', '200.00', '50.50', false], [$summary['expectedCash']['amount'], $summary['deposits']['amount'], $summary['withdrawals']['amount'], $summary['frozen']]);
        $debit = collect($summary['byMethod'])->firstWhere('method', 'CARD_DEBIT');
        $this->assertSame('100.00', $debit['sales']['amount']);
        $this->as('gerente', 'GET', "$path/movements")->assertJsonCount(2, 'data');

        // Cierre en dos pasos; en CLOSING no entran movimientos.
        $close = ['countedCash' => $this->money('860'), 'cashLeftInDrawer' => $this->money('500')];
        $this->as('gerente', 'POST', "$path/close", $close, $this->v($shift) + $this->key())->assertStatus(409)->assertJsonPath('error.code', 'CASH_SHIFT_INVALID_STATE');
        $shift = $this->as('gerente', 'POST', "$path/start-closing", [], $this->v($shift))->assertOk()->assertJsonPath('data.status', 'CLOSING')->json('data');
        $this->as('gerente', 'POST', "$path/start-closing", [], $this->v($shift))->assertOk();
        $this->as('gerente', 'POST', "$path/movements", $deposit, $this->key())->assertStatus(409)->assertJsonPath('error.code', 'CASH_SHIFT_NOT_OPEN');
        $shift = $this->as('gerente', 'POST', "$path/cancel-closing", ['reason' => 'Faltó un cobro'], $this->v($shift))->assertOk()->assertJsonPath('data.status', 'OPEN')->json('data');
        $shift = $this->as('gerente', 'POST', "$path/start-closing", [], $this->v($shift))->assertOk()->json('data');

        // Una operación sin resolver bloquea el cierre.
        DB::connection('mongodb')->table('payments')->insert(['cash_shift_id' => $shiftId, 'status' => 'UNKNOWN', 'method' => 'CARD_CREDIT', 'amount' => new Decimal128('50'), 'tip' => new Decimal128('0')]);
        $this->as('gerente', 'POST', "$path/close", $close, $this->v($shift) + $this->key())->assertStatus(409)
            ->assertJsonPath('error.code', 'CASH_SHIFT_HAS_UNRESOLVED_OPERATIONS')->assertJsonPath('error.details.unknownPayments', 1);
        DB::connection('mongodb')->table('payments')->where('status', 'UNKNOWN')->update(['status' => 'FAILED']);

        // Faltan 16.50: exige motivo y, por superar la tolerancia de 10, queda para revisión.
        $this->as('gerente', 'POST', "$path/close", ['cashLeftInDrawer' => $this->money('900')] + $close, $this->v($shift) + $this->key())->assertStatus(422);
        $this->as('gerente', 'POST', "$path/close", $close, $this->v($shift) + $this->key())->assertStatus(409)->assertJsonPath('error.code', 'CASH_SHIFT_REASON_REQUIRED');
        $key = $this->key();
        $closed = $this->as('gerente', 'POST', "$path/close", ['differenceReason' => 'Se dio cambio de más'] + $close, $this->v($shift) + $key)->assertOk()->json('data');
        $this->assertSame(['CLOSED', '876.50', '-16.50', true], [$closed['status'], $closed['expectedCash']['amount'], $closed['difference']['amount'], $closed['reviewRequired']]);
        $this->as('gerente', 'POST', "$path/close", ['differenceReason' => 'Se dio cambio de más'] + $close, $this->v($shift) + $key)->assertOk()->assertJsonPath('data.id', $shift['id']);
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'cash_shift.difference_detected')->count());

        // El resumen queda congelado aunque aparezca otro pago.
        DB::connection('mongodb')->table('payments')->insert(['cash_shift_id' => $shiftId, 'status' => 'CONFIRMED', 'method' => 'CASH', 'amount' => new Decimal128('999'), 'tip' => new Decimal128('0')]);
        $this->as('gerente', 'GET', "$path/summary")->assertJsonPath('data.frozen', true)->assertJsonPath('data.expectedCash.amount', '876.50')->assertJsonPath('data.countedCash.amount', '860.00');
        $this->as('mesero', 'GET', $path)->assertStatus(404);
        $this->as('gerente', 'GET', 'cash-shifts?reviewRequired=true')->assertJsonCount(1, 'data');

        // El siguiente turno compara su fondo con lo que dejó el anterior (CA-9).
        $this->open($register['id'], '450')->assertStatus(409)->assertJsonPath('error.code', 'CASH_SHIFT_REASON_REQUIRED');
        $this->open($register['id'], '450', ['openingDifferenceReason' => 'Faltaba un billete'])->assertCreated()->assertJsonPath('data.openingDifference.amount', '-50.00');
    }

    public function test_turno_olvidado_genera_una_alerta(): void
    {
        $register = $this->register();
        $this->open($register['id'])->assertCreated();
        $this->travel(25)->hours();
        $this->assertSame(1, Cash::forgotten());
        $this->assertSame(0, Cash::forgotten());
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'cash_shift.forgotten')->count());
    }
}
