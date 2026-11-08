<?php

namespace Tests\Feature\Identity;

use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** /auth/me, sesiones propias, logout y gestión de dispositivos, con la matriz de roles. */
class AccountAndDevicesTest extends IdentityTestCase
{
    public function test_me_devuelve_usuario_membresias_activas_y_permisos_del_rol(): void
    {
        $centro = $this->branch('CENTRO');
        $norte = $this->branch('NORTE');
        $cerrada = $this->branch('CERRADA', 'INACTIVE');
        $user = $this->user('mesero@ejemplo.mx', $centro, 'WAITER');
        $this->member($user, $norte, 'KITCHEN');
        $this->member($user, $cerrada, 'ADMIN');
        $t = $this->tokens($user, $this->enrolledDevice($centro));

        $r = $this->bearer($t['access'], 'GET', '/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.user.email', 'mesero@ejemplo.mx')
            ->assertJsonPath('data.session.id', $t['session'])
            ->assertJsonPath('data.session.current', true)
            ->assertJsonPath('data.session.clientType', 'ANDROID');
        $memberships = collect($r->json('data.memberships'))->keyBy('branch.id');
        // La sucursal inactiva no aparece.
        $this->assertEqualsCanonicalizing([$centro->uuid, $norte->uuid], $memberships->keys()->all());
        $this->assertContains('order_units.deliver', $memberships[$centro->uuid]['permissions']);
        $this->assertContains('order_units.cancel', $memberships[$centro->uuid]['permissions']);
        $this->assertNotContains('devices.enroll', $memberships[$centro->uuid]['permissions']);
        $this->assertNotContains('order_units.deliver', $memberships[$norte->uuid]['permissions']);
        $this->assertArrayNotHasKey('password_hash', $r->json('data.user'));
    }

    public function test_sin_token_o_con_token_invalido_da_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->bearer('123|noexiste', 'GET', '/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_revoca_y_es_idempotente_en_efecto(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);
        $t = $this->tokens($user, $device);

        $this->bearer($t['access'], 'POST', '/api/v1/auth/logout')->assertNoContent();
        $this->assertSame([AuthSession::REVOKED, 'LOGOUT'], $this->sessionStatus($t['session']));
        $this->bearer($t['access'], 'POST', '/api/v1/auth/logout')->assertStatus(401);
        $this->refreshWith($device, $t['refresh'])->assertStatus(401);
        $this->assertSame(1, $this->auditCount('auth.logout'));
    }

    public function test_lista_y_cierra_sesiones_propias(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $old = $this->tokens($user, $this->enrolledDevice($branch));
        $this->travel(1)->seconds();
        $current = $this->tokens($user, $this->enrolledDevice($branch));

        $r = $this->bearer($current['access'], 'GET', '/api/v1/auth/sessions')->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.id', $current['session'])
            ->assertJsonPath('data.0.current', true)
            ->assertJsonPath('data.1.current', false);
        $this->assertNotNull($r->json('data.1.deviceId'));

        $this->bearer($current['access'], 'POST', "/api/v1/auth/sessions/{$old['session']}/revoke")->assertNoContent();
        $this->bearer($current['access'], 'POST', "/api/v1/auth/sessions/{$old['session']}/revoke")->assertNoContent();
        $this->bearer($old['access'], 'GET', '/api/v1/auth/me')->assertStatus(401);
        $this->bearer($current['access'], 'GET', '/api/v1/auth/sessions?status=REVOKED')->assertJsonPath('meta.pagination.total', 1);
        $this->bearer($current['access'], 'GET', '/api/v1/auth/sessions?status=ACTIVE')->assertJsonPath('meta.pagination.total', 1);
        $this->bearer($current['access'], 'GET', '/api/v1/auth/sessions?status=OTRO')->assertStatus(422);
        $this->bearer($current['access'], 'GET', '/api/v1/auth/sessions?perPage=500')->assertStatus(422);
    }

    public function test_sesion_ajena_responde_404(): void
    {
        $branch = $this->branch();
        $a = $this->tokens($this->user('a@ejemplo.mx', $branch), $this->enrolledDevice($branch));
        $b = $this->tokens($this->user('b@ejemplo.mx', $branch), $this->enrolledDevice($branch));

        $this->bearer($a['access'], 'POST', "/api/v1/auth/sessions/{$b['session']}/revoke")->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->bearer($a['access'], 'POST', '/api/v1/auth/sessions/no-es-uuid/revoke')->assertStatus(404);
        $this->assertSame(AuthSession::ACTIVE, $this->sessionStatus($b['session'])[0]);
    }

    public function test_codigo_de_alta_solo_gerente_y_admin(): void
    {
        $branch = $this->branch();
        foreach (['ADMIN' => 201, 'MANAGER' => 201, 'WAITER' => 403, 'KITCHEN' => 403] as $role => $status) {
            $t = $this->tokens($this->user(strtolower($role).'@ejemplo.mx', $branch, $role), $this->enrolledDevice($branch));
            $r = $this->bearer($t['access'], 'POST', "/api/v1/branches/{$branch->uuid}/devices/enrollment-codes")->assertStatus($status);
            if ($status === 201) {
                $r->assertNoStore()->assertJsonStructure(['data' => ['code', 'expiresAt'], 'meta' => ['requestId']]);
                $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{12}$/', $r->json('data.code'));
            } else {
                $r->assertJsonPath('error.code', 'FORBIDDEN');
            }
        }
    }

    public function test_sucursal_inexistente_404_e_inactiva_409(): void
    {
        $branch = $this->branch();
        $t = $this->tokens($this->user('g@ejemplo.mx', $branch, 'MANAGER'), $this->enrolledDevice($branch));
        $this->bearer($t['access'], 'GET', '/api/v1/branches/'.Str::uuid().'/devices')->assertStatus(404);
        $this->bearer($t['access'], 'GET', '/api/v1/branches/no-es-uuid/devices')->assertStatus(404);

        $branch->update(['status' => 'INACTIVE']);
        $this->bearer($t['access'], 'POST', "/api/v1/branches/{$branch->uuid}/devices/enrollment-codes")
            ->assertStatus(409)->assertJsonPath('error.code', 'BRANCH_INACTIVE');
    }

    public function test_lista_dispositivos_de_la_sucursal_con_filtro_y_paginas(): void
    {
        $branch = $this->branch();
        $other = $this->branch('NORTE');
        $manager = $this->user('g@ejemplo.mx', $branch, 'MANAGER');
        $mine = $this->enrolledDevice($branch, $manager);
        $this->enrolledDevice($branch, $manager);
        $this->enrolledDevice($other);
        $t = $this->tokens($manager, $mine);

        $this->bearer($t['access'], 'GET', "/api/v1/branches/{$branch->uuid}/devices?perPage=1")->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.lastPage', 2)
            ->assertJsonCount(1, 'data');
        $this->bearer($t['access'], 'GET', "/api/v1/branches/{$branch->uuid}/devices?status=BLOCKED")->assertJsonPath('meta.pagination.total', 0);
        $r = $this->bearer($t['access'], 'GET', "/api/v1/branches/{$branch->uuid}/devices")->json('data.0');
        $this->assertSame($branch->uuid, $r['branchId']);
        $this->assertArrayNotHasKey('credential_hash', $r);
        $this->assertArrayNotHasKey('device_uuid', $r);
    }

    public function test_cred12_y_13_bloqueo_revoca_y_es_idempotente(): void
    {
        $branch = $this->branch();
        $manager = $this->user('g@ejemplo.mx', $branch, 'MANAGER');
        $waiter = $this->user('m@ejemplo.mx', $branch);
        $lost = $this->enrolledDevice($branch, $manager);
        $managerDevice = $this->enrolledDevice($branch, $manager);
        $victim = $this->tokens($waiter, $lost);
        $t = $this->tokens($manager, $managerDevice);

        $uri = "/api/v1/branches/{$branch->uuid}/devices/{$lost['id']}/block";
        $this->bearer($t['access'], 'POST', $uri, ['reason' => 'Dispositivo extraviado'])->assertNoContent();
        $this->bearer($t['access'], 'POST', $uri, ['reason' => 'Dispositivo extraviado'])->assertNoContent();

        $this->bearer($victim['access'], 'GET', '/api/v1/auth/me')->assertStatus(401);
        $this->refreshWith($lost, $victim['refresh'])->assertStatus(403)->assertJsonPath('error.code', 'DEVICE_BLOCKED');
        $this->login($waiter, $lost)->assertStatus(403)->assertJsonPath('error.code', 'DEVICE_BLOCKED');
        $this->assertSame([AuthSession::REVOKED, 'DEVICE_BLOCKED'], $this->sessionStatus($victim['session']));
        $this->assertSame(1, $this->auditCount('device.blocked'));
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'device.blocked')->count());

        // BLOCKED es final: retirarlo no cambia su estado.
        $this->bearer($t['access'], 'POST', "/api/v1/branches/{$branch->uuid}/devices/{$lost['id']}/retire", ['reason' => 'Baja'])->assertNoContent();
        $this->assertSame(Device::BLOCKED, Device::query()->where('uuid', $lost['id'])->value('status'));
        $this->assertSame(0, $this->auditCount('device.retired'));
    }

    public function test_retiro_y_validaciones_del_motivo(): void
    {
        $branch = $this->branch();
        $manager = $this->user('g@ejemplo.mx', $branch, 'MANAGER');
        $old = $this->enrolledDevice($branch, $manager);
        $t = $this->tokens($manager, $this->enrolledDevice($branch, $manager));
        $uri = "/api/v1/branches/{$branch->uuid}/devices/{$old['id']}/retire";

        $this->bearer($t['access'], 'POST', $uri, ['reason' => 'x'])->assertStatus(422);
        $this->bearer($t['access'], 'POST', $uri, [])->assertStatus(422);
        $this->bearer($t['access'], 'POST', $uri, ['reason' => 'Se reemplazó', 'extra' => 1])->assertStatus(422);
        $this->bearer($t['access'], 'POST', $uri, ['reason' => 'Se reemplazó'])->assertNoContent();
        $this->assertSame(Device::RETIRED, Device::query()->where('uuid', $old['id'])->value('status'));
    }

    public function test_dispositivo_de_otra_sucursal_responde_404(): void
    {
        $centro = $this->branch('CENTRO');
        $norte = $this->branch('NORTE');
        $manager = $this->user('g@ejemplo.mx', $centro, 'MANAGER');
        $foreign = $this->enrolledDevice($norte);
        $t = $this->tokens($manager, $this->enrolledDevice($centro, $manager));

        $this->bearer($t['access'], 'POST', "/api/v1/branches/{$centro->uuid}/devices/{$foreign['id']}/block", ['reason' => 'Prueba'])
            ->assertStatus(404);
        $this->assertSame(Device::ACTIVE, Device::query()->where('uuid', $foreign['id'])->value('status'));
    }

    public function test_mesero_no_bloquea_ni_lista(): void
    {
        $branch = $this->branch();
        $waiter = $this->user('m@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);
        $t = $this->tokens($waiter, $device);

        $this->bearer($t['access'], 'GET', "/api/v1/branches/{$branch->uuid}/devices")->assertStatus(403);
        $this->bearer($t['access'], 'POST', "/api/v1/branches/{$branch->uuid}/devices/{$device['id']}/block", ['reason' => 'Prueba'])->assertStatus(403);
    }

    public function test_instalacion_crea_sucursal_admin_y_no_se_repite(): void
    {
        putenv('RESTIA_ADMIN_PASSWORD=contraseña-larga-123');
        try {
            $this->artisan('restia:install', ['--branch-code' => 'CENTRO', '--branch-name' => 'Centro', '--email' => 'Admin@Ejemplo.mx', '--name' => 'Admin'])
                ->assertSuccessful();
            $this->artisan('restia:install', ['--branch-code' => 'OTRA', '--branch-name' => 'Otra', '--email' => 'b@ejemplo.mx', '--name' => 'B'])
                ->assertFailed();
        } finally {
            putenv('RESTIA_ADMIN_PASSWORD');
        }

        $branch = Branch::query()->firstOrFail();
        $admin = User::query()->where('normalized_email', 'admin@ejemplo.mx')->firstOrFail();
        $device = $this->enrolledDevice($branch, $admin);
        $this->postJson('/api/v1/auth/mobile/login', ['email' => 'admin@ejemplo.mx', 'password' => 'contraseña-larga-123', 'deviceUuid' => $device['uuid'], 'deviceCredential' => $device['credential']])
            ->assertCreated();
    }
}
