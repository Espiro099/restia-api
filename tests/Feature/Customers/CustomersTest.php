<?php

namespace Tests\Feature\Customers;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Floor\FloorTestCase;

/** Clientes (CU-1 a CU-4): perfil por sucursal, búsqueda por POST, datos enmascarados en la auditoría y anonimizar. */
class CustomersTest extends FloorTestCase
{
    private array $ana = ['name' => 'Ana López', 'phone' => '+523312345678', 'email' => 'ana@correo.mx', 'notes' => 'Alérgica a la nuez', 'preferences' => ['Terraza']];

    public function test_alta_lectura_busqueda_y_edicion(): void
    {
        $this->as('cocina', 'POST', 'customers', $this->ana, $this->key())->assertStatus(403);
        $this->as('mesero', 'POST', 'customers', ['name' => 'X', 'phone' => '3312345678'], $this->key())->assertStatus(422);
        $key = $this->key();
        $ana = $this->as('mesero', 'POST', 'customers', $this->ana, $key)->assertCreated()
            ->assertJsonPath('data.anonymized', false)->assertJsonPath('data.profile.preferences', ['Terraza'])
            ->assertJsonPath('data.profile.visitCount', 0)->json('data');
        $this->as('mesero', 'POST', 'customers', $this->ana, $key)->assertCreated()->assertJsonPath('data.id', $ana['id']);
        $this->as('mesero', 'POST', 'customers', ['name' => 'Beto Ruiz', 'phone' => '+523398765432'], $this->key())->assertCreated();

        $this->as('mesero', 'GET', "customers/{$ana['id']}")->assertOk()->assertHeader('ETag', '"1"')->assertJsonPath('data.email', 'ana@correo.mx');
        $this->as('mesero', 'POST', 'customers/search', [])->assertStatus(422);
        $this->as('mesero', 'POST', 'customers/search', ['phone' => '+523312345678'])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ana['id']);
        $this->as('mesero', 'POST', 'customers/search', ['name' => 'lóp'])->assertOk()->assertJsonCount(1, 'data');
        $this->as('mesero', 'POST', 'customers/search', ['name' => '.*'])->assertOk()->assertJsonCount(0, 'data');
        $this->as('mesero', 'POST', 'customers/search', ['name' => 'Ru', 'phone' => '+523312345678'])->assertOk()->assertJsonCount(0, 'data');

        $this->as('mesero', 'PATCH', "customers/{$ana['id']}", ['notes' => 'Prefiere ventana'])->assertStatus(400);
        $this->as('mesero', 'PATCH', "customers/{$ana['id']}", [], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('mesero', 'PATCH', "customers/{$ana['id']}", ['phone' => '+523311112222', 'notes' => 'Prefiere ventana'], ['If-Match' => '"1"'])->assertOk()
            ->assertHeader('ETag', '"2"')->assertJsonPath('data.phone', '+523311112222')->assertJsonPath('data.profile.notes', 'Prefiere ventana');
        $this->as('mesero', 'PATCH', "customers/{$ana['id']}", ['name' => 'Ana'], ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');

        // La auditoría nunca guarda el dato completo.
        $audit = json_encode(DB::connection('mongodb')->table('audit_log')->where('entity_type', 'customer')->get(), JSON_UNESCAPED_UNICODE);
        foreach (['Ana López', '+523312345678', '+523311112222', 'ana@correo.mx', 'Alérgica a la nuez', 'Prefiere ventana'] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }
        $this->assertStringContainsString('…2222', $audit);
    }

    public function test_cada_sucursal_ve_solo_sus_clientes(): void
    {
        $ana = $this->as('mesero', 'POST', 'customers', $this->ana, $this->key())->assertCreated()->json('data.id');
        $norte = Branch::query()->create(['code' => 'NORTE', 'name' => 'Norte', 'status' => Branch::ACTIVE, 'timezone' => 'America/Mexico_City', 'currency' => 'MXN']);
        $this->staff['norte'] = $this->staffMember('mesero@norte.mx', $norte, 'WAITER');
        $base = "/branches/{$norte->uuid}/customers";

        $this->as('norte', 'GET', "$base/$ana")->assertStatus(404);
        $this->as('norte', 'POST', "$base/search", ['phone' => '+523312345678'])->assertOk()->assertJsonCount(0, 'data');
        // El mismo teléfono en otra sucursal es otra identidad.
        $otra = $this->as('norte', 'POST', $base, $this->ana, $this->key())->assertCreated()->json('data.id');
        $this->assertNotSame($ana, $otra);
        $this->as('norte', 'POST', "$base/search", ['phone' => '+523312345678'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $otra);
    }

    public function test_anonimizar_solo_admin_y_borra_tambien_las_reservas(): void
    {
        $ana = $this->as('mesero', 'POST', 'customers', $this->ana, $this->key())->assertCreated()->json('data');
        $customerId = DB::connection('mongodb')->table('customers')->where('uuid', $ana['id'])->first();
        $customerId = ((array) $customerId)['_id'] ?? ((array) $customerId)['id'];
        DB::connection('mongodb')->table('reservations')->insert([
            'customer_id' => $customerId, 'customer_snapshot' => ['name' => 'Ana López', 'phone' => '+523312345678'], 'notes' => 'Cumpleaños',
            'personal_data_removed_at' => null,
        ]);
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
        $path = "customers/{$ana['id']}/anonymize";

        $this->as('gerente', 'POST', $path, ['reason' => 'Lo pidió el cliente'], ['If-Match' => '"1"'])->assertStatus(403);
        $this->as('admin', 'POST', $path, ['reason' => 'x'], ['If-Match' => '"1"'])->assertStatus(422);
        $done = $this->as('admin', 'POST', $path, ['reason' => 'Lo pidió el cliente'], ['If-Match' => '"1"'])->assertOk()
            ->assertJsonPath('data.anonymized', true)->assertJsonPath('data.name', 'Cliente anonimizado')->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.email', null)->assertJsonPath('data.profile.notes', null)->assertJsonPath('data.profile.preferences', [])->json('data');
        $this->as('admin', 'POST', $path, ['reason' => 'Otra vez'], ['If-Match' => '"'.$done['version'].'"'])->assertStatus(409)
            ->assertJsonPath('error.code', 'CUSTOMER_ANONYMIZED');
        $this->as('mesero', 'PATCH', "customers/{$ana['id']}", ['name' => 'Ana'], ['If-Match' => '"'.$done['version'].'"'])->assertStatus(409)
            ->assertJsonPath('error.code', 'CUSTOMER_ANONYMIZED');
        $this->as('mesero', 'POST', 'customers/search', ['phone' => '+523312345678'])->assertJsonCount(0, 'data');

        $reservation = (array) DB::connection('mongodb')->table('reservations')->first();
        $this->assertSame('Cliente anonimizado', ((array) $reservation['customer_snapshot'])['name']);
        $this->assertNull(((array) $reservation['customer_snapshot'])['phone']);
        $this->assertNull($reservation['notes']);
        $this->assertNotNull($reservation['personal_data_removed_at']);

        $audit = DB::connection('mongodb')->table('audit_log')->where('action', 'customer.anonymized')->first();
        $this->assertSame('Lo pidió el cliente', ((array) $audit)['reason']);
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'customer.anonymized')->count());
    }
}
