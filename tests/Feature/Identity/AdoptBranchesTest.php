<?php

namespace Tests\Feature\Identity;

use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/** Sucursales cargadas a mano (como en Atlas) que la API no podía alcanzar. */
class AdoptBranchesTest extends IdentityTestCase
{
    /** Igual que los documentos cargados en Atlas: todo correcto salvo el uuid. */
    private function handmade(string $uuid, string $code): void
    {
        DB::connection('mongodb')->table('branches')->insert([
            'uuid' => $uuid, 'code' => $code, 'name' => 'La Mesa '.$code, 'status' => 'ACTIVE',
            'timezone' => 'America/Mexico_City', 'currency' => 'MXN', 'business_day_cutoff' => '04:00',
            'operations_epoch' => 0, 'menu_version' => 0, 'version' => 1,
            'created_at' => new UTCDateTime, 'updated_at' => new UTCDateTime,
        ]);
    }

    public function test_da_uuid_real_y_conserva_el_id_y_los_datos(): void
    {
        $this->handmade('sucursal-001', 'LM-CENTRO');
        $this->handmade('sucursal-002', 'LM-NORTE');
        $before = Branch::query()->where('code', 'LM-CENTRO')->firstOrFail();

        $this->artisan('restia:adopt-branches')->assertSuccessful();

        $after = Branch::query()->where('code', 'LM-CENTRO')->firstOrFail();
        $this->assertTrue(Str::isUuid($after->uuid));
        $this->assertEquals($before->getKey(), $after->getKey());
        $this->assertSame('La Mesa LM-CENTRO', $after->name);
        $this->assertSame(2, $after->version);
        $this->assertNotSame($after->uuid, Branch::query()->where('code', 'LM-NORTE')->value('uuid'));
        $this->assertSame(2, DB::connection('mongodb')->table('audit_log')->where('action', 'branch.uuid_adopted')->count());

        // Una segunda vez no cambia nada.
        $this->artisan('restia:adopt-branches')->expectsOutputToContain('Nada que hacer')->assertSuccessful();
        $this->assertSame($after->uuid, Branch::query()->where('code', 'LM-CENTRO')->value('uuid'));
    }

    public function test_en_seco_no_escribe(): void
    {
        $this->handmade('sucursal-001', 'LM-CENTRO');

        $this->artisan('restia:adopt-branches', ['--dry-run' => true, '--admin-email' => 'a@ejemplo.mx'])->assertSuccessful();

        $this->assertSame('sucursal-001', Branch::query()->value('uuid'));
        $this->assertSame(0, User::query()->count());
    }

    public function test_un_uuid_en_mayusculas_solo_se_pasa_a_minusculas(): void
    {
        $uuid = (string) Str::uuid();
        $this->handmade(strtoupper($uuid), 'LM-CENTRO');

        $this->artisan('restia:adopt-branches')->assertSuccessful();

        $this->assertSame($uuid, Branch::query()->value('uuid'));
    }

    public function test_crea_el_primer_admin_en_todas_las_sucursales_y_puede_entrar(): void
    {
        $this->handmade('sucursal-001', 'LM-CENTRO');
        $this->handmade('sucursal-002', 'LM-NORTE');

        putenv('RESTIA_ADMIN_PASSWORD=contraseña-larga-123');
        try {
            $this->artisan('restia:adopt-branches', ['--admin-email' => 'Admin@Ejemplo.mx', '--admin-name' => 'Admin'])->assertSuccessful();
            // Con usuarios ya no se crea otro ADMIN por aquí.
            $this->artisan('restia:adopt-branches', ['--admin-email' => 'b@ejemplo.mx', '--admin-name' => 'B'])->assertFailed();
        } finally {
            putenv('RESTIA_ADMIN_PASSWORD');
        }

        $admin = User::query()->where('normalized_email', 'admin@ejemplo.mx')->firstOrFail();
        $this->assertSame(2, Membership::query()->where('user_id', new ObjectId($admin->getKey()))->where('role', 'ADMIN')->count());

        $branch = Branch::query()->where('code', 'LM-NORTE')->firstOrFail();
        $device = $this->enrolledDevice($branch, $admin);
        $token = $this->postJson('/api/v1/auth/mobile/login', ['email' => 'admin@ejemplo.mx', 'password' => 'contraseña-larga-123', 'deviceUuid' => $device['uuid'], 'deviceCredential' => $device['credential']])
            ->assertCreated()->json('data.accessToken');
        // La sucursal ya es alcanzable por su nuevo UUID.
        $this->withToken($token)->getJson("/api/v1/branches/{$branch->uuid}/tables")->assertOk();
    }

    public function test_sin_sucursales_manda_a_restia_install(): void
    {
        $this->artisan('restia:adopt-branches')->expectsOutputToContain('restia:install')->assertFailed();
    }
}
