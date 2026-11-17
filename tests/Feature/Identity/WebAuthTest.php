<?php

namespace Tests\Feature\Identity;

use App\Models\AuthSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/** Login Web de D-36, sección 6, y los casos CRED-18 a CRED-20. */
class WebAuthTest extends IdentityTestCase
{
    /** El panel en desarrollo: Sanctum solo abre sesión por cookie para orígenes de SANCTUM_STATEFUL_DOMAINS. */
    private const ORIGIN = 'http://127.0.0.1:5173';

    /** Cookies que el "navegador" de la prueba guarda entre peticiones, ya descifradas. @var array<string, string> */
    private array $jar = [];

    private ?string $xsrf = null;

    private function browser(string $method, string $uri, array $body = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $headers = ['Origin' => self::ORIGIN, 'Referer' => self::ORIGIN.'/', 'Accept' => 'application/json'] + $headers;
        if ($this->xsrf !== null && $method !== 'GET') {
            $headers['X-XSRF-TOKEN'] ??= $this->xsrf;
        }
        $r = $this->withCookies($this->jar)->withHeaders($headers)->json($method, $uri, $body);
        foreach ($r->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN') {
                $this->xsrf = $cookie->getValue();
            }
            $this->jar[$cookie->getName()] = (string) $r->getCookie($cookie->getName())?->getValue();
        }

        return $r;
    }

    private function sessionCookie(TestResponse $r): Cookie
    {
        $cookie = $r->getCookie(config('session.cookie'), false);
        $this->assertNotNull($cookie, 'La respuesta no trae la cookie de sesión');

        return $cookie;
    }

    private function webLogin(string $email, ?string $password = null): TestResponse
    {
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();

        return $this->browser('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password ?? self::PASSWORD]);
    }

    public function test_login_web_abre_sesion_por_cookie_y_me_la_reconoce(): void
    {
        $branch = $this->branch();
        $user = $this->user('gerente@ejemplo.mx', $branch, 'MANAGER');

        $r = $this->webLogin($user->email)->assertOk()->assertNoStore()
            ->assertJsonPath('data.user.email', 'gerente@ejemplo.mx')
            ->assertJsonPath('data.session.clientType', 'WEB')
            ->assertJsonPath('data.memberships.0.role', 'MANAGER');
        $this->assertArrayNotHasKey('accessToken', $r->json('data'));
        $this->assertSame(1, $this->auditCount('auth.login'));

        $this->browser('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.session.id', $r->json('data.session.id'));
    }

    public function test_cookie_de_sesion_httponly_secure_y_samesite(): void
    {
        config(['session.secure' => true]);
        $user = $this->user('gerente@ejemplo.mx', $this->branch(), 'MANAGER');

        $cookie = $this->sessionCookie($this->webLogin($user->email)->assertOk());
        $this->assertTrue($cookie->isHttpOnly(), 'La cookie de sesión debe ser HttpOnly');
        $this->assertTrue($cookie->isSecure(), 'La cookie de sesión debe ser Secure');
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        // Las cookies viajan cifradas con AES-256-CBC: el valor no es el ID de sesión en claro.
        $this->assertSame('AES-256-CBC', config('app.cipher'));
        $this->assertNotSame($this->app['session']->getId(), $cookie->getValue());
    }

    public function test_cred18_post_web_sin_token_csrf_es_403(): void
    {
        // Laravel no valida CSRF mientras corren pruebas: esta prueba simula un entorno real.
        $this->app['env'] = 'local';
        $user = $this->user('gerente@ejemplo.mx', $this->branch(), 'MANAGER');
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();

        $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD], ['X-XSRF-TOKEN' => 'token-falso'])
            ->assertStatus(403)->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
        $this->assertSame(0, AuthSession::query()->count());

        // Con el token correcto, el mismo login pasa.
        $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    public function test_login_sin_origen_del_panel_no_abre_sesion(): void
    {
        $user = $this->user('gerente@ejemplo.mx', $this->branch(), 'MANAGER');

        // Sin Origin ni Referer del panel no hay sesión ni CSRF: el login web no se acepta.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(403)->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_credenciales_invalidas_no_revelan_ni_registran_datos(): void
    {
        $user = $this->user('gerente@ejemplo.mx', $this->branch(), 'MANAGER');
        Log::spy();

        $this->webLogin($user->email, 'otra-clave')->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_INVALID');
        $this->browser('POST', '/api/v1/auth/login', ['email' => 'nadie@ejemplo.mx', 'password' => 'otra-clave'])
            ->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_INVALID');

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
            $flat = json_encode($context);

            return ! str_contains($flat, 'otra-clave') && ! str_contains($flat, 'ejemplo.mx');
        });
    }

    public function test_inyeccion_de_operadores_mongo_en_el_login_es_422(): void
    {
        $this->user('gerente@ejemplo.mx', $this->branch(), 'MANAGER');
        $this->browser('GET', '/sanctum/csrf-cookie');

        // {"email": {"$ne": null}} intentaría entrar sin saber el correo.
        $this->browser('POST', '/api/v1/auth/login', ['email' => ['$ne' => null], 'password' => ['$ne' => null]])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        // Campos no declarados también se rechazan (asignación masiva).
        $this->browser('POST', '/api/v1/auth/login', ['email' => 'gerente@ejemplo.mx', 'password' => self::PASSWORD, 'role' => 'ADMIN'])
            ->assertStatus(422);
        $this->assertSame(0, AuthSession::query()->count());
    }

    public function test_logout_web_cierra_la_sesion_y_la_cookie_deja_de_servir(): void
    {
        $user = $this->user('gerente@ejemplo.mx', $this->branch(), 'MANAGER');
        $id = $this->webLogin($user->email)->json('data.session.id');

        $this->browser('POST', '/api/v1/auth/logout')->assertNoContent();
        $this->assertSame([AuthSession::REVOKED, 'LOGOUT'], $this->sessionStatus($id));
        $this->browser('GET', '/api/v1/auth/me')->assertStatus(401);
    }

    public function test_cuarta_sesion_web_cierra_la_mas_antigua(): void
    {
        $user = $this->user('gerente@ejemplo.mx', $this->branch(), 'MANAGER');
        $ids = [];
        foreach (range(1, 4) as $i) {
            $this->jar = [];
            $this->xsrf = null;
            $ids[] = $this->webLogin($user->email)->assertOk()->json('data.session.id');
            $this->travel(1)->minutes();
        }

        $this->assertSame([AuthSession::REVOKED, 'SESSION_LIMIT'], $this->sessionStatus($ids[0]));
        $this->assertSame(3, AuthSession::query()->where('status', AuthSession::ACTIVE)->count());
    }

    public function test_cred20_vence_por_inactividad_y_por_maximo(): void
    {
        $user = $this->user('gerente@ejemplo.mx', $this->branch(), 'MANAGER');
        $this->webLogin($user->email)->assertOk();

        $this->travel(59)->minutes();
        $this->browser('GET', '/api/v1/auth/me')->assertOk();
        $this->travel(61)->minutes();
        $this->browser('GET', '/api/v1/auth/me')->assertStatus(401);

        // Con actividad constante, igual vence a las 16 h.
        $this->jar = [];
        $this->xsrf = null;
        $this->webLogin($user->email)->assertOk();
        foreach (range(1, 16) as $hour) {
            $this->travel(50)->minutes();
            $this->browser('GET', '/api/v1/auth/me');
            $this->travel(10)->minutes();
        }
        $this->browser('GET', '/api/v1/auth/me')->assertStatus(401);
    }

    public function test_cred19_android_con_bearer_no_necesita_csrf(): void
    {
        $this->app['env'] = 'local';
        $branch = $this->branch();
        $user = $this->user('mesero@ejemplo.mx', $branch);
        $tokens = $this->tokens($user, $this->enrolledDevice($branch));

        $this->bearer($tokens['access'], 'POST', '/api/v1/auth/logout')->assertNoContent();
    }
}
