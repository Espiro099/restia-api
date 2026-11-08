<?php

namespace Tests\Feature\Floor;

use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Mesas con estado derivado (T-5), apertura de sesiones (T-7, T-9) y menú con ETag (M-9). */
class TablesAndMenuTest extends FloorTestCase
{
    public function test_lista_mesas_libres_con_etag_y_304(): void
    {
        $r = $this->as('cocina', 'GET', 'tables')->assertOk()
            ->assertJsonPath('meta.pagination.total', 6)
            ->assertJsonPath('data.0.number', 1)
            ->assertJsonPath('data.0.operationalState', 'LIBRE')
            ->assertJsonPath('data.0.currentSessionId', null)
            ->assertJsonPath('data.0.branchId', $this->branch->uuid);
        $etag = $r->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->as('cocina', 'GET', 'tables', headers: ['If-None-Match' => $etag])->assertStatus(304)->assertHeader('ETag', $etag);

        $this->openSession(1);
        // Cambió el estado de una mesa: el ETag anterior ya no coincide.
        $this->as('cocina', 'GET', 'tables', headers: ['If-None-Match' => $etag])->assertOk();
    }

    public function test_abrir_sesion_ocupa_la_mesa_y_se_filtra_por_estado(): void
    {
        $session = $this->openSession(3);

        $this->as('mesero', 'GET', 'tables?operationalState=OCUPADA_SIN_COMANDA')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 3)
            ->assertJsonPath('data.0.currentSessionId', $session);
        $this->as('mesero', 'GET', "table-sessions/$session")->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.tables.0.number', 3)
            ->assertJsonPath('data.tables.0.phase', 'SEATED')
            ->assertJsonPath('data.responsibleUserId', $this->staff['mesero']['user']->uuid)
            ->assertJsonPath('data.membershipVersion', 1);
        $this->assertSame(1, $this->auditCount('table_session.opened'));
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'table_session.opened')->count());
    }

    public function test_misma_clave_devuelve_la_misma_sesion_y_otra_clave_choca(): void
    {
        $key = $this->key();
        $body = ['tableIds' => [$this->table(1)->uuid], 'guestCount' => 2];
        $first = $this->as('mesero', 'POST', 'table-sessions', $body, $key)->assertCreated()->json('data.id');
        $this->as('mesero', 'POST', 'table-sessions', $body, $key)->assertCreated()->assertJsonPath('data.id', $first);
        $this->assertSame(1, TableSession::query()->count());

        $this->as('mesero', 'POST', 'table-sessions', ['guestCount' => 3] + $body, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        // Otro usuario con la misma clave tampoco recibe la respuesta guardada (CON-1).
        $this->as('gerente', 'POST', 'table-sessions', $body, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->as('gerente', 'POST', 'table-sessions', $body, $this->key())->assertStatus(409)->assertJsonPath('error.code', 'TABLE_ALREADY_ASSIGNED');
        $this->assertSame(1, TableAssignment::query()->count());
    }

    public function test_reglas_de_apertura(): void
    {
        $body = fn (array $tables) => ['tableIds' => $tables, 'guestCount' => 2];
        $this->as('mesero', 'POST', 'table-sessions', $body([$this->table(1)->uuid]))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['fields' => ['Idempotency-Key']]]]);
        $this->as('cocina', 'POST', 'table-sessions', $body([$this->table(1)->uuid]), $this->key())->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->as('mesero', 'POST', 'table-sessions', $body([(string) Str::uuid()]), $this->key())->assertStatus(409)->assertJsonPath('error.code', 'TABLE_NOT_AVAILABLE');

        $this->table(2)->update(['status' => RestaurantTable::MAINTENANCE]);
        $this->as('mesero', 'POST', 'table-sessions', $body([$this->table(2)->uuid]), $this->key())->assertStatus(409)->assertJsonPath('error.code', 'TABLE_NOT_AVAILABLE');
        $this->as('mesero', 'GET', 'tables?status=MAINTENANCE')->assertJsonPath('data.0.operationalState', null);

        $this->as('mesero', 'POST', 'table-sessions', $body([$this->table(1)->uuid, $this->table(1)->uuid]), $this->key())->assertStatus(422);
        $this->as('mesero', 'POST', 'table-sessions', $body([$this->table(1)->uuid]) + ['extra' => 1], $this->key())->assertStatus(422);
        $this->as('mesero', 'POST', 'table-sessions', $body([$this->table(1)->uuid]) + ['responsibleUserId' => (string) Str::uuid()], $this->key())
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['responsibleUserId']]]]);

        Branch::query()->where('_id', $this->branch->getKey())->update(['status' => Branch::INACTIVE]);
        $this->as('mesero', 'POST', 'table-sessions', $body([$this->table(1)->uuid]), $this->key())->assertStatus(409)->assertJsonPath('error.code', 'BRANCH_INACTIVE');
        $this->assertSame(0, TableSession::query()->count());
    }

    public function test_una_sesion_puede_ocupar_varias_mesas(): void
    {
        $r = $this->as('mesero', 'POST', 'table-sessions', ['tableIds' => [$this->table(4)->uuid, $this->table(6)->uuid], 'guestCount' => 12], $this->key())
            ->assertCreated();
        $this->assertSame([4, 6], array_column($r->json('data.tables'), 'number'));
        $this->as('mesero', 'GET', 'table-sessions?status=ACTIVE&tableId='.$this->table(6)->uuid)->assertOk()->assertJsonPath('data.0.id', $r->json('data.id'));
    }

    public function test_otra_sucursal_no_ve_ni_usa_estas_mesas(): void
    {
        $norte = $this->branch('NORTE');
        $this->staff['otro'] = $this->staffMember('otro@ejemplo.mx', $norte, 'MANAGER');
        $mesa = $this->table(1)->uuid;

        $this->as('otro', 'GET', "/branches/{$norte->uuid}/tables/$mesa")->assertStatus(404);
        $this->as('otro', 'POST', "/branches/{$norte->uuid}/table-sessions", ['tableIds' => [$mesa], 'guestCount' => 2], $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'TABLE_NOT_AVAILABLE');
        // Su dispositivo es de NORTE: no puede operar CENTRO aunque tuviera membresía (A-6).
        $this->as('otro', 'GET', 'tables')->assertStatus(403);
    }

    public function test_menu_con_productos_modificadores_y_etag(): void
    {
        $r = $this->as('mesero', 'GET', 'menu')->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.branchId', $this->branch->uuid)
            ->assertJsonPath('data.menuVersion', 1);
        $products = collect($r->json('data.products'))->keyBy('code');
        $this->assertCount(6, $products);
        $this->assertSame(['amount' => '35.00', 'currency' => 'MXN'], $products['AGUA']['price']);
        $this->assertSame('SOLD_OUT', $products['LIMONADA']['availability']);
        $size = collect($r->json('data.modifiers'))->firstWhere('name', 'Tamaño');
        $this->assertSame([$size['id']], $products['AGUA']['modifierIds']);
        $this->assertSame(['Chico', 'Grande'], array_column($size['options'], 'name'));
        $this->assertSame('15.00', $size['options'][1]['priceDelta']['amount']);
        $this->assertArrayNotHasKey('fiscal', $products['AGUA']);

        $this->as('cocina', 'GET', 'menu', headers: ['If-None-Match' => '"1"'])->assertStatus(304);
    }

    public function test_parametros_de_consulta_que_no_son_texto_se_rechazan(): void
    {
        $this->as('mesero', 'GET', 'tables?status[$ne]=ACTIVE')->assertStatus(422);
        $this->as('mesero', 'GET', 'orders?sessionId[$ne]=x')->assertStatus(422);
    }
}
