<?php

namespace Tests\Feature\Floor;

use App\Models\Check;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

/** Cuentas (Decisiones_Checks): asignación desde la comanda, totales, dividir, solicitar, cortesías y cierre. */
class ChecksTest extends FloorTestCase
{
    private function checks(string $session): array
    {
        return $this->as('mesero', 'GET', "checks?sessionId=$session")->assertOk()->json('data');
    }

    private function approval(string $action, array $ids, string $reason): string
    {
        return $this->as('mesero', 'POST', 'approvals', [
            'approverEmail' => 'gerente@ejemplo.mx', 'approverPassword' => self::PASSWORD, 'action' => $action,
            'resourceIds' => $ids, 'reason' => $reason,
        ])->assertCreated()->json('data.approvalToken');
    }

    private function v(array $check): array
    {
        return ['If-Match' => '"'.$check['version'].'"'];
    }

    public function test_la_comanda_crea_la_cuenta_y_sus_totales(): void
    {
        $session = $this->openSession();
        $this->assertSame([], $this->checks($session));
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated()->json('data');
        [$check] = $this->checks($session);

        // 2 aguas grandes (35 + 15) y unos tacos con queso (95 + 12), IVA 16 % incluido, por unidad.
        $this->assertSame([1, 'OPEN', 3], [$check['number'], $check['status'], count($check['units'])]);
        $this->assertSame(['207.00', '178.44', '28.56', '207.00', '0.00'], [
            $check['total']['amount'], $check['subtotal']['amount'], $check['taxTotal']['amount'], $check['balance']['amount'], $check['paidTotal']['amount'],
        ]);
        $this->assertSame(['50.00', '6.90'], [$check['units'][0]['price']['amount'], $check['units'][0]['tax']['amount']]);
        $this->assertSame($order['id'], $check['units'][0]['orderId']);
        $this->as('cocina', 'GET', 'checks')->assertStatus(403);

        // Una segunda ronda va a la misma cuenta OPEN; una cancelación deja de cobrarse.
        $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated();
        $this->assertCount(1, $this->checks($session));
        $this->transition('mesero', 'CANCEL', $this->unitsOf($order, 1), 'Se equivocó')->assertOk();
        [$check] = $this->checks($session);
        $this->assertSame([6, '307.00'], [count($check['units']), $check['total']['amount']]);
        $this->as('mesero', 'GET', "checks/{$check['id']}")->assertOk()->assertHeader('ETag', '"'.$check['version'].'"');

        // targetCheckId debe ser una cuenta OPEN de la sesión.
        $body = $this->round($session) + ['targetCheckId' => '00000000-0000-4000-8000-000000000000'];
        $this->as('mesero', 'POST', 'orders', $body, $this->key())->assertStatus(409)->assertJsonPath('error.code', 'CHECK_INVALID_STATE');
    }

    public function test_dividir_la_cuenta_y_solicitarla(): void
    {
        $session = $this->openSession();
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->json('data');
        [$main] = $this->checks($session);
        $split = $this->as('mesero', 'POST', 'checks', ['sessionId' => $session, 'label' => 'Juan'], $this->key())->assertCreated()
            ->assertJsonPath('data.number', 2)->assertJsonPath('data.total.amount', '0.00')->json('data');

        $tacos = $this->unitsOf($order, 1)[0]['id'];
        $move = ['unitIds' => [$tacos], 'targetCheckId' => $split['id'], 'targetCheckVersion' => $split['version']];
        $this->as('mesero', 'POST', "checks/{$main['id']}/units/move", $move)->assertStatus(400);
        $this->as('mesero', 'POST', "checks/{$main['id']}/units/move", ['targetCheckVersion' => 99] + $move, $this->v($main))
            ->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->as('mesero', 'POST', "checks/{$split['id']}/units/move", ['targetCheckId' => $main['id'], 'targetCheckVersion' => $main['version']] + $move, $this->v($split))
            ->assertStatus(422);
        $main = $this->as('mesero', 'POST', "checks/{$main['id']}/units/move", $move, $this->v($main))->assertOk()
            ->assertJsonPath('data.total.amount', '100.00')->json('data');
        $split = $this->as('mesero', 'GET', "checks/{$split['id']}")->assertJsonPath('data.total.amount', '107.00')->assertJsonPath('data.units.0.unitId', $tacos)->json('data');

        // Otra sesión: no se mezclan cuentas.
        $other = $this->as('mesero', 'POST', 'checks', ['sessionId' => $this->openSession(2)], $this->key())->json('data');
        $this->as('mesero', 'POST', "checks/{$split['id']}/units/move", ['unitIds' => [$tacos], 'targetCheckId' => $other['id'], 'targetCheckVersion' => $other['version']], $this->v($split))
            ->assertStatus(409)->assertJsonPath('error.code', 'CHECK_SESSION_MISMATCH');

        // Solicitar: la de Juan pasa a REQUESTED y la mesa a CUENTA_SOLICITADA.
        $split = $this->as('mesero', 'POST', "checks/{$split['id']}/request", [], $this->v($split))->assertOk()
            ->assertJsonPath('data.status', 'REQUESTED')->json('data');
        $this->assertNotNull($split['requestedAt']);
        $this->as('mesero', 'GET', 'tables/'.$this->table(1)->uuid)->assertJsonPath('data.operationalState', 'CUENTA_SOLICITADA');
        // REQUESTED no recibe unidades: una ronda nueva va a la cuenta 1, que sigue OPEN.
        $this->as('mesero', 'POST', 'orders', $this->round($session) + ['targetCheckId' => $split['id']], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'CHECK_INVALID_STATE');
        $empty = $this->as('mesero', 'POST', 'checks', ['sessionId' => $session], $this->key())->json('data');
        $this->as('mesero', 'POST', "checks/{$empty['id']}/request", [], $this->v($empty))->assertStatus(409)->assertJsonPath('error.code', 'CHECK_EMPTY');

        // Reabrir: el mesero necesita el token del gerente (C-9).
        $reason = 'El cliente pidió algo más';
        $this->as('mesero', 'POST', "checks/{$split['id']}/reopen", ['reason' => $reason], $this->v($split))->assertStatus(403);
        $token = $this->approval('CHECK_REOPEN', [$split['id']], $reason);
        $this->as('mesero', 'POST', "checks/{$split['id']}/reopen", ['reason' => $reason], $this->v($split) + ['X-Approval-Token' => $token])->assertOk()
            ->assertJsonPath('data.status', 'OPEN')->assertJsonPath('data.requestedAt', null);
        $this->as('gerente', 'POST', "checks/{$empty['id']}/cancel", ['reason' => 'Cuenta de más'], $this->v($empty))->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->as('mesero', 'POST', "checks/{$main['id']}/cancel", ['reason' => 'Ya no la quieren'], $this->v($main))->assertStatus(409)->assertJsonPath('error.code', 'CHECK_NOT_EMPTY');
    }

    public function test_cortesia_lleva_la_unidad_a_cero_y_se_revierte(): void
    {
        $session = $this->openSession();
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->json('data');
        [$check] = $this->checks($session);
        $tacos = $this->unitsOf($order, 1)[0]['id'];
        $reason = 'Tardó demasiado';
        $body = ['unitId' => $tacos, 'type' => 'COURTESY', 'reason' => $reason];

        $this->as('mesero', 'POST', "checks/{$check['id']}/adjustments", $body, $this->v($check))->assertStatus(403);
        $token = $this->approval('CHECK_ADJUSTMENT', [$tacos], $reason);
        $check = $this->as('mesero', 'POST', "checks/{$check['id']}/adjustments", $body, $this->v($check) + ['X-Approval-Token' => $token])->assertCreated()
            ->assertJsonPath('data.adjustmentTotal.amount', '107.00')->assertJsonPath('data.total.amount', '100.00')->json('data');
        $adjustment = collect($check['units'])->firstWhere('unitId', $tacos)['adjustment'];
        $this->assertSame(['COURTESY', '107.00', $reason], [$adjustment['type'], $adjustment['amount']['amount'], $adjustment['reason']]);
        $this->as('gerente', 'POST', "checks/{$check['id']}/adjustments", $body, $this->v($check))->assertStatus(409)->assertJsonPath('error.code', 'ORDER_UNIT_INVALID_STATE');

        $check = $this->as('gerente', 'POST', "checks/{$check['id']}/adjustments/{$adjustment['id']}/revert", ['reason' => 'Se equivocó'], $this->v($check))->assertOk()
            ->assertJsonPath('data.total.amount', '207.00')->json('data');
        $this->assertNull(collect($check['units'])->firstWhere('unitId', $tacos)['adjustment']);

        // Cortesía de todo: la cuenta llega a 0 y queda PAID (C-8).
        foreach ($check['units'] as $u) {
            $check = $this->as('gerente', 'POST', "checks/{$check['id']}/adjustments", ['unitId' => $u['unitId'], 'type' => 'COURTESY', 'reason' => 'Invita la casa'], $this->v($check))
                ->assertCreated()->json('data');
        }
        $this->assertSame(['PAID', '0.00'], [$check['status'], $check['balance']['amount']]);
        $this->assertNotNull($check['paidAt']);
        $this->assertSame(4, $this->auditCount('check.adjustment_applied'));
    }

    public function test_cerrar_la_sesion_cancela_las_cuentas_vacias_y_un_pago_congela_las_unidades(): void
    {
        $session = $this->openSession();
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->json('data');
        [$main] = $this->checks($session);
        $this->as('mesero', 'POST', 'checks', ['sessionId' => $session], $this->key())->assertCreated();
        // Todo cancelado: la cuenta queda sin unidades que se cobren.
        $this->transition('mesero', 'CANCEL', [...$this->unitsOf($order, 0), ...$this->unitsOf($order, 1)], 'Se fueron')->assertOk();
        $version = $this->as('mesero', 'GET', "table-sessions/$session")->json('data.version');
        $this->as('mesero', 'POST', "table-sessions/$session/close", [], ['If-Match' => '"'.$version.'"'])->assertOk();
        $this->assertSame(['CANCELLED', 'CANCELLED'], array_column($this->checks($session), 'status'));
        $this->assertSame(2, $this->auditCount('check.cancelled'));

        // C-2: con un pago confirmado, las unidades de la cuenta ya no se cancelan.
        $session = $this->openSession(2);
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->json('data');
        [$check] = $this->checks($session);
        DB::connection('mongodb')->table('payments')->insert(['uuid' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'check_id' => new ObjectId(Check::query()->where('uuid', $check['id'])->first()->getKey()), 'status' => 'CONFIRMED']);
        $this->transition('mesero', 'CANCEL', $this->unitsOf($order, 1), 'Ya no')->assertStatus(409)->assertJsonPath('error.code', 'ORDER_UNIT_PAID');
        $split = $this->as('mesero', 'POST', 'checks', ['sessionId' => $session], $this->key())->json('data');
        $this->as('mesero', 'POST', "checks/{$check['id']}/units/move", ['unitIds' => [$this->unitsOf($order, 1)[0]['id']], 'targetCheckId' => $split['id'], 'targetCheckVersion' => $split['version']], $this->v($check))
            ->assertStatus(409)->assertJsonPath('error.code', 'CHECK_HAS_CONFIRMED_PAYMENT');
    }
}
