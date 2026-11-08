<?php

namespace Tests\Feature\Floor;

use App\Models\Branch;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\Station;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Altas y cambios del catálogo (Menu) y de las mesas. */
class CatalogTest extends FloorTestCase
{
    private const FISCAL = ['satProductCode' => '90101501', 'satUnitCode' => 'E48', 'taxObject' => '02', 'taxRate' => '0.16'];

    private function v(int $version): array
    {
        return ['If-Match' => '"'.$version.'"'];
    }

    private function menuVersion(): int
    {
        return (int) Branch::query()->find($this->branch->getKey())->menu_version;
    }

    private function newProduct(array $extra = []): TestResponse
    {
        return $this->as('gerente', 'POST', 'products', [
            'code' => 'TORTA', 'name' => 'Torta de milanesa', 'category' => 'Platos fuertes',
            'price' => ['amount' => '89.50', 'currency' => 'MXN'], 'fiscal' => self::FISCAL,
        ] + $extra);
    }

    private function station(string $code): string
    {
        return Station::query()->where('code', $code)->firstOrFail()->uuid;
    }

    public function test_un_producto_nuevo_se_vende_cuando_tiene_ruta_y_se_activa(): void
    {
        $menu = $this->menuVersion();
        $size = Modifier::query()->where('code', 'TAMANO')->first()->uuid;
        $p = $this->newProduct(['modifierIds' => [$size]])->assertCreated()->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.status', 'INACTIVE')->assertJsonPath('data.availability', 'AVAILABLE')
            ->assertJsonPath('data.price', ['amount' => '89.50', 'currency' => 'MXN'])
            ->assertJsonPath('data.modifierIds', [$size])->assertJsonPath('data.routes', [])
            ->assertJsonPath('data.fiscal.taxRate', '0.16')->json('data');
        $this->assertSame($menu + 1, $this->menuVersion());
        $this->newProduct()->assertStatus(409)->assertJsonPath('error.code', 'CATALOG_CODE_TAKEN');

        $path = "products/{$p['id']}";
        $this->as('gerente', 'POST', "$path/status", ['status' => 'ACTIVE', 'reason' => 'Ya está lista'], $this->v(1))
            ->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_NOT_SELLABLE');
        $routes = [['stationId' => $this->station('COCINA'), 'priority' => 1], ['stationId' => $this->station('BARRA'), 'priority' => 2]];
        $this->as('gerente', 'PUT', "$path/routes", ['routes' => $routes], $this->v(1))->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.routes', $routes);
        $this->as('gerente', 'POST', "$path/status", ['status' => 'ACTIVE', 'reason' => 'Ya está lista'], $this->v(2))->assertOk()
            ->assertHeader('ETag', '"3"')->assertJsonPath('data.status', 'ACTIVE');
        // Repetir el cambio de estado no tiene efecto, aunque la versión ya no coincida.
        $this->as('gerente', 'POST', "$path/status", ['status' => 'ACTIVE', 'reason' => 'Ya está lista'], $this->v(2))->assertOk()->assertJsonPath('data.version', 3);
        $this->as('gerente', 'PUT', "$path/routes", ['routes' => []], $this->v(3))->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_NOT_SELLABLE');

        $menuItem = collect($this->as('mesero', 'GET', 'menu')->json('data.products'))->firstWhere('code', 'TORTA');
        $this->assertSame('89.50', $menuItem['price']['amount']);
        // Ya se puede pedir.
        $session = $this->openSession();
        $this->as('mesero', 'POST', 'orders', ['sessionId' => $session, 'clientOrderUuid' => (string) Str::uuid(), 'items' => [
            ['productId' => $p['id'], 'quantity' => 1, 'modifierOptions' => [$this->option('TAMANO', 'Chico')]],
        ]], $this->key())->assertCreated();

        $this->assertSame(1, $this->auditCount('product.created'));
        $this->assertSame(1, $this->auditCount('product.routes_changed'));
        $audit = (array) DB::connection('mongodb')->table('audit_log')->where('action', 'product.status_changed')->first();
        $this->assertSame('Ya está lista', $audit['reason']);
    }

    public function test_editar_producto_con_if_match_y_validaciones(): void
    {
        $id = $this->product('TACOS');
        $path = "products/$id";
        $this->as('gerente', 'PATCH', $path, ['name' => 'Tacos'])->assertStatus(400)->assertJsonPath('error.code', 'VERSION_REQUIRED');
        $this->as('gerente', 'PATCH', $path, [], $this->v(1))->assertStatus(422);
        $this->as('gerente', 'PATCH', $path, ['price' => ['amount' => '99.999', 'currency' => 'MXN']], $this->v(1))->assertStatus(422);
        $this->as('gerente', 'PATCH', $path, ['price' => ['amount' => '99', 'currency' => 'USD']], $this->v(1))->assertStatus(422);
        $this->as('gerente', 'PATCH', $path, ['code' => 'OTRO'], $this->v(1))->assertStatus(422);
        $this->as('gerente', 'PATCH', $path, ['fiscal' => self::FISCAL + ['extra' => 'x']], $this->v(1))->assertStatus(422);
        $this->as('gerente', 'PATCH', $path, ['modifierIds' => ['00000000-0000-4000-8000-000000000000']], $this->v(1))
            ->assertStatus(422)->assertJsonValidationErrors(['modifierIds.0'], 'error.details.fields');
        $this->as('gerente', 'PATCH', $path, ['imageUrl' => 'http://inseguro.mx/a.png'], $this->v(1))->assertStatus(422);

        $this->as('gerente', 'PATCH', $path, ['price' => ['amount' => '105', 'currency' => 'MXN'], 'description' => 'Con piña', 'modifierIds' => []], $this->v(1))
            ->assertOk()->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.price.amount', '105.00')->assertJsonPath('data.description', 'Con piña')->assertJsonPath('data.modifierIds', []);
        $this->as('gerente', 'PATCH', $path, ['name' => 'Tacos'], $this->v(1))->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->as('mesero', 'PATCH', $path, ['name' => 'Tacos'], $this->v(2))->assertStatus(403);
        $this->as('mesero', 'GET', $path)->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.routes.0.stationId', $this->station('COCINA'));
    }

    public function test_agotar_un_producto_no_pide_version(): void
    {
        $id = $this->product('TACOS');
        $menu = $this->menuVersion();
        $this->as('mesero', 'POST', "products/$id/availability", ['availability' => 'SOLD_OUT'])->assertOk()->assertJsonPath('data.availability', 'SOLD_OUT');
        $this->assertSame($menu + 1, $this->menuVersion());
        // Repetir no cambia nada.
        $this->as('mesero', 'POST', "products/$id/availability", ['availability' => 'SOLD_OUT'])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame($menu + 1, $this->menuVersion());
        $this->as('cocina', 'POST', "products/$id/availability", ['availability' => 'AVAILABLE'])->assertOk();
        $this->as('mesero', 'POST', "products/$id/availability", ['availability' => 'MAYBE'])->assertStatus(422);
    }

    public function test_listas_paginadas_y_filtradas(): void
    {
        $this->as('mesero', 'GET', 'products?perPage=4')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.pagination.total', 6)->assertJsonPath('data.0.category', 'Bebidas');
        $this->as('mesero', 'GET', 'products?status=INACTIVE')->assertOk()->assertJsonCount(0, 'data');
        $this->as('mesero', 'GET', 'products?status=OTRO')->assertStatus(422);
        $this->as('cocina', 'GET', 'stations')->assertOk()->assertJsonPath('data.*.code', ['BARRA', 'COCINA']);
        $this->as('cocina', 'GET', 'modifiers')->assertOk()->assertJsonPath('data.0.code', 'EXTRAS')
            ->assertJsonPath('data.0.options.0.priceDelta', ['amount' => '12.00', 'currency' => 'MXN'])
            ->assertJsonPath('data.0.options.0.active', true);
        $this->as('mesero', 'GET', 'stations/no-es-uuid')->assertStatus(404);
    }

    public function test_estaciones_y_su_desactivacion(): void
    {
        $s = $this->as('gerente', 'POST', 'stations', ['code' => 'POSTRES', 'name' => 'Postres'])->assertCreated()->json('data');
        $this->as('gerente', 'POST', 'stations', ['code' => 'POSTRES', 'name' => 'Otra'])->assertStatus(409)->assertJsonPath('error.code', 'CATALOG_CODE_TAKEN');
        $this->as('gerente', 'POST', 'stations', ['code' => 'minúsculas', 'name' => 'Otra'])->assertStatus(422);
        $this->as('gerente', 'PATCH', "stations/{$s['id']}", ['name' => 'Repostería'], $this->v(1))->assertOk()->assertJsonPath('data.name', 'Repostería');
        $this->as('gerente', 'POST', "stations/{$s['id']}/status", ['status' => 'INACTIVE', 'reason' => 'Sin uso'], $this->v(2))->assertOk();

        // La cocina es la única ruta de los tacos.
        $kitchen = $this->station('COCINA');
        $this->as('gerente', 'POST', "stations/$kitchen/status", ['status' => 'INACTIVE', 'reason' => 'Remodelación'], $this->v(1))
            ->assertStatus(409)->assertJsonPath('error.code', 'STATION_IN_USE')->assertJsonPath('error.details.reason', 'ONLY_ROUTE');

        // La barra cubre la cocina, pero hay platillos sin terminar en ella.
        foreach (['TACOS', 'ENCHILADAS', 'SOPA'] as $code) {
            $p = Product::query()->where('code', $code)->first();
            $this->as('gerente', 'PUT', "products/{$p->uuid}/routes", ['routes' => [
                ['stationId' => $kitchen, 'priority' => 1], ['stationId' => $this->station('BARRA'), 'priority' => 2],
            ]], $this->v((int) $p->version))->assertOk();
        }
        $this->as('mesero', 'POST', 'orders', $this->round($this->openSession()), $this->key())->assertCreated();
        $this->as('gerente', 'POST', "stations/$kitchen/status", ['status' => 'INACTIVE', 'reason' => 'Remodelación'], $this->v(1))
            ->assertStatus(409)->assertJsonPath('error.details.reason', 'UNFINISHED_UNITS');
        // Una ruta a una estación inactiva no se acepta.
        $this->as('gerente', 'PUT', 'products/'.$this->product('SOPA').'/routes', ['routes' => [['stationId' => $s['id'], 'priority' => 1]]], $this->v(2))
            ->assertStatus(422)->assertJsonValidationErrors(['routes.0.stationId'], 'error.details.fields');
    }

    public function test_modificadores_conservan_sus_opciones(): void
    {
        $body = ['code' => 'SALSAS', 'name' => 'Salsas', 'minSelections' => 1, 'maxSelections' => 2, 'options' => [
            ['name' => 'Verde', 'priceDelta' => ['amount' => '0', 'currency' => 'MXN']],
            ['name' => 'Roja', 'priceDelta' => ['amount' => '0', 'currency' => 'MXN']],
        ]];
        $m = $this->as('gerente', 'POST', 'modifiers', $body)->assertCreated()->assertJsonCount(2, 'data.options')->json('data');
        $this->as('gerente', 'POST', 'modifiers', ['code' => 'OTRO', 'minSelections' => 3] + $body)->assertStatus(422);
        $this->as('gerente', 'POST', 'modifiers', ['code' => 'OTRO2', 'options' => [$body['options'][0], $body['options'][0]]] + $body)->assertStatus(422);

        [$verde, $roja] = $m['options'];
        $path = "modifiers/{$m['id']}";
        // Omitir una opción existente no se permite: se desactiva.
        $this->as('gerente', 'PATCH', $path, ['options' => [['id' => $verde['id'], 'name' => 'Verde', 'priceDelta' => $verde['priceDelta']]]], $this->v(1))
            ->assertStatus(422)->assertJsonValidationErrors(['options'], 'error.details.fields');
        $this->as('gerente', 'PATCH', $path, ['options' => [
            ['id' => $verde['id'], 'name' => 'Verde', 'priceDelta' => $verde['priceDelta']],
            ['id' => $roja['id'], 'name' => 'Roja', 'priceDelta' => $roja['priceDelta'], 'active' => false],
            ['name' => 'Habanero', 'priceDelta' => ['amount' => '5', 'currency' => 'MXN']],
        ]], $this->v(1))->assertOk()
            ->assertJsonPath('data.options.0.id', $verde['id'])->assertJsonPath('data.options.1.active', false)
            ->assertJsonPath('data.options.2.priceDelta.amount', '5.00');
        $this->as('gerente', 'PATCH', $path, ['minSelections' => 3, 'maxSelections' => 3], $this->v(2))->assertStatus(422);
        $this->as('gerente', 'POST', "$path/status", ['status' => 'INACTIVE', 'reason' => 'Temporada'], $this->v(2))->assertOk()->assertJsonPath('data.status', 'INACTIVE');
    }

    public function test_alta_y_cambios_de_mesa(): void
    {
        $t = $this->as('gerente', 'POST', 'tables', ['number' => 7, 'capacity' => 4, 'zone' => 'Jardín'])->assertCreated()
            ->assertHeader('ETag', '"1"')->assertJsonPath('data.operationalState', 'LIBRE')->json('data');
        $this->as('gerente', 'POST', 'tables', ['number' => 7, 'capacity' => 2])->assertStatus(409)->assertJsonPath('error.code', 'TABLE_NUMBER_TAKEN');
        $this->as('mesero', 'POST', 'tables', ['number' => 8, 'capacity' => 2])->assertStatus(403);
        $this->as('gerente', 'PATCH', "tables/{$t['id']}", ['number' => 1], $this->v(1))->assertStatus(409)->assertJsonPath('error.code', 'TABLE_NUMBER_TAKEN');
        $this->as('gerente', 'PATCH', "tables/{$t['id']}", ['capacity' => 6, 'zone' => null], $this->v(1))->assertOk()
            ->assertJsonPath('data.capacity', 6)->assertJsonPath('data.zone', null)->assertJsonPath('data.version', 2);

        // Una mesa ocupada no pasa a mantenimiento.
        $this->openSession(1);
        $one = $this->table(1);
        $this->as('gerente', 'POST', "tables/{$one->uuid}/status", ['status' => 'MAINTENANCE', 'reason' => 'Pata rota'], $this->v(1))
            ->assertStatus(409)->assertJsonPath('error.code', 'TABLE_ALREADY_ASSIGNED');
        $this->as('gerente', 'POST', "tables/{$t['id']}/status", ['status' => 'MAINTENANCE', 'reason' => 'Pata rota'], $this->v(2))->assertOk()
            ->assertJsonPath('data.status', 'MAINTENANCE')->assertJsonPath('data.operationalState', null);
        $this->as('mesero', 'POST', 'table-sessions', ['tableIds' => [$t['id']], 'guestCount' => 2], $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'TABLE_NOT_AVAILABLE');
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'table.status_changed')->count());
    }
}
