<?php

namespace Tests\Feature\Identity;

use App\Models\Branch;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use Tests\Feature\Floor\FloorTestCase;

/** Sucursales (Decisiones_Branches, B-2): lectura, alta, cambios y estado sin operación activa. */
class BranchesTest extends FloorTestCase
{
    private array $address = ['line1' => 'Av. Juárez 100', 'city' => 'Guadalajara', 'state' => 'Jalisco', 'postalCode' => '44100', 'country' => 'MX'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
    }

    private function version(): string
    {
        return '"'.$this->branch->fresh()->version.'"';
    }

    public function test_lectura_y_alta_con_su_admin(): void
    {
        // En Android solo la sucursal del dispositivo.
        $this->as('mesero', 'GET', '/branches')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->branch->uuid)
            ->assertJsonPath('data.0.reservationHours.0.opensAt', '12:00')->assertJsonPath('data.0.reservationNoShowToleranceMinutes', 15);
        $this->as('mesero', 'GET', '')->assertOk()->assertHeader('ETag')->assertJsonPath('data.code', 'CENTRO');

        $body = ['code' => 'NORTE', 'name' => 'Restia Norte', 'timezone' => 'America/Mexico_City', 'currency' => 'MXN', 'businessDayCutoff' => '04:00', 'address' => $this->address];
        $this->as('gerente', 'POST', '/branches', $body)->assertStatus(403);
        $this->as('admin', 'POST', '/branches', ['timezone' => 'Marte/Olympus'] + $body)->assertStatus(422);
        $this->as('admin', 'POST', '/branches', ['code' => 'norte'] + $body)->assertStatus(422);
        $norte = $this->as('admin', 'POST', '/branches', $body)->assertCreated()->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.address.city', 'Guadalajara')->assertJsonPath('data.reservationHours', [])->json('data');
        $this->as('admin', 'POST', '/branches', $body)->assertStatus(409)->assertJsonPath('error.code', 'BRANCH_CODE_TAKEN');
        // Quien la crea queda como su ADMIN.
        $created = Branch::query()->where('uuid', $norte['id'])->first();
        $this->assertSame('ADMIN', Membership::query()->where('branch_id', new ObjectId($created->getKey()))
            ->where('user_id', new ObjectId($this->staff['admin']['user']->getKey()))->first()->role);
        $this->as('gerente', 'GET', "/branches/{$norte['id']}")->assertStatus(403);
    }

    public function test_cambios_y_horario_de_reservas(): void
    {
        $this->as('gerente', 'PATCH', '', ['name' => 'Otro'], ['If-Match' => $this->version()])->assertStatus(403);
        $this->as('admin', 'PATCH', '', ['code' => 'OTRO'], ['If-Match' => $this->version()])->assertStatus(422);
        $this->as('admin', 'PATCH', '', ['reservationHours' => [['dayOfWeek' => 8, 'opensAt' => '13:00', 'closesAt' => '22:00']]], ['If-Match' => $this->version()])->assertStatus(422);
        $hours = [['dayOfWeek' => 5, 'opensAt' => '13:00', 'closesAt' => '23:30'], ['dayOfWeek' => 6, 'opensAt' => '13:00', 'closesAt' => '01:00']];
        $v = $this->version();
        $this->as('admin', 'PATCH', '', ['name' => 'Restia Centro Histórico', 'reservationHours' => $hours, 'reservationNoShowToleranceMinutes' => 20, 'address' => $this->address],
            ['If-Match' => $v])->assertOk()->assertJsonPath('data.reservationHours', $hours)->assertJsonPath('data.reservationNoShowToleranceMinutes', 20)
            ->assertJsonPath('data.name', 'Restia Centro Histórico');
        $this->as('admin', 'PATCH', '', ['name' => 'Otra vez'], ['If-Match' => $v])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $audit = DB::connection('mongodb')->table('audit_log')->where('action', 'branch.updated')->first();
        $this->assertSame('Restia Centro Histórico', ((array) ((array) $audit)['changes']['after'])['name']);

        // La zona horaria y el corte solo cambian sin operación activa.
        $session = $this->openSession(1);
        $this->as('admin', 'PATCH', '', ['businessDayCutoff' => '05:00'], ['If-Match' => $this->version()])->assertStatus(409)
            ->assertJsonPath('error.code', 'BRANCH_HAS_ACTIVE_OPERATIONS')->assertJsonPath('error.details.tableSessions', 1);
        $this->as('admin', 'PATCH', '', ['phone' => '+523300000000'], ['If-Match' => $this->version()])->assertOk();
        $this->as('gerente', 'POST', "table-sessions/$session/cancel", ['reason' => 'Prueba'], ['If-Match' => '"1"'])->assertOk();
        $this->as('admin', 'PATCH', '', ['businessDayCutoff' => '05:00'], ['If-Match' => $this->version()])->assertOk()->assertJsonPath('data.businessDayCutoff', '05:00');
    }

    public function test_desactivar_solo_sin_operacion_y_reactivar(): void
    {
        $session = $this->openSession(2);
        $this->as('admin', 'POST', 'deactivate', ['reason' => 'Remodelación'], ['If-Match' => $this->version()])->assertStatus(409)
            ->assertJsonPath('error.code', 'BRANCH_HAS_ACTIVE_OPERATIONS');
        $this->as('gerente', 'POST', "table-sessions/$session/cancel", ['reason' => 'Prueba'], ['If-Match' => '"1"'])->assertOk();
        $this->as('gerente', 'POST', 'deactivate', ['reason' => 'Remodelación'], ['If-Match' => $this->version()])->assertStatus(403);
        $this->as('admin', 'POST', 'deactivate', ['reason' => 'x'], ['If-Match' => $this->version()])->assertStatus(422);
        $this->as('admin', 'POST', 'deactivate', ['reason' => 'Remodelación'], ['If-Match' => $this->version()])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        // Ya inactiva, sin cambios; y no se abren mesas.
        $this->as('admin', 'POST', 'deactivate', ['reason' => 'Remodelación'], ['If-Match' => $this->version()])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->as('mesero', 'POST', 'table-sessions', ['tableIds' => [$this->table(3)->uuid], 'guestCount' => 2], $this->key())->assertStatus(409)
            ->assertJsonPath('error.code', 'BRANCH_INACTIVE');
        $this->as('admin', 'POST', 'activate', [], ['If-Match' => $this->version()])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertSame(Branch::ACTIVE, $this->branch->fresh()->status);
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'branch.deactivated')->count());
    }
}
