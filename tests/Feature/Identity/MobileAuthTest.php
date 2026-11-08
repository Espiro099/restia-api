<?php

namespace Tests\Feature\Identity;

use App\Identity\MobileAuth;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\Membership;
use App\Models\PersonalAccessToken;
use App\Support\RefreshTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Casos CRED de D-36 (sección 9) para Android. Los de Web (18 a 20) llegan con el login Web. */
class MobileAuthTest extends IdentityTestCase
{
    public function test_alta_devuelve_la_credencial_una_vez_y_guarda_solo_su_hash(): void
    {
        $branch = $this->branch();
        $device = $this->enrolledDevice($branch);

        $stored = Device::query()->where('uuid', $device['id'])->firstOrFail();
        $this->assertSame(hash('sha256', $device['credential']), $stored->credential_hash);
        $this->assertSame(Device::ACTIVE, $stored->status);
        $this->assertSame(1, $this->auditCount('device.enrolled'));
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'device.enrolled')->count());
    }

    public function test_respuesta_de_alta_cumple_el_contrato(): void
    {
        $branch = $this->branch();
        $manager = $this->user('gerente@ejemplo.mx', $branch, 'MANAGER');
        $code = MobileAuth::createEnrollmentCode($branch, $manager)['code'];

        $r = $this->postJson('/api/v1/auth/mobile/enroll', ['code' => strtolower(substr($code, 0, 6).'-'.substr($code, 6)), 'deviceUuid' => (string) Str::uuid(), 'displayName' => 'Tablet'])
            ->assertCreated()
            ->assertNoStore()
            ->assertJsonStructure(['data' => ['device' => ['id', 'branchId', 'type', 'status', 'displayName', 'enrolledAt', 'lastSeenAt', 'version'], 'deviceCredential'], 'meta' => ['requestId']]);
        $this->assertSame($branch->uuid, $r->json('data.device.branchId'));
        $this->assertArrayNotHasKey('credential_hash', $r->json('data.device'));
    }

    public function test_cred04_codigo_usado_dos_veces(): void
    {
        $branch = $this->branch();
        $code = MobileAuth::createEnrollmentCode($branch, $this->user('g@ejemplo.mx', $branch, 'MANAGER'))['code'];
        $body = fn () => ['code' => $code, 'deviceUuid' => (string) Str::uuid(), 'displayName' => 'Tablet'];

        $this->postJson('/api/v1/auth/mobile/enroll', $body())->assertCreated();
        $this->postJson('/api/v1/auth/mobile/enroll', $body())->assertStatus(401)->assertJsonPath('error.code', 'ENROLLMENT_CODE_INVALID');
        $this->assertSame(1, Device::query()->count());
    }

    public function test_codigo_vencido_o_inexistente(): void
    {
        $branch = $this->branch();
        $code = MobileAuth::createEnrollmentCode($branch, $this->user('g@ejemplo.mx', $branch, 'MANAGER'))['code'];
        $this->travel(16)->minutes();

        $this->postJson('/api/v1/auth/mobile/enroll', ['code' => $code, 'deviceUuid' => (string) Str::uuid(), 'displayName' => 'T'])
            ->assertStatus(401)->assertJsonPath('error.code', 'ENROLLMENT_CODE_INVALID');
        $this->postJson('/api/v1/auth/mobile/enroll', ['code' => 'NOEXISTE1234', 'deviceUuid' => (string) Str::uuid(), 'displayName' => 'T'])
            ->assertStatus(401);
    }

    public function test_device_uuid_repetido_no_gasta_el_codigo(): void
    {
        $branch = $this->branch();
        $device = $this->enrolledDevice($branch);
        $code = MobileAuth::createEnrollmentCode($branch, $this->user('g@ejemplo.mx', $branch, 'MANAGER'))['code'];

        $this->postJson('/api/v1/auth/mobile/enroll', ['code' => $code, 'deviceUuid' => $device['uuid'], 'displayName' => 'T'])
            ->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_ALREADY_ENROLLED');
        $this->postJson('/api/v1/auth/mobile/enroll', ['code' => $code, 'deviceUuid' => (string) Str::uuid(), 'displayName' => 'T'])
            ->assertCreated();
    }

    public function test_campos_extra_y_tipos_invalidos_dan_422(): void
    {
        $this->postJson('/api/v1/auth/mobile/enroll', ['code' => 'ABCDEFGH', 'deviceUuid' => (string) Str::uuid(), 'displayName' => 'T', 'branchId' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.fields.branchId.0', 'Campo no permitido.');
        $this->postJson('/api/v1/auth/mobile/enroll', ['code' => 'x', 'deviceUuid' => 'no'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.code.0', 'Debe tener al menos 8 caracteres.')
            ->assertJsonPath('error.details.fields.deviceUuid.0', 'Debe ser un UUID válido.')
            ->assertJsonPath('error.details.fields.displayName.0', 'Es obligatorio.');
        $this->postJson('/api/v1/auth/mobile/login', ['email' => ['$ne' => 'x'], 'password' => 'x', 'deviceUuid' => (string) Str::uuid(), 'deviceCredential' => str_repeat('a', 40)])
            ->assertStatus(422);
    }

    public function test_cred01_login_crea_sesion_y_audita(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);

        $r = $this->login($user, $device)->assertCreated()->assertNoStore()
            ->assertJsonStructure(['data' => ['session' => ['id', 'absoluteExpiresAt'], 'accessToken', 'accessTokenExpiresIn', 'refreshToken', 'refreshTokenExpiresAt'], 'meta' => ['requestId']]);
        $this->assertSame(900, $r->json('data.accessTokenExpiresIn'));
        $this->assertSame([AuthSession::ACTIVE, null], $this->sessionStatus($r->json('data.session.id')));
        $this->assertSame(1, $this->auditCount('auth.login'));
        $this->bearer($r->json('data.accessToken'), 'GET', '/api/v1/auth/me')->assertOk();
    }

    public function test_correo_sin_importar_mayusculas_ni_espacios(): void
    {
        $branch = $this->branch();
        $user = $this->user('Mesero@Ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);

        $this->postJson('/api/v1/auth/mobile/login', ['email' => 'MESERO@ejemplo.MX', 'password' => self::PASSWORD, 'deviceUuid' => $device['uuid'], 'deviceCredential' => $device['credential']])
            ->assertCreated();
    }

    public function test_cred02_sin_membresia_en_la_sucursal_del_dispositivo(): void
    {
        $centro = $this->branch('CENTRO');
        $norte = $this->branch('NORTE');
        $user = $this->user('mesero@ejemplo.mx', $norte);
        $device = $this->enrolledDevice($centro);

        $this->login($user, $device)->assertStatus(403)->assertJsonPath('error.code', 'BRANCH_ACCESS_DENIED');
        $this->assertSame(0, AuthSession::query()->count());
    }

    public function test_cred03_credencial_de_dispositivo_incorrecta(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);
        $device['credential'] = str_repeat('x', 64);

        $this->login($user, $device)->assertStatus(401)->assertJsonPath('error.code', 'DEVICE_NOT_ENROLLED');
    }

    public function test_contrasena_mala_y_correo_inexistente_responden_igual(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);

        $bad = $this->login($user, $device, 'otra-contraseña')->assertStatus(401);
        $user->email = 'nadie@ejemplo.mx';
        $missing = $this->login($user, $device)->assertStatus(401);
        $this->assertSame($bad->json('error.code'), $missing->json('error.code'));
        $this->assertSame($bad->json('error.message'), $missing->json('error.message'));
        $this->assertSame('AUTHENTICATION_INVALID', $bad->json('error.code'));
    }

    public function test_usuario_inactivo_no_entra(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch, status: 'INACTIVE');
        $this->login($user, $this->enrolledDevice($branch))->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_INVALID');
    }

    public function test_cred05_access_token_vencido_da_401_y_renovar_lo_arregla(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);
        $t = $this->tokens($user, $device);

        $this->travel(16)->minutes();
        $this->bearer($t['access'], 'GET', '/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $new = $this->refreshWith($device, $t['refresh'])->assertOk()->assertNoStore();
        $this->bearer($new->json('data.accessToken'), 'GET', '/api/v1/auth/me')->assertOk();
        $this->assertNotSame($t['refresh'], $new->json('data.refreshToken'));
    }

    public function test_cred06_y_07_reintento_con_el_refresh_anterior_dentro_de_la_gracia(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);
        $t = $this->tokens($user, $device);

        $first = $this->refreshWith($device, $t['refresh'])->assertOk();
        $this->travel(30)->seconds();
        // La respuesta anterior se perdió: el cliente repite con el mismo refresh.
        $second = $this->refreshWith($device, $t['refresh'])->assertOk();
        $this->assertSame([AuthSession::ACTIVE, null], $this->sessionStatus($t['session']));

        // Solo el último queda vigente: el de la respuesta perdida ya fue reemplazado.
        $this->refreshWith($device, $second->json('data.refreshToken'))->assertOk();
        $this->assertNotSame($first->json('data.refreshToken'), $second->json('data.refreshToken'));
    }

    public function test_cred08_refresh_anterior_despues_de_la_gracia_revoca(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);
        $t = $this->tokens($user, $device);

        $new = $this->refreshWith($device, $t['refresh'])->assertOk();
        $this->travel(61)->seconds();
        $this->refreshWith($device, $t['refresh'])->assertStatus(401)->assertJsonPath('error.code', 'SESSION_REVOKED');

        $this->assertSame([AuthSession::REVOKED, 'REFRESH_REUSE'], $this->sessionStatus($t['session']));
        $this->refreshWith($device, $new->json('data.refreshToken'))->assertStatus(401);
        $this->bearer($new->json('data.accessToken'), 'GET', '/api/v1/auth/me')->assertStatus(401);
        $this->assertSame(1, $this->auditCount('auth_session.revoked'));
    }

    public function test_cred09_atacante_renueva_y_luego_el_cliente(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);
        $t = $this->tokens($user, $device);

        $stolen = $this->refreshWith($device, $t['refresh'])->assertOk();
        // El atacante sigue renovando; el cliente legítimo se queda con el original.
        $this->refreshWith($device, $stolen->json('data.refreshToken'))->assertOk();
        $this->travel(61)->seconds();
        $this->refreshWith($device, $t['refresh'])->assertStatus(401);
        $this->assertSame([AuthSession::REVOKED, 'REFRESH_REUSE'], $this->sessionStatus($t['session']));
    }

    public function test_cred25_firma_invalida_no_toca_la_sesion_ajena(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);
        $t = $this->tokens($user, $device);

        $forged = $t['session'].'.'.bin2hex(random_bytes(32)).'.'.str_repeat('0', 64);
        $this->refreshWith($device, $forged)->assertStatus(401)->assertJsonPath('error.code', 'SESSION_REVOKED');
        $this->assertSame([AuthSession::ACTIVE, null], $this->sessionStatus($t['session']));
        $this->assertNull(RefreshTokens::verify($forged));
    }

    public function test_refresh_desde_otro_dispositivo_no_renueva(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $a = $this->enrolledDevice($branch);
        $b = $this->enrolledDevice($branch);
        $t = $this->tokens($user, $a);

        $this->refreshWith($b, $t['refresh'])->assertStatus(401);
    }

    public function test_sesion_maxima_de_30_dias(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);
        $refresh = $this->tokens($user, $device)['refresh'];

        // Renovar cada 6 días mantiene viva la sesión, pero no más allá de 30.
        for ($day = 6; $day <= 30; $day += 6) {
            $this->travel(6)->days();
            $r = $this->refreshWith($device, $refresh);
            if ($day < 30) {
                $r->assertOk();
                $refresh = $r->json('data.refreshToken');
            } else {
                $r->assertStatus(401);
            }
        }
    }

    public function test_refresh_vence_tras_7_dias_sin_uso(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);
        $t = $this->tokens($user, $device);

        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->refreshWith($device, $t['refresh'])->assertStatus(401);
        // Vencida no es reúso: no se marca como revocada.
        $this->assertSame([AuthSession::ACTIVE, null], $this->sessionStatus($t['session']));
    }

    public function test_cred10_login_en_dispositivo_ocupado_reemplaza_la_sesion(): void
    {
        $branch = $this->branch();
        $a = $this->user('a@ejemplo.mx', $branch);
        $b = $this->user('b@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);

        $first = $this->tokens($a, $device);
        $second = $this->tokens($b, $device);

        $this->assertSame([AuthSession::REVOKED, 'REPLACED_BY_LOGIN'], $this->sessionStatus($first['session']));
        $this->assertSame(1, AuthSession::query()->where('status', 'ACTIVE')->count());
        $this->bearer($first['access'], 'GET', '/api/v1/auth/me')->assertStatus(401);
        $this->bearer($second['access'], 'GET', '/api/v1/auth/me')->assertOk();
    }

    public function test_cred11_tercera_sesion_android_cierra_la_mas_antigua(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $sessions = [];
        foreach (range(1, 3) as $i) {
            $sessions[] = $this->tokens($user, $this->enrolledDevice($branch))['session'];
            $this->travel(1)->seconds();
        }

        $this->assertSame([AuthSession::REVOKED, 'SESSION_LIMIT'], $this->sessionStatus($sessions[0]));
        $this->assertSame(AuthSession::ACTIVE, $this->sessionStatus($sessions[1])[0]);
        $this->assertSame(AuthSession::ACTIVE, $this->sessionStatus($sessions[2])[0]);
        $this->assertSame(1, DB::connection('mongodb')->table('audit_log')->where('reason', 'SESSION_LIMIT')->count());
    }

    public function test_cred14_baja_de_membresia_con_token_vigente(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch, 'MANAGER');
        $t = $this->tokens($user, $this->enrolledDevice($branch));
        $this->bearer($t['access'], 'GET', "/api/v1/branches/{$branch->uuid}/devices")->assertOk();

        Membership::query()->update(['status' => Membership::REVOKED]);
        $this->bearer($t['access'], 'GET', "/api/v1/branches/{$branch->uuid}/devices")
            ->assertStatus(403)->assertJsonPath('error.code', 'BRANCH_ACCESS_DENIED');
    }

    public function test_cred15_ruta_de_otra_sucursal_desde_el_dispositivo(): void
    {
        $centro = $this->branch('CENTRO');
        $norte = $this->branch('NORTE');
        $user = $this->user('gerente@ejemplo.mx', $centro, 'MANAGER');
        $this->member($user, $norte, 'MANAGER');
        $t = $this->tokens($user, $this->enrolledDevice($centro));

        $this->bearer($t['access'], 'GET', "/api/v1/branches/{$norte->uuid}/devices")
            ->assertStatus(403)->assertJsonPath('error.code', 'DEVICE_BRANCH_MISMATCH');
    }

    public function test_cred21_sexto_intento_de_login_en_un_minuto(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $device = $this->enrolledDevice($branch);

        foreach (range(1, 5) as $i) {
            $this->login($user, $device, 'mala')->assertStatus(401);
        }
        $r = $this->login($user, $device)->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
        $this->assertGreaterThan(0, (int) $r->headers->get('Retry-After'));
        $this->assertSame(1, $this->auditCount('auth.login_locked'));

        $this->travel(61)->seconds();
        $this->login($user, $device)->assertCreated();
    }

    public function test_cred24_auditoria_y_logs_sin_secretos(): void
    {
        $logged = [];
        Log::listen(function ($e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch, 'MANAGER');
        $device = $this->enrolledDevice($branch);
        $this->login($user, $device, 'mala');
        $t = $this->tokens($user, $device);
        $new = $this->refreshWith($device, $t['refresh'])->json('data');
        $this->travel(61)->seconds();
        $this->refreshWith($device, $t['refresh']);
        $this->bearer($new['accessToken'], 'POST', '/api/v1/auth/logout');

        $audit = json_encode(DB::connection('mongodb')->table('audit_log')->get()).implode("\n", $logged);
        $secrets = [self::PASSWORD, 'mala', $device['credential'], hash('sha256', $device['credential']), $t['access'], $t['refresh'], $new['refreshToken'], RefreshTokens::hash($t['refresh'])];
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }
        $this->assertNotEmpty($logged);
    }

    public function test_tokens_guardados_solo_como_hash(): void
    {
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $t = $this->tokens($user, $this->enrolledDevice($branch));

        [$id, $plain] = explode('|', $t['access'], 2);
        $this->assertSame(hash('sha256', $plain), PersonalAccessToken::query()->find($id)->token);
        $session = AuthSession::query()->where('uuid', $t['session'])->first();
        $this->assertSame(RefreshTokens::hash($t['refresh']), $session->refresh_hash);
    }
}
