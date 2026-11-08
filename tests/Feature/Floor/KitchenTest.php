<?php

namespace Tests\Feature\Floor;

use App\Models\Station;
use Illuminate\Support\Facades\DB;

/** Cola de cocina (GET /order-units), notas de una partida (O-2) y reasignación de estación (O-11). */
class KitchenTest extends FloorTestCase
{
    private function station(string $code): string
    {
        return Station::query()->where('code', $code)->firstOrFail()->uuid;
    }

    public function test_la_cola_trae_lo_que_cocina_necesita_y_respeta_el_etag(): void
    {
        $this->as('mesero', 'POST', 'table-sessions/'.$this->openSession(1).'/tables', ['tableIds' => [$this->table(2)->uuid]], ['If-Match' => '"1"'])->assertOk();
        $session = $this->as('mesero', 'GET', 'table-sessions')->json('data.0.id');
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated()->json('data');

        $queue = $this->as('cocina', 'GET', 'order-units')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.pagination.total', 3);
        $water = $queue->json('data.0');
        $this->assertSame('Agua mineral', $water['productName']);
        $this->assertSame('Sin hielo', $water['notes']);
        $this->assertSame([1, 2], $water['tableNumbers']);
        $this->assertSame('Grande', $water['modifiers'][0]['name']);
        $this->assertSame('PENDING', $water['unit']['status']);
        $this->assertSame($this->station('BARRA'), $water['unit']['stationId']);

        // Sin cambios, el mismo ETag responde 304.
        $etag = $queue->headers->get('ETag');
        $this->as('cocina', 'GET', 'order-units', headers: ['If-None-Match' => $etag])->assertStatus(304);

        $this->as('cocina', 'GET', 'order-units?stationId='.$this->station('COCINA'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.productName', 'Tacos al pastor');
        $this->as('cocina', 'GET', 'order-units?sessionId='.$session.'&perPage=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.lastPage', 2);
        $this->as('cocina', 'GET', 'order-units?stationId=no-es-uuid')->assertStatus(422);

        // Lo entregado sale de la cola por omisión, pero se puede pedir.
        $tacos = $this->unitsOf($order, 1);
        $this->transition('cocina', 'START', $tacos)->assertOk();
        $this->transition('cocina', 'READY', $this->bump($tacos))->assertOk();
        $this->transition('mesero', 'DELIVER', $this->bump($this->bump($tacos)))->assertOk();
        $this->as('cocina', 'GET', 'order-units', headers: ['If-None-Match' => $etag])->assertOk()->assertJsonCount(2, 'data');
        $this->as('mesero', 'GET', 'order-units?status=DELIVERED')->assertJsonCount(1, 'data')->assertJsonPath('data.0.unit.status', 'DELIVERED');
    }

    public function test_notas_solo_mientras_todo_esta_pendiente(): void
    {
        $order = $this->as('mesero', 'POST', 'orders', $this->round($this->openSession()), $this->key())->json('data');
        [$water, $tacos] = $order['items'];
        $path = "orders/{$order['id']}/items/{$water['id']}";

        $this->as('mesero', 'PATCH', $path, ['notes' => 'Con hielo'])->assertStatus(400)->assertJsonPath('error.code', 'VERSION_REQUIRED');
        $this->as('mesero', 'PATCH', $path, [], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('mesero', 'PATCH', $path, ['notes' => 'Con hielo', 'quantity' => 3], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('mesero', 'PATCH', $path, ['notes' => 'Con hielo'], ['If-Match' => '"1"'])->assertOk()->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.items.0.notes', 'Con hielo')->assertJsonPath('data.items.0.version', 2);
        $this->as('mesero', 'PATCH', $path, ['notes' => 'Otra'], ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->as('mesero', 'PATCH', $path, ['notes' => null], ['If-Match' => '"2"'])->assertOk()->assertJsonPath('data.items.0.notes', null);
        $this->as('cocina', 'PATCH', $path, ['notes' => 'x'], ['If-Match' => '"3"'])->assertStatus(403);
        $this->assertSame(2, $this->auditCount('order_item.updated'));

        // Con una unidad ya en preparación, la nota ya no llega a tiempo.
        $this->transition('cocina', 'START', $this->unitsOf($order, 0, 1))->assertOk();
        $this->as('mesero', 'PATCH', $path, ['notes' => 'Tarde'], ['If-Match' => '"3"'])->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_UNIT_INVALID_STATE')->assertJsonPath('error.details.unitIds', [$water['units'][0]['id']]);
        // Otra comanda u otra partida no se cruzan.
        $this->as('mesero', 'PATCH', "orders/{$order['id']}/items/{$tacos['id']}", ['notes' => 'Sin cebolla'], ['If-Match' => '"1"'])->assertOk();
        $this->as('mesero', 'PATCH', "orders/{$order['id']}/items/00000000-0000-4000-8000-000000000000", ['notes' => 'x'], ['If-Match' => '"1"'])->assertStatus(404);
    }

    public function test_reasignar_estacion_en_pendiente(): void
    {
        $order = $this->as('mesero', 'POST', 'orders', $this->round($this->openSession()), $this->key())->json('data');
        $unit = $order['items'][1]['units'][0];
        $path = "order-units/{$unit['id']}/station";
        $bar = $this->station('BARRA');

        $this->as('mesero', 'POST', $path, ['stationId' => $bar, 'reason' => 'Cocina saturada'], ['If-Match' => '"1"'])->assertStatus(403);
        $this->as('cocina', 'POST', $path, ['stationId' => $bar], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('cocina', 'POST', $path, ['stationId' => '00000000-0000-4000-8000-000000000000', 'reason' => 'Cocina saturada'], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('cocina', 'POST', $path, ['stationId' => $bar, 'reason' => 'Cocina saturada'], ['If-Match' => '"1"'])->assertOk()
            ->assertHeader('ETag', '"2"')->assertJsonPath('data.stationId', $bar)->assertJsonPath('data.version', 2);
        $this->as('cocina', 'GET', "order-units?stationId=$bar")->assertJsonCount(3, 'data');
        $this->as('cocina', 'POST', $path, ['stationId' => $bar, 'reason' => 'Otra vez'], ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');

        $audit = (array) DB::connection('mongodb')->table('audit_log')->where('action', 'order_unit.reassigned')->first();
        $this->assertSame('Cocina saturada', $audit['reason']);
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'order_unit.reassigned')->count());

        $this->transition('cocina', 'START', [['id' => $unit['id'], 'version' => 2]])->assertOk();
        $this->as('gerente', 'POST', $path, ['stationId' => $this->station('COCINA'), 'reason' => 'Regresa'], ['If-Match' => '"3"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_UNIT_INVALID_STATE');
    }
}
