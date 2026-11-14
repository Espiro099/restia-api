<?php

namespace Tests\Feature\Identity;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Floor\FloorTestCase;

/** Empleados y contraseñas (U-1 a U-5, A-10): alta, rol, baja, enlace, recuperación y cambio. */
class MembersTest extends FloorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
    }

    private function token(string $setupUrl): string
    {
        return Str::after($setupUrl, '#');
    }

    private function add(string $who, string $email, string $role): TestResponse
    {
        return $this->as($who, 'POST', 'members', ['email' => $email, 'name' => 'Empleado '.$email, 'role' => $role]);
    }

    private function memberOf(string $userUuid): array
    {
        return $this->as('admin', 'GET', "members/$userUuid")->assertOk()->json('data');
    }

    public function test_alta_con_enlace_y_primera_contrasena(): void
    {
        $this->add('mesero', 'nuevo@ejemplo.mx', 'WAITER')->assertStatus(403);
        $this->add('gerente', 'nuevo@ejemplo.mx', 'MANAGER')->assertStatus(403);
        $r = $this->add('gerente', 'Nuevo@Ejemplo.mx', 'WAITER')->assertCreated()->assertNoStore()
            ->assertJsonPath('data.member.role', 'WAITER')->assertJsonPath('data.member.active', true)
            ->assertJsonPath('data.member.user.status', 'INVITED')->json('data');
        $this->assertStringContainsString('/contrasena#', $r['setupUrl']);
        $this->add('gerente', 'nuevo@ejemplo.mx', 'KITCHEN')->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_ALREADY_EXISTS');

        // Sin contraseña no entra; con el enlace la define y ya puede.
        $user = User::query()->where('uuid', $r['member']['user']['id'])->first();
        $device = $this->enrolledDevice($this->branch);
        $this->login($user, $device, 'una-contraseña-segura-1')->assertStatus(401);
        $this->postJson('/api/v1/auth/password/reset', ['token' => $this->token($r['setupUrl']), 'newPassword' => 'corta'])->assertStatus(422);
        $this->postJson('/api/v1/auth/password/reset', ['token' => $this->token($r['setupUrl']), 'newPassword' => 'una-contraseña-segura-1'])->assertNoContent();
        $this->postJson('/api/v1/auth/password/reset', ['token' => $this->token($r['setupUrl']), 'newPassword' => 'otra-contraseña-segura-2'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PASSWORD_TOKEN_INVALID');
        $this->login($user, $device, 'una-contraseña-segura-1')->assertCreated();
        $this->assertSame('ACTIVE', $this->memberOf($user->uuid)['user']['status']);

        // Ni el enlace ni la contraseña llegan a la auditoría.
        $audit = json_encode(DB::connection('mongodb')->table('audit_log')->get());
        $this->assertStringNotContainsString($this->token($r['setupUrl']), $audit);
        $this->assertStringNotContainsString('una-contraseña-segura-1', $audit);

        // Un correo que ya existe recibe la membresía, pero no un enlace: no lo creó esta petición.
        $norte = Branch::query()->create(['code' => 'NORTE', 'name' => 'Norte', 'status' => Branch::ACTIVE, 'timezone' => 'America/Mexico_City', 'currency' => 'MXN']);
        $this->staff['admin-norte'] = $this->staffMember('admin@norte.mx', $norte, 'ADMIN');
        $this->as('admin-norte', 'POST', "/branches/{$norte->uuid}/members", ['email' => 'nuevo@ejemplo.mx', 'name' => 'Otro nombre', 'role' => 'KITCHEN'])
            ->assertCreated()->assertJsonMissingPath('data.setupUrl')->assertJsonPath('data.member.user.name', 'Empleado Nuevo@Ejemplo.mx');
    }

    public function test_roles_que_cada_quien_puede_cambiar(): void
    {
        $mesero = $this->staff['mesero']['user']->uuid;
        $m = $this->memberOf($mesero);
        $this->as('gerente', 'PATCH', "members/$mesero", ['role' => 'MANAGER'], ['If-Match' => '"'.$m['version'].'"'])->assertStatus(403);
        $m = $this->as('gerente', 'PATCH', "members/$mesero", ['role' => 'KITCHEN'], ['If-Match' => '"'.$m['version'].'"'])->assertOk()
            ->assertJsonPath('data.role', 'KITCHEN')->json('data');
        $this->as('gerente', 'PATCH', "members/$mesero", ['role' => 'WAITER'], ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        // Nadie se cambia a sí mismo.
        $gerente = $this->staff['gerente']['user']->uuid;
        $this->as('gerente', 'PATCH', "members/$gerente", ['role' => 'WAITER'], ['If-Match' => '"1"'])->assertStatus(403);
        // La sucursal conserva un ADMIN.
        $admin = $this->memberOf($this->staff['admin']['user']->uuid);
        $this->as('admin', 'PATCH', "members/{$admin['user']['id']}", ['role' => 'MANAGER'], ['If-Match' => '"1"'])->assertStatus(403);
        $this->staff['admin2'] = $this->staffMember('admin2@ejemplo.mx', $this->branch, 'ADMIN');
        $this->as('admin2', 'PATCH', "members/{$admin['user']['id']}", ['role' => 'MANAGER'], ['If-Match' => '"'.$admin['version'].'"'])->assertOk();
        $this->as('admin', 'PATCH', "members/{$this->staff['admin2']['user']->uuid}", ['role' => 'MANAGER'], ['If-Match' => '"1"'])->assertStatus(403);
        $this->as('mesero', 'GET', 'members')->assertStatus(403);
        $this->as('gerente', 'GET', 'members?role=ADMIN')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_revocar_corta_el_acceso_y_nunca_al_ultimo_admin(): void
    {
        $mesero = $this->staff['mesero']['user']->uuid;
        $this->as('mesero', 'GET', 'tables')->assertOk();
        $m = $this->memberOf($mesero);
        $this->as('gerente', 'POST', "members/$mesero/revoke", ['reason' => 'Dejó de trabajar'], ['If-Match' => '"'.$m['version'].'"'])->assertOk()
            ->assertJsonPath('data.active', false);
        $this->as('mesero', 'GET', 'tables')->assertStatus(403);
        $this->as('gerente', 'GET', 'members?active=false')->assertOk()->assertJsonCount(1, 'data');
        // Volver a darlo de alta reactiva la misma membresía.
        $this->add('gerente', 'mesero@ejemplo.mx', 'WAITER')->assertCreated()->assertJsonMissingPath('data.setupUrl');
        $this->as('mesero', 'GET', 'tables')->assertOk();

        $this->staff['admin2'] = $this->staffMember('admin2@ejemplo.mx', $this->branch, 'ADMIN');
        $admin2 = $this->memberOf($this->staff['admin2']['user']->uuid);
        $this->as('admin', 'POST', "members/{$admin2['user']['id']}/revoke", ['reason' => 'Cambio'], ['If-Match' => '"'.$admin2['version'].'"'])->assertOk();
        // Ahora es el único: nadie lo puede revocar, ni él mismo.
        $admin = $this->memberOf($this->staff['admin']['user']->uuid);
        $this->as('admin', 'POST', "members/{$admin['user']['id']}/revoke", ['reason' => 'Cambio'], ['If-Match' => '"'.$admin['version'].'"'])->assertStatus(403);
    }

    public function test_desactivar_reactivar_y_revocar_sesiones(): void
    {
        $mesero = $this->staff['mesero']['user'];
        $path = "/users/{$mesero->uuid}";
        $this->as('gerente', 'POST', "$path/deactivate", ['reason' => 'Baja'], ['If-Match' => '"1"'])->assertStatus(403);
        $this->as('admin', 'POST', "$path/deactivate", ['reason' => 'Baja'], ['If-Match' => '"9"'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->as('admin', 'POST', "$path/deactivate", ['reason' => 'Baja'], ['If-Match' => '"'.$mesero->version.'"'])->assertOk()
            ->assertJsonPath('data.status', 'INACTIVE');
        $this->as('mesero', 'GET', 'tables')->assertStatus(401);
        $this->as('admin', 'POST', "$path/password-setup-link")->assertStatus(409)->assertJsonPath('error.code', 'USER_INACTIVE');
        $this->as('admin', 'POST', "$path/activate", [], ['If-Match' => '"'.$mesero->fresh()->version.'"'])->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        // Nunca el propio, y nunca dejar una sucursal sin ADMIN.
        $admin = $this->staff['admin']['user'];
        $this->as('admin', 'POST', "/users/{$admin->uuid}/deactivate", ['reason' => 'x x x'], ['If-Match' => '"'.$admin->version.'"'])->assertStatus(403);
        $this->staff['admin2'] = $this->staffMember('admin2@ejemplo.mx', $this->branch, 'ADMIN');
        $this->as('admin2', 'POST', "/users/{$admin->uuid}/deactivate", ['reason' => 'Baja'], ['If-Match' => '"'.$admin->fresh()->version.'"'])->assertOk();
        $admin2 = $this->staff['admin2']['user'];
        $this->staff['admin3'] = $this->staffMember('admin3@ejemplo.mx', $this->branch, 'ADMIN');
        User::query()->where('_id', $this->staff['admin3']['user']->getKey())->update(['status' => User::INACTIVE]);
        $this->as('admin2', 'POST', "/users/{$this->staff['admin3']['user']->uuid}/activate", [], ['If-Match' => '"1"'])->assertOk();
        $this->as('admin3', 'POST', "/users/{$admin2->uuid}/deactivate", ['reason' => 'Baja'], ['If-Match' => '"'.$admin2->fresh()->version.'"'])->assertOk();
        $this->assertSame(User::ACTIVE, $this->staff['admin3']['user']->fresh()->status);

        // Un usuario de otra sucursal no existe para este ADMIN.
        $this->as('admin3', 'POST', '/users/'.Str::uuid().'/sessions/revoke', ['reason' => 'Prueba'])->assertStatus(404);
        $this->as('admin3', 'POST', "/users/{$mesero->uuid}/sessions/revoke", ['reason' => 'Teléfono perdido'])->assertNoContent();
        $this->as('mesero', 'GET', 'tables')->assertStatus(401);
    }

    public function test_recuperar_y_cambiar_la_contrasena(): void
    {
        // Exista o no el correo, la respuesta es la misma; solo al empleado le llega el enlace.
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nadie@ejemplo.mx'])->assertNoContent();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'cocina@ejemplo.mx'])->assertNoContent();
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('cocina@ejemplo.mx', $sent[0]->getEnvelope()->getRecipients()[0]->getAddress());
        preg_match('~/contrasena#([A-Za-z0-9_-]+)~', $sent[0]->getOriginalMessage()->getTextBody(), $m);
        $this->postJson('/api/v1/auth/password/reset', ['token' => $m[1], 'newPassword' => 'recuperada-segura-123'])->assertNoContent();
        // Recuperar revoca las sesiones que tenía.
        $this->as('cocina', 'GET', 'tables')->assertStatus(401);
        $this->login($this->staff['cocina']['user'], $this->enrolledDevice($this->branch), 'recuperada-segura-123')->assertCreated();

        // Cambio propio: exige la actual, conserva la sesión en curso y revoca las demás.
        $mesero = $this->staff['mesero']['user'];
        $otra = $this->tokens($mesero, $this->enrolledDevice($this->branch))['access'];
        $this->as('mesero', 'POST', '/auth/password/change', ['currentPassword' => 'incorrecta', 'newPassword' => 'nueva-contraseña-segura'])->assertStatus(422);
        $this->as('mesero', 'POST', '/auth/password/change', ['currentPassword' => self::PASSWORD, 'newPassword' => 'corta'])->assertStatus(422);
        $this->as('mesero', 'POST', '/auth/password/change', ['currentPassword' => self::PASSWORD, 'newPassword' => 'nueva-contraseña-segura'])->assertNoContent();
        $this->as('mesero', 'GET', 'tables')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->json('GET', '/api/v1/branches/'.$this->branch->uuid.'/tables', [], ['Authorization' => 'Bearer '.$otra])->assertStatus(401);
        $this->login($mesero, $this->enrolledDevice($this->branch), 'nueva-contraseña-segura')->assertCreated();
    }
}
