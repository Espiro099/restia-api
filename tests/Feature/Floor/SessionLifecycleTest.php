<?php

namespace Tests\Feature\Floor;

use App\Models\TableAssignment;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** Ciclo de vida de la sesión (T-1 a T-4): editar, agregar, separar, cerrar, limpiar y cancelar. */
class SessionLifecycleTest extends FloorTestCase
{
    private function open(array $tables, string $who = 'mesero'): array
    {
        return $this->as($who, 'POST', 'table-sessions', ['tableIds' => array_map(fn ($n) => $this->table($n)->uuid, $tables), 'guestCount' => 4], $this->key())
            ->assertCreated()->json('data');
    }

    private function act(string $who, string $session, string $path, int $version, array $body = []): TestResponse
    {
        return $this->as($who, 'POST', "table-sessions/$session/$path", $body, ['If-Match' => '"'.$version.'"']);
    }

    private function clean(string $who, string $session, int $table, int $membership): TestResponse
    {
        return $this->as($who, 'POST', "table-sessions/$session/tables/{$this->table($table)->uuid}/cleaning-confirmation", ['membershipVersion' => $membership]);
    }

    private function stateOf(int $table): ?string
    {
        return collect($this->as('mesero', 'GET', 'tables')->json('data'))->firstWhere('number', $table)['operationalState'];
    }

    public function test_cerrar_deja_cada_mesa_por_limpiar_y_la_ultima_limpieza_cierra(): void
    {
        $s = $this->open([1, 2]);
        $closed = $this->act('mesero', $s['id'], 'close', 1)->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.status', 'PENDING_CLEANING')
            ->assertJsonPath('data.tables.0.phase', 'CLEANING')
            ->assertJsonPath('data.tables.1.cleaningMembershipVersion', 1)
            ->json('data');
        $this->assertNotNull($closed['closeRequestedAt']);
        $this->assertSame('POR_LIMPIAR', $this->stateOf(1));
        // Sigue bloqueada hasta confirmar la limpieza (T-2).
        $this->as('mesero', 'POST', 'table-sessions', ['tableIds' => [$this->table(1)->uuid], 'guestCount' => 2], $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'TABLE_ALREADY_ASSIGNED');

        $this->clean('mesero', $s['id'], 1, 1)->assertOk()->assertJsonPath('data.status', 'PENDING_CLEANING')->assertJsonCount(1, 'data.tables');
        $this->assertSame('LIBRE', $this->stateOf(1));
        // Repetir una confirmación hecha responde igual, sin cambios.
        $this->clean('mesero', $s['id'], 1, 1)->assertOk();
        $this->clean('mesero', $s['id'], 2, 1)->assertOk()->assertJsonPath('data.status', 'CLOSED')->assertJsonPath('data.version', 4);
        $this->assertNotNull(TableSession::query()->first()->closed_at);
        $this->assertSame(1, $this->auditCount('table_session.closed'));
        $this->assertSame(2, DB::connection('mongodb')->table('outbox_events')->where('type', 'table.cleaned')->count());
        $this->open([1, 2]);
    }

    public function test_no_cierra_con_platillos_por_terminar(): void
    {
        $s = $this->open([1]);
        $order = $this->as('mesero', 'POST', 'orders', $this->round($s['id']), $this->key())->assertCreated()->json('data');
        $this->act('mesero', $s['id'], 'close', 2)->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->act('mesero', $s['id'], 'close', 1)->assertStatus(409)->assertJsonPath('error.code', 'SESSION_HAS_PENDING_UNITS')
            ->assertJsonPath('error.details.units', 3);

        $all = [...$this->unitsOf($order), ...$this->unitsOf($order, 1)];
        $this->transition('cocina', 'START', $all)->assertOk();
        $this->transition('cocina', 'READY', $this->bump($all))->assertOk();
        $this->transition('mesero', 'DELIVER', $this->bump($this->bump($all)))->assertOk();
        $this->act('mesero', $s['id'], 'close', 1)->assertOk();
        // Cerrada, ya no recibe comandas.
        $this->as('mesero', 'POST', 'orders', $this->round($s['id']), $this->key())->assertStatus(409)->assertJsonPath('error.code', 'SESSION_NOT_ACTIVE');
    }

    public function test_agregar_y_separar_mesas_cambian_la_version_de_membresia(): void
    {
        $s = $this->open([1]);
        $this->act('mesero', $s['id'], 'tables', 1, ['tableIds' => [$this->table(2)->uuid, $this->table(3)->uuid]])->assertOk()
            ->assertJsonPath('data.membershipVersion', 2)->assertJsonPath('data.version', 2)->assertJsonCount(3, 'data.tables');
        $this->act('mesero', $s['id'], 'tables', 2, ['tableIds' => [$this->table(3)->uuid]])->assertStatus(409)->assertJsonPath('error.code', 'TABLE_ALREADY_ASSIGNED');

        // Separar sin limpieza la libera al momento; con limpieza queda POR_LIMPIAR.
        $this->act('mesero', $s['id'], 'tables/'.$this->table(3)->uuid.'/release', 2, ['requiresCleaning' => false])->assertOk()
            ->assertJsonPath('data.membershipVersion', 3)->assertJsonCount(2, 'data.tables');
        $this->assertSame('LIBRE', $this->stateOf(3));
        $released = $this->act('mesero', $s['id'], 'tables/'.$this->table(2)->uuid.'/release', 3, ['requiresCleaning' => true])->assertOk()->json('data');
        $this->assertSame(['SEATED', 'CLEANING'], array_column($released['tables'], 'phase'));
        $this->assertSame(4, $released['tables'][1]['cleaningMembershipVersion']);
        $this->assertSame('POR_LIMPIAR', $this->stateOf(2));

        $this->act('mesero', $s['id'], 'tables/'.$this->table(1)->uuid.'/release', 4, ['requiresCleaning' => false])
            ->assertStatus(409)->assertJsonPath('error.code', 'SESSION_LAST_TABLE');
        $this->act('mesero', $s['id'], 'tables/'.$this->table(5)->uuid.'/release', 4, ['requiresCleaning' => false])->assertStatus(404);

        // La limpieza de una mesa separada sigue valiendo aunque la sesión cambie después.
        $this->act('mesero', $s['id'], 'tables', 4, ['tableIds' => [$this->table(4)->uuid]])->assertOk()->assertJsonPath('data.membershipVersion', 5);
        $this->clean('mesero', $s['id'], 2, 3)->assertStatus(409)->assertJsonPath('error.code', 'CLEANING_CONFIRMATION_STALE');
        $this->clean('mesero', $s['id'], 2, 4)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertSame('LIBRE', $this->stateOf(2));
    }

    public function test_cancelar_solo_sin_actividad_y_solo_gerente(): void
    {
        $s = $this->open([1]);
        $this->act('mesero', $s['id'], 'cancel', 1, ['reason' => 'Se equivocó de mesa'])->assertStatus(403);
        $this->act('gerente', $s['id'], 'cancel', 1)->assertStatus(422);

        $order = $this->as('mesero', 'POST', 'orders', $this->round($s['id']), $this->key())->json('data');
        $this->transition('mesero', 'CANCEL', [...$this->unitsOf($order), ...$this->unitsOf($order, 1)], 'Error')->assertOk();
        // Lo cancelado nunca se preparó: no cuenta como actividad.
        $this->act('gerente', $s['id'], 'cancel', 1, ['reason' => 'Se equivocó de mesa'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame('LIBRE', $this->stateOf(1));
        $this->assertSame(0, TableAssignment::query()->where('active', true)->count());
        $audit = (array) DB::connection('mongodb')->table('audit_log')->where('action', 'table_session.cancelled')->first();
        $this->assertSame('Se equivocó de mesa', $audit['reason']);
        $this->act('gerente', $s['id'], 'close', 2)->assertStatus(409)->assertJsonPath('error.code', 'SESSION_NOT_ACTIVE');
    }

    public function test_cancelar_con_algo_preparado_o_en_limpieza_se_rechaza(): void
    {
        $s = $this->open([1]);
        $order = $this->as('mesero', 'POST', 'orders', $this->round($s['id']), $this->key())->json('data');
        $this->transition('cocina', 'START', $this->unitsOf($order, 1))->assertOk();
        $this->act('gerente', $s['id'], 'cancel', 1, ['reason' => 'Se fueron'])->assertStatus(409)->assertJsonPath('error.code', 'SESSION_HAS_ACTIVITY');

        $t = $this->open([2, 3]);
        $this->act('mesero', $t['id'], 'tables/'.$this->table(3)->uuid.'/release', 1, ['requiresCleaning' => true])->assertOk();
        $this->act('gerente', $t['id'], 'cancel', 2, ['reason' => 'Se fueron'])->assertStatus(409)->assertJsonPath('error.code', 'SESSION_HAS_ACTIVITY');
    }

    public function test_editar_comensales_y_responsable_con_if_match(): void
    {
        $s = $this->open([1]);
        $path = "table-sessions/{$s['id']}";
        $this->as('mesero', 'PATCH', $path, ['guestCount' => 6])->assertStatus(400)->assertJsonPath('error.code', 'VERSION_REQUIRED');
        $this->as('mesero', 'PATCH', $path, [], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('mesero', 'PATCH', $path, ['guestCount' => 6, 'responsibleUserId' => $this->staff['gerente']['user']->uuid], ['If-Match' => '"1"'])->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.guestCount', 6)
            ->assertJsonPath('data.responsibleUserId', $this->staff['gerente']['user']->uuid);
        $this->as('mesero', 'PATCH', $path, ['guestCount' => 7], ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->as('mesero', 'PATCH', $path, ['guestCount' => 7], ['If-Match' => 'basura'])->assertStatus(409);
        $this->as('cocina', 'PATCH', $path, ['guestCount' => 7], ['If-Match' => '"2"'])->assertStatus(403);
    }

    public function test_limpieza_que_no_existe_o_de_otra_sesion(): void
    {
        $s = $this->open([1]);
        $this->clean('mesero', $s['id'], 1, 1)->assertStatus(409)->assertJsonPath('error.code', 'CLEANING_CONFIRMATION_STALE');
        $this->clean('cocina', $s['id'], 1, 1)->assertStatus(403);
        $this->as('mesero', 'POST', "table-sessions/{$s['id']}/tables/no-es-uuid/cleaning-confirmation", ['membershipVersion' => 1])->assertStatus(404);
    }
}
