<?php

namespace Tests\Feature\Floor;

use App\Models\Order;
use App\Models\OrderItemUnit;
use App\Models\Station;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Comandas (O-1, O-2, O-7) con copia del catálogo, idempotencia y borradores offline (A-7). */
class OrdersTest extends FloorTestCase
{
    public function test_crea_la_ronda_con_unidades_pendientes_y_total(): void
    {
        $session = $this->openSession(1);
        $r = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated()
            ->assertJsonPath('data.sessionId', $session)
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.total', ['amount' => '207.00', 'currency' => 'MXN'])
            ->assertJsonPath('data.createdBy', $this->staff['mesero']['user']->uuid)
            ->assertJsonPath('data.items.0.name', 'Agua mineral')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unitPrice.amount', '35.00')
            ->assertJsonPath('data.items.0.modifiers.0.name', 'Grande')
            ->assertJsonPath('data.items.0.modifiers.0.priceDelta.amount', '15.00')
            ->assertJsonPath('data.items.0.notes', 'Sin hielo')
            ->assertJsonCount(2, 'data.items.0.units')
            ->assertJsonPath('data.items.0.units.1.unitNumber', 2)
            ->assertJsonPath('data.items.0.units.0.status', 'PENDING')
            ->assertJsonPath('data.items.0.units.0.version', 1);
        $bar = Station::query()->where('code', 'BARRA')->first()->uuid;
        $kitchen = Station::query()->where('code', 'COCINA')->first()->uuid;
        $this->assertSame($bar, $r->json('data.items.0.units.0.stationId'));
        $this->assertSame($kitchen, $r->json('data.items.1.units.0.stationId'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $r->json('data.businessDate'));

        $this->as('mesero', 'GET', 'tables?operationalState=ESPERANDO_COMIDA')->assertJsonPath('data.0.number', 1);
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'order.created')->count());
        $this->assertSame(1, TableSession::query()->first()->activity_epoch);
    }

    public function test_lista_las_rondas_de_la_sesion_en_orden_con_etag(): void
    {
        $session = $this->openSession(1);
        $other = $this->openSession(2);
        $first = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->json('data.id');
        $second = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->json('data.id');
        $this->as('mesero', 'POST', 'orders', $this->round($other), $this->key())->assertCreated();

        $r = $this->as('mesero', 'GET', "orders?sessionId=$session&perPage=100")->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
        $this->assertSame([$first, $second], array_column($r->json('data'), 'id'));
        $this->as('mesero', 'GET', "orders?sessionId=$session&perPage=100", headers: ['If-None-Match' => $r->headers->get('ETag')])->assertStatus(304);
        $this->as('cocina', 'GET', "orders/$first")->assertOk()->assertJsonPath('data.id', $first);
        $this->as('mesero', 'GET', 'orders?sessionId='.Str::uuid())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_misma_clave_o_mismo_borrador_no_duplican(): void
    {
        $session = $this->openSession(1);
        $key = $this->key();
        $body = $this->round($session);
        $id = $this->as('mesero', 'POST', 'orders', $body, $key)->assertCreated()->json('data.id');
        $this->as('mesero', 'POST', 'orders', $body, $key)->assertCreated()->assertJsonPath('data.id', $id);
        // Un borrador offline reenviado con otra clave (A-7) devuelve la misma comanda.
        $this->as('mesero', 'POST', 'orders', $body + ['capturedAt' => '2026-11-07T20:00:00Z'], $this->key())->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(3, OrderItemUnit::query()->count());

        $changed = $body;
        $changed['items'][0]['quantity'] = 3;
        $this->as('mesero', 'POST', 'orders', $changed, $this->key())->assertStatus(409)->assertJsonPath('error.code', 'CLIENT_ORDER_CONFLICT');
        $this->as('mesero', 'POST', 'orders', $changed, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_productos_y_modificadores_invalidos(): void
    {
        $session = $this->openSession(1);
        $one = fn (string $code, array $options = []) => ['sessionId' => $session, 'clientOrderUuid' => (string) Str::uuid(),
            'items' => [['productId' => $this->product($code), 'quantity' => 1, 'modifierOptions' => $options]]];

        $this->as('mesero', 'POST', 'orders', $one('LIMONADA', [$this->option('TAMANO', 'Chico')]), $this->key())->assertStatus(409)
            ->assertJsonPath('error.code', 'PRODUCT_UNAVAILABLE')->assertJsonPath('error.details.productIds.0', $this->product('LIMONADA'));
        // Tamaño es obligatorio (mínimo 1) y admite una sola opción.
        $this->as('mesero', 'POST', 'orders', $one('AGUA'), $this->key())->assertStatus(422);
        $this->as('mesero', 'POST', 'orders', $one('AGUA', [$this->option('TAMANO', 'Chico'), $this->option('TAMANO', 'Grande')]), $this->key())->assertStatus(422);
        // Una opción de un grupo que no es del producto.
        $this->as('mesero', 'POST', 'orders', $one('SOPA', [$this->option('EXTRAS', 'Aguacate')]), $this->key())->assertStatus(422);
        $this->as('mesero', 'POST', 'orders', $one('SOPA'), $this->key())->assertCreated();

        $extra = $one('SOPA');
        $extra['items'][0]['price'] = '1.00';
        $this->as('mesero', 'POST', 'orders', $extra, $this->key())->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['items.0.price']]]]);

        Station::query()->update(['status' => Station::INACTIVE]);
        $this->as('mesero', 'POST', 'orders', $one('SOPA'), $this->key())->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_UNAVAILABLE');
    }

    public function test_reglas_de_sesion_y_cuenta(): void
    {
        $session = $this->openSession(1);
        $this->as('cocina', 'POST', 'orders', $this->round($session), $this->key())->assertStatus(403);
        $this->as('mesero', 'POST', 'orders', $this->round((string) Str::uuid()), $this->key())->assertStatus(404);
        // Aún no hay cuentas (Checks llega en la fase 5): ninguna está OPEN.
        $this->as('mesero', 'POST', 'orders', $this->round($session) + ['targetCheckId' => (string) Str::uuid()], $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'CHECK_INVALID_STATE');

        TableSession::query()->update(['status' => TableSession::CLOSED]);
        $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertStatus(409)->assertJsonPath('error.code', 'SESSION_NOT_ACTIVE');
        $this->assertSame(0, Order::query()->count());
    }
}
