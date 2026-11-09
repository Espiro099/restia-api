<?php

namespace Tests\Feature\Printing;

use App\Models\AuthSession;
use App\Models\Printer;
use App\Models\PrintStation;
use App\Models\Station;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use MongoDB\BSON\ObjectId;
use Tests\Feature\Floor\FloorTestCase;

/** Identidades de servicio (D-36, sección 11) y configuración de impresión: impresoras, rutas y agentes. */
class PrintingSetupTest extends FloorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
    }

    private function service(string $token, string $method, string $path, array $body = []): TestResponse
    {
        return $this->bearer($token, $method, '/api/v1'.$path, $body);
    }

    /** @return array{0: string, 1: string} id y token */
    private function identity(string $name = 'Agente cocina', string $type = 'PRINTING_AGENT'): array
    {
        $r = $this->as('admin', 'POST', '/service-identities', ['name' => $name, 'type' => $type, 'branchIds' => [$this->branch->uuid]])->assertCreated();

        return [$r->json('data.identity.id'), $r->json('data.token')];
    }

    /** @return array{0: string, 1: string} agente y token */
    private function agent(): array
    {
        [$identity, $token] = $this->identity();
        $agent = $this->as('gerente', 'POST', 'print-agents', ['serviceIdentityId' => $identity, 'name' => 'PC de cocina'])->assertCreated()->json('data.id');

        return [$agent, $token];
    }

    private function printer(string $agent, string $code = 'COCINA-1'): array
    {
        return $this->as('gerente', 'POST', 'printers', [
            'agentId' => $agent, 'code' => $code, 'name' => 'Térmica cocina',
            'connection' => ['type' => 'NETWORK', 'host' => '192.168.1.50'], 'paperWidth' => 80,
        ])->assertCreated()->json('data');
    }

    public function test_identidad_de_servicio_emite_rota_y_revoca_su_token(): void
    {
        $body = ['name' => 'Agente cocina', 'type' => 'PRINTING_AGENT', 'branchIds' => [$this->branch->uuid]];
        $this->as('gerente', 'POST', '/service-identities', $body)->assertStatus(403);
        $this->as('mesero', 'GET', '/service-identities')->assertStatus(403);

        $created = $this->as('admin', 'POST', '/service-identities', $body)->assertCreated()->assertNoStore()->assertHeader('ETag', '"1"');
        $id = $created->json('data.identity.id');
        $token = $created->json('data.token');
        $this->assertStringStartsWith('rsv_', $token);
        $this->assertSame([$this->branch->uuid], $created->json('data.identity.branchIds'));
        $this->as('admin', 'POST', '/service-identities', $body)->assertStatus(409)->assertJsonPath('error.code', 'SERVICE_IDENTITY_NAME_TAKEN');
        // Solo se autoriza en sucursales que el ADMIN administra.
        $other = $this->branch('NORTE');
        $this->as('admin', 'POST', '/service-identities', ['branchIds' => [$other->uuid]] + $body)->assertStatus(422);
        $this->as('admin', 'GET', '/service-identities')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.token');

        // El token de servicio solo abre las rutas de servicio, y el de un empleado no las abre.
        $this->service($token, 'GET', '/service-identities/me')->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'ACTIVE');
        $this->service($token, 'GET', '/branches/'.$this->branch->uuid.'/printers')->assertStatus(401);
        $this->as('mesero', 'GET', '/service-identities/me')->assertStatus(401);
        $this->service('rsv_no-existe', 'GET', '/service-identities/me')->assertStatus(401);

        // Al rotar, el anterior vale 7 días más.
        $this->as('admin', 'POST', "/service-identities/$id/rotate")->assertStatus(400);
        $rotated = $this->as('admin', 'POST', "/service-identities/$id/rotate", [], ['If-Match' => '"1"'])->assertOk()->assertNoStore()->assertHeader('ETag', '"2"');
        $new = $rotated->json('data.token');
        $this->assertNotNull($rotated->json('data.identity.previousTokenExpiresAt'));
        $this->service($token, 'GET', '/service-identities/me')->assertOk();
        $this->service($new, 'GET', '/service-identities/me')->assertOk();
        $this->travel(8)->days();
        $this->service($token, 'GET', '/service-identities/me')->assertStatus(401);
        $this->service($new, 'GET', '/service-identities/me')->assertOk();
        $this->travelBack();

        // El token nunca queda en la auditoría.
        $audit = json_encode(DB::connection('mongodb')->table('audit_log')->where('entity_type', 'service_identity')->get());
        $this->assertStringNotContainsString($token, $audit);
        $this->assertStringNotContainsString($new, $audit);
        $this->assertStringNotContainsString(hash('sha256', $new), $audit);

        $this->as('admin', 'POST', "/service-identities/$id/revoke", ['reason' => 'Equipo robado'], ['If-Match' => '"2"'])->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->service($new, 'GET', '/service-identities/me')->assertStatus(401);
        $this->as('admin', 'POST', "/service-identities/$id/revoke", ['reason' => 'Otra vez'], ['If-Match' => '"2"'])->assertOk();
        $this->as('admin', 'POST', "/service-identities/$id/rotate", [], ['If-Match' => '"3"'])->assertStatus(409)->assertJsonPath('error.code', 'SERVICE_IDENTITY_REVOKED');
        $this->as('admin', 'PATCH', "/service-identities/$id", ['branchIds' => [$this->branch->uuid]], ['If-Match' => '"3"'])->assertStatus(409)->assertJsonPath('error.code', 'SERVICE_IDENTITY_REVOKED');
        $this->assertSame(1, $this->auditCount('service_identity.revoked'));
    }

    public function test_impresoras_rutas_y_agentes(): void
    {
        [$ai] = $this->identity('IA reservaciones', 'RESERVATION_AI');
        $this->as('gerente', 'POST', 'print-agents', ['serviceIdentityId' => $ai, 'name' => 'x'])->assertStatus(422);
        $this->as('mesero', 'GET', 'print-agents')->assertStatus(403);
        [$agent] = $this->agent();
        $identity = $this->as('gerente', 'GET', "print-agents/$agent")->assertOk()->json('data.serviceIdentityId');
        $this->as('gerente', 'POST', 'print-agents', ['serviceIdentityId' => $identity, 'name' => 'Otra PC'])->assertStatus(422);

        // Conexión: NETWORK exige host y USB el nombre local; cada uno rechaza los campos del otro.
        $base = ['agentId' => $agent, 'code' => 'BARRA-1', 'name' => 'Barra', 'paperWidth' => 58];
        $this->as('gerente', 'POST', 'printers', $base + ['connection' => ['type' => 'NETWORK']])->assertStatus(422);
        $this->as('gerente', 'POST', 'printers', $base + ['connection' => ['type' => 'USB', 'localName' => 'EPSON', 'host' => '10.0.0.1']])->assertStatus(422);
        $this->as('gerente', 'POST', 'printers', ['paperWidth' => 70] + $base + ['connection' => ['type' => 'USB', 'localName' => 'EPSON']])->assertStatus(422);
        $this->as('mesero', 'POST', 'printers', $base + ['connection' => ['type' => 'USB', 'localName' => 'EPSON']])->assertStatus(403);
        $usb = $this->as('gerente', 'POST', 'printers', $base + ['connection' => ['type' => 'USB', 'localName' => 'EPSON']])->assertCreated()
            ->assertJsonPath('data.connection', ['type' => 'USB', 'localName' => 'EPSON'])->json('data');
        $printer = $this->printer($agent);
        $this->assertSame(['type' => 'NETWORK', 'host' => '192.168.1.50', 'port' => 9100], $printer['connection']);
        $this->as('gerente', 'POST', 'printers', $base + ['connection' => ['type' => 'USB', 'localName' => 'X']])->assertStatus(409)->assertJsonPath('error.code', 'CATALOG_CODE_TAKEN');
        $this->as('mesero', 'GET', 'printers')->assertOk()->assertJsonCount(2, 'data');
        $this->as('gerente', 'PATCH', "printers/{$usb['id']}", ['name' => 'Barra principal'], ['If-Match' => '"1"'])->assertOk()->assertJsonPath('data.version', 2);

        // Rutas: la comanda necesita estación de producción; la impresora debe estar activa.
        $kitchen = Station::query()->where('code', 'COCINA')->firstOrFail()->uuid;
        $route = ['code' => 'COMANDA-COCINA', 'name' => 'Comandas de cocina', 'jobTypes' => ['KITCHEN_TICKET', 'VOID_NOTICE'], 'printerId' => $printer['id']];
        $this->as('gerente', 'POST', 'print-stations', $route)->assertStatus(422)->assertJsonValidationErrors('stationId', 'error.details.fields');
        $this->as('gerente', 'POST', 'print-stations', ['jobTypes' => ['TEST', 'TEST']] + $route)->assertStatus(422);
        $created = $this->as('gerente', 'POST', 'print-stations', ['stationId' => $kitchen] + $route)->assertCreated()
            ->assertJsonPath('data.stationId', $kitchen)->assertJsonPath('data.printerId', $printer['id'])->json('data');
        $this->as('gerente', 'POST', 'print-stations', ['code' => 'RECIBOS', 'name' => 'Recibos', 'jobTypes' => ['PRE_CHECK', 'PAYMENT_RECEIPT'], 'printerId' => $usb['id']])
            ->assertCreated()->assertJsonPath('data.stationId', null);
        // Una referencia vacía se guarda como null, no como un ObjectId inventado.
        $this->assertNull(PrintStation::query()->where('code', 'RECIBOS')->first()->getAttributes()['station_id']);
        $this->assertNull(AuthSession::query()->first()->getAttributes()['revoked_by']);
        $this->as('cocina', 'GET', 'print-stations?status=ACTIVE')->assertOk()->assertJsonCount(2, 'data');

        $this->as('gerente', 'POST', "printers/{$usb['id']}/status", ['status' => 'INACTIVE', 'reason' => 'Sin papel'], ['If-Match' => '"2"'])->assertOk();
        $this->as('gerente', 'PATCH', "print-stations/{$created['id']}", ['printerId' => $usb['id']], ['If-Match' => '"1"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PRINTER_INACTIVE');
        $this->as('gerente', 'POST', 'print-stations', ['code' => 'OTRA', 'name' => 'Otra', 'jobTypes' => ['TEST'], 'printerId' => $usb['id']])
            ->assertStatus(409)->assertJsonPath('error.code', 'PRINTER_INACTIVE');

        // Una impresora con un trabajo en manos del agente no se desactiva.
        DB::connection('mongodb')->table('print_jobs')->insert(['printer_id' => new ObjectId(Printer::query()->where('uuid', $printer['id'])->first()->getKey()), 'status' => 'PRINTING']);
        $this->as('gerente', 'POST', "printers/{$printer['id']}/status", ['status' => 'INACTIVE', 'reason' => 'Mantenimiento'], ['If-Match' => '"1"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PRINTER_HAS_ACTIVE_JOBS');

        // Un agente inactivo no recibe impresoras.
        $this->as('gerente', 'POST', "print-agents/$agent/status", ['status' => 'INACTIVE', 'reason' => 'Equipo retirado'], ['If-Match' => '"1"'])->assertOk();
        $this->as('gerente', 'POST', 'printers', $base + ['code' => 'NUEVA', 'connection' => ['type' => 'USB', 'localName' => 'X']])
            ->assertStatus(409)->assertJsonPath('error.code', 'PRINT_AGENT_INACTIVE');
        foreach (['printer.created' => 2, 'print_station.created' => 2, 'print_agent.created' => 1, 'printer.status_changed' => 1] as $action => $count) {
            $this->assertSame($count, $this->auditCount($action), $action);
        }
    }

    public function test_el_agente_lee_su_configuracion_y_late(): void
    {
        [$agent, $token] = $this->agent();
        $printer = $this->printer($agent);
        $this->printer($agent, 'APAGADA');
        $this->as('gerente', 'POST', 'printers/'.$this->as('gerente', 'GET', 'printers')->json('data.0.id').'/status', ['status' => 'INACTIVE', 'reason' => 'Descompuesta'], ['If-Match' => '"1"'])->assertOk();

        $this->as('gerente', 'GET', '/print-agent/config')->assertStatus(401);
        $config = $this->service($token, 'GET', '/print-agent/config')->assertOk();
        $config->assertJsonPath('data.agent.id', $agent)->assertJsonPath('data.pollIntervalSeconds', 3)
            ->assertJsonCount(1, 'data.printers')->assertJsonPath('data.printers.0.id', $printer['id']);

        // El latido no sube la versión: el panel puede seguir editando el agente con su If-Match.
        $beat = $this->service($token, 'POST', '/print-agent/heartbeat', ['agentVersion' => '1.4.0', 'printers' => [['printerId' => $printer['id'], 'online' => true]]])->assertOk();
        $beat->assertJsonPath('data.agentVersion', '1.4.0')->assertJsonPath('data.version', 1);
        $this->assertNotNull($beat->json('data.lastSeenAt'));
        $foreign = $this->printer($this->agent2(), 'AJENA');
        $this->service($token, 'POST', '/print-agent/heartbeat', ['printers' => [['printerId' => $foreign['id'], 'online' => false]]])->assertStatus(422);
        $this->service($token, 'POST', '/print-agent/heartbeat', ['printers' => [['printerId' => $printer['id'], 'online' => 'sí']]])->assertStatus(422);

        // Quitar la sucursal de la identidad corta al agente de inmediato; también desactivarlo.
        $identity = $this->as('gerente', 'GET', "print-agents/$agent")->json('data.serviceIdentityId');
        $other = $this->branch('NORTE');
        $this->member($this->staff['admin']['user'], $other, 'ADMIN');
        $this->as('admin', 'PATCH', "/service-identities/$identity", ['branchIds' => [$other->uuid]], ['If-Match' => '"1"'])->assertOk();
        $this->service($token, 'GET', '/print-agent/config')->assertStatus(403);
        $this->as('admin', 'PATCH', "/service-identities/$identity", ['branchIds' => [$this->branch->uuid]], ['If-Match' => '"2"'])->assertOk();
        $this->service($token, 'GET', '/print-agent/config')->assertOk();
        $this->as('gerente', 'POST', "print-agents/$agent/status", ['status' => 'INACTIVE', 'reason' => 'Equipo retirado'], ['If-Match' => '"1"'])->assertOk();
        $this->service($token, 'POST', '/print-agent/heartbeat')->assertStatus(403);
        // Una identidad de IA no entra a las rutas del agente.
        [, $ai] = $this->identity('IA reservaciones', 'RESERVATION_AI');
        $this->service($ai, 'GET', '/print-agent/config')->assertStatus(403);
    }

    private function agent2(): string
    {
        [$identity] = $this->identity('Agente barra');

        return $this->as('gerente', 'POST', 'print-agents', ['serviceIdentityId' => $identity, 'name' => 'PC de barra'])->assertCreated()->json('data.id');
    }
}
