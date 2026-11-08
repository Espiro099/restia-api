<?php

namespace Tests\Feature\Identity;

use App\Identity\MobileAuth;
use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/** Base de datos vacía con índices, y ayudas para recorrer el flujo Android de D-36. */
abstract class IdentityTestCase extends TestCase
{
    protected const PASSWORD = 'contraseña-de-prueba-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshDatabase();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        // El contrato exige exactamente `no-store` (header NoStore).
        TestResponse::macro('assertNoStore', function () {
            /** @var TestResponse $this */
            Assert::assertSame('no-store', $this->headers->get('Cache-Control'));

            return $this;
        });
    }

    protected function branch(string $code = 'CENTRO', string $status = Branch::ACTIVE): Branch
    {
        return Branch::query()->create(['code' => $code, 'name' => 'Sucursal '.$code, 'status' => $status, 'timezone' => 'America/Mexico_City', 'currency' => 'MXN']);
    }

    protected function user(string $email, ?Branch $branch = null, string $role = 'WAITER', string $status = User::ACTIVE): User
    {
        $user = User::query()->create([
            'email' => $email,
            'normalized_email' => User::normalizeEmail($email),
            'name' => 'Empleado '.$email,
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => $status,
        ]);
        if ($branch) {
            $this->member($user, $branch, $role);
        }

        return $user;
    }

    protected function member(User $user, Branch $branch, string $role): Membership
    {
        return Membership::query()->create(['user_id' => $user->getKey(), 'branch_id' => $branch->getKey(), 'role' => $role, 'status' => Membership::ACTIVE]);
    }

    /** Un dispositivo registrado como lo haría la app. @return array{uuid: string, credential: string, id: string} */
    protected function enrolledDevice(Branch $branch, ?User $manager = null): array
    {
        $manager ??= $this->user('gerente'.Str::random(6).'@ejemplo.mx', $branch, 'MANAGER');
        $code = MobileAuth::createEnrollmentCode($branch, $manager)['code'];
        $uuid = (string) Str::uuid();
        $r = $this->postJson('/api/v1/auth/mobile/enroll', ['code' => $code, 'deviceUuid' => $uuid, 'displayName' => 'Tablet 1'])->assertCreated();

        return ['uuid' => $uuid, 'credential' => $r->json('data.deviceCredential'), 'id' => $r->json('data.device.id')];
    }

    protected function login(User $user, array $device, ?string $password = null): TestResponse
    {
        return $this->postJson('/api/v1/auth/mobile/login', [
            'email' => $user->email,
            'password' => $password ?? self::PASSWORD,
            'deviceUuid' => $device['uuid'],
            'deviceCredential' => $device['credential'],
        ]);
    }

    /** @return array{access: string, refresh: string, session: string} */
    protected function tokens(User $user, array $device): array
    {
        $r = $this->login($user, $device)->assertCreated();

        return ['access' => $r->json('data.accessToken'), 'refresh' => $r->json('data.refreshToken'), 'session' => $r->json('data.session.id')];
    }

    protected function refreshWith(array $device, string $refresh): TestResponse
    {
        return $this->postJson('/api/v1/auth/mobile/refresh', ['deviceUuid' => $device['uuid'], 'refreshToken' => $refresh]);
    }

    /** Cada petición sin estado de otra: Sanctum guarda el usuario resuelto entre peticiones de una prueba. */
    protected function bearer(string $token, string $method, string $uri, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, $body, ['Authorization' => 'Bearer '.$token]);
    }

    protected function sessionStatus(string $uuid): array
    {
        $s = AuthSession::query()->where('uuid', $uuid)->firstOrFail();

        return [$s->status, $s->revocation_reason];
    }

    protected function auditCount(string $action): int
    {
        return DB::connection('mongodb')->table('audit_log')->where('action', $action)->count();
    }
}
