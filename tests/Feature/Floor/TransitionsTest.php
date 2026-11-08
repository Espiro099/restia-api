<?php

namespace Tests\Feature\Floor;

use App\Models\Order;
use App\Models\OrderItemUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Transiciones por lote (O-5): permisos por acción, todo o nada, versión e idempotencia. */
class TransitionsTest extends FloorTestCase
{
    private function order(int $table = 1): array
    {
        return $this->as('mesero', 'POST', 'orders', $this->round($this->openSession($table)), $this->key())->assertCreated()->json('data');
    }

    public function test_ciclo_completo_cocina_y_mesero(): void
    {
        $order = $this->order();
        $water = $this->unitsOf($order);

        $r = $this->transition('cocina', 'START', $water)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'PREPARING')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.0.orderId', $order['id'])
            ->assertJsonPath('data.0.recipeVersion', null);
        $this->assertNotNull($r->json('data.0.preparingAt'));
        $this->transition('cocina', 'READY', $this->bump($water))->assertOk()->assertJsonPath('data.1.status', 'READY');
        $this->transition('mesero', 'DELIVER', $this->bump($this->bump($water)))->assertOk()->assertJsonPath('data.0.status', 'DELIVERED');

        $this->as('mesero', 'GET', "orders/{$order['id']}")->assertJsonPath('data.status', 'OPEN');
        $tacos = $this->unitsOf($order, 1);
        $this->transition('mesero', 'CANCEL', $tacos, 'Se arrepintió')->assertOk()->assertJsonPath('data.0.reason', 'Se arrepintió');
        // Todo terminó y algo se entregó: COMPLETED (O-1).
        $this->as('mesero', 'GET', "orders/{$order['id']}")->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.version', 2);
        $this->as('mesero', 'GET', 'tables?operationalState=CONSUMIENDO')->assertJsonPath('data.0.number', 1);
        $this->assertSame(2, $this->auditCount('order_unit.started'));
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'order_unit.cancelled')->count());
    }

    public function test_todo_cancelado_deja_la_comanda_cancelada(): void
    {
        $order = $this->order();
        $all = [...$this->unitsOf($order), ...$this->unitsOf($order, 1)];
        $this->transition('mesero', 'CANCEL', $all, 'Error de captura')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(Order::CANCELLED, Order::query()->first()->status);
        $this->as('mesero', 'GET', 'tables')->assertJsonPath('data.0.operationalState', 'OCUPADA_SIN_COMANDA');
    }

    public function test_permisos_por_accion(): void
    {
        $order = $this->order();
        $water = $this->unitsOf($order);
        $this->transition('mesero', 'START', $water)->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->transition('cocina', 'START', $water)->assertOk();
        $this->transition('cocina', 'READY', $this->bump($water))->assertOk();
        $this->transition('cocina', 'DELIVER', $this->bump($this->bump($water)))->assertStatus(403);
        $this->transition('mesero', 'VOID', $this->bump($this->bump($water)), 'Se cayó')->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_todo_o_nada_estado_y_version(): void
    {
        $order = $this->order();
        $water = $this->unitsOf($order);
        $tacos = $this->unitsOf($order, 1);
        $this->transition('cocina', 'START', [$water[0]])->assertOk();

        // Una unidad ya empezó: no se cancela ninguna.
        $r = $this->transition('mesero', 'CANCEL', [$water[1], $water[0]], 'Error')->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_UNIT_INVALID_STATE');
        $this->assertSame([$water[0]['id']], $r->json('error.details.unitIds'));
        $this->assertSame(2, OrderItemUnit::query()->where('status', OrderItemUnit::PENDING)->count());

        $this->transition('cocina', 'START', [['id' => $water[1]['id'], 'version' => 7], $tacos[0]])->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT')->assertJsonPath('error.details.unitIds', [$water[1]['id']]);
        $this->assertSame(2, OrderItemUnit::query()->where('status', OrderItemUnit::PENDING)->count());

        $this->transition('cocina', 'START', [['id' => (string) Str::uuid(), 'version' => 1]])->assertStatus(404);
    }

    public function test_validacion_del_cuerpo(): void
    {
        $water = $this->unitsOf($this->order());
        $this->transition('mesero', 'CANCEL', $water)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['reason']]]]);
        $this->transition('mesero', 'CANCEL', $water, 'no')->assertStatus(422);
        $this->transition('mesero', 'CANCEL', [$water[0], $water[0]], 'Error')->assertStatus(422);
        $this->transition('mesero', 'FLY', $water)->assertStatus(422);
        $this->transition('mesero', 'CANCEL', [$water[0] + ['status' => 'X']], 'Error')->assertStatus(422);
        $this->as('mesero', 'POST', 'order-units/transitions', ['action' => 'CANCEL', 'units' => $water, 'reason' => 'Error'])->assertStatus(422);
    }

    public function test_respuesta_perdida_se_repite_con_la_misma_clave(): void
    {
        $water = $this->unitsOf($this->order());
        $key = $this->key();
        $body = ['action' => 'CANCEL', 'units' => $water, 'reason' => 'Error'];
        $first = $this->as('mesero', 'POST', 'order-units/transitions', $body, $key)->assertOk()->json('data');
        $this->as('mesero', 'POST', 'order-units/transitions', $body, $key)->assertOk()->assertJsonPath('data', $first);
        $this->assertSame(2, $this->auditCount('order_unit.cancelled'));
        $this->as('mesero', 'POST', 'order-units/transitions', ['reason' => 'Otro'] + $body, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }
}
