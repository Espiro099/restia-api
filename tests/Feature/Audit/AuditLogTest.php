<?php

namespace Tests\Feature\Audit;

use App\Models\Branch;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Tests\Feature\Floor\FloorTestCase;

/** AU-1: la bitácora se consulta por sucursal (ADMIN y MANAGER) y globalmente (solo ADMIN), sin ObjectIds. */
class AuditLogTest extends FloorTestCase
{
    private function range(): string
    {
        return 'from='.urlencode(now()->subHour()->toIso8601String()).'&to='.urlencode(now()->addHour()->toIso8601String());
    }

    public function test_consulta_por_sucursal_con_filtros_y_sin_ids_internos(): void
    {
        $session = $this->openSession(2);
        $this->as('gerente', 'POST', 'stations', ['code' => 'POSTRES', 'name' => 'Postres'])->assertCreated();

        $this->as('mesero', 'GET', 'audit-log?'.$this->range())->assertStatus(403);
        $this->as('cocina', 'GET', 'audit-log?'.$this->range())->assertStatus(403);
        $this->as('gerente', 'GET', 'audit-log')->assertStatus(422);

        $all = $this->as('gerente', 'GET', 'audit-log?'.$this->range().'&perPage=100')->assertOk();
        $rows = $all->json('data');
        $this->assertNotEmpty($rows);
        // Del más reciente al más antiguo.
        $this->assertSame('station.created', $rows[0]['action']);
        $this->assertSame($this->branch->uuid, $rows[0]['branchId']);
        $this->assertSame($this->staff['gerente']['user']->uuid, $rows[0]['actorId']);
        $this->assertSame('USER', $rows[0]['actorType']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $rows[0]['id']);
        // El documento copiado en `changes` no lleva _id ni branch_id.
        $after = $rows[0]['changes']['after'];
        $this->assertSame('POSTRES', $after['code']);
        $this->assertArrayNotHasKey('_id', $after);
        $this->assertArrayNotHasKey('branch_id', $after);
        $this->assertDoesNotMatchRegularExpression('/"[0-9a-f]{24}"/', $all->getContent());

        $opened = $this->as('gerente', 'GET', 'audit-log?'.$this->range().'&action=table_session.opened')->assertOk()->json('data');
        $this->assertCount(1, $opened);
        $this->assertSame($session, $opened[0]['entityId']);
        $this->assertSame('table_session', $opened[0]['entityType']);
        $this->as('gerente', 'GET', 'audit-log?'.$this->range().'&entityId='.$session)->assertOk()->assertJsonCount(1, 'data');
        $this->as('gerente', 'GET', 'audit-log?'.$this->range().'&entityType=table_session&entityId='.$session)->assertOk()->assertJsonCount(1, 'data');
        $byWaiter = $this->as('gerente', 'GET', 'audit-log?'.$this->range().'&actorId='.$this->staff['mesero']['user']->uuid)->assertOk()->json('data');
        $this->assertContains('table_session.opened', array_column($byWaiter, 'action'));
        $this->assertNotContains('station.created', array_column($byWaiter, 'action'));
        $this->as('gerente', 'GET', 'audit-log?'.$this->range().'&perPage=1')->assertOk()->assertJsonPath('meta.pagination.perPage', 1)
            ->assertJsonCount(1, 'data');
    }

    public function test_el_rango_tiene_limite_y_respeta_la_sucursal(): void
    {
        $from = urlencode(now()->subDays(40)->toIso8601String());
        $to = urlencode(now()->toIso8601String());
        $this->as('gerente', 'GET', "audit-log?from=$from&to=$to")->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['to']]]]);
        $this->as('gerente', 'GET', "audit-log?from=$to&to=$from")->assertStatus(422);

        // Lo de otra sucursal no aparece, ni con su id.
        $other = Branch::query()->create(['code' => 'NORTE', 'name' => 'Norte', 'status' => Branch::ACTIVE, 'timezone' => 'America/Mexico_City', 'currency' => 'MXN']);
        Audit::record('branch.updated', 'branch', $other->getKey(), $other->getKey());
        $this->assertSame([], array_filter(
            $this->as('gerente', 'GET', 'audit-log?'.$this->range().'&perPage=100')->json('data'),
            fn ($r) => $r['entityType'] === 'branch'));
        $this->as('gerente', 'GET', "/branches/{$other->uuid}/audit-log?".$this->range())->assertStatus(403);
    }

    public function test_global_solo_admin_e_incluye_lo_que_no_es_de_una_sucursal(): void
    {
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
        $this->as('gerente', 'GET', '/audit-log?'.$this->range())->assertStatus(403);

        // Una entrada vieja, sin uuid y sin sucursal: recibe un id estable derivado del _id.
        DB::connection('mongodb')->table('audit_log')->insert([
            '_id' => $legacy = new ObjectId, 'branch_id' => null, 'actor_type' => 'SYSTEM', 'actor_id' => null,
            'action' => 'service_identity.revoked', 'entity_type' => 'service_identity', 'entity_id' => new ObjectId,
            'reason' => null, 'changes' => null, 'request_id' => null, 'created_at' => new UTCDateTime,
        ]);
        $first = $this->as('admin', 'GET', '/audit-log?'.$this->range().'&action=service_identity.revoked')->assertOk()->json('data.0');
        $this->assertNull($first['branchId']);
        $this->assertNull($first['entityId']);
        $this->assertSame('SYSTEM', $first['actorType']);
        $again = $this->as('admin', 'GET', '/audit-log?'.$this->range().'&action=service_identity.revoked')->json('data.0.id');
        $this->assertSame($first['id'], $again);
        $this->assertStringNotContainsString((string) $legacy, json_encode($first));

        $this->openSession(3);
        $this->as('admin', 'GET', '/audit-log?'.$this->range().'&action=table_session.opened')->assertOk()->assertJsonCount(1, 'data');
        // La de la sucursal no muestra lo global.
        $this->as('admin', 'GET', 'audit-log?'.$this->range().'&action=service_identity.revoked')->assertOk()->assertJsonCount(0, 'data');
    }
}
