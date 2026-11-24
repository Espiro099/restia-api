<?php

namespace Tests\Feature\Identity;

use App\Identity\GoogleIdTokenVerifier;
use App\Identity\GoogleTokens;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/** Inicio de sesión con Google (GOOGLE-1 a GOOGLE-4): solo personal ya dado de alta, sin crear cuentas. */
class GoogleLoginTest extends IdentityTestCase
{
    private const ORIGIN = 'http://127.0.0.1:5173';

    private const CLIENT = '1234-abc.apps.googleusercontent.com';

    private FakeGoogleTokens $google;

    /** @var array<string, string> */
    private array $jar = [];

    private ?string $xsrf = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->google = new FakeGoogleTokens(self::CLIENT);
        $this->app->instance(GoogleTokens::class, $this->google);
    }

    private function browser(string $method, string $uri, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $headers = ['Origin' => self::ORIGIN, 'Referer' => self::ORIGIN.'/', 'Accept' => 'application/json'];
        if ($this->xsrf !== null && $method !== 'GET') {
            $headers['X-XSRF-TOKEN'] = $this->xsrf;
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

    private function webGoogle(string $token): TestResponse
    {
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();

        return $this->browser('POST', '/api/v1/auth/google', ['credential' => $token]);
    }

    public function test_dice_si_esta_encendido_y_con_que_cliente(): void
    {
        $this->getJson('/api/v1/auth/google')->assertOk()->assertJsonPath('data', ['enabled' => true, 'clientId' => self::CLIENT]);
        $this->app->instance(GoogleTokens::class, new FakeGoogleTokens(null));
        $this->getJson('/api/v1/auth/google')->assertOk()->assertJsonPath('data', ['enabled' => false, 'clientId' => null]);
        $this->postJson('/api/v1/auth/mobile/google', ['idToken' => str_repeat('a', 20), 'deviceUuid' => '0b3c1f6e-8f7a-4a51-9a3e-2d1c7e5b9a10', 'deviceCredential' => str_repeat('a', 40)])
            ->assertStatus(401); // el dispositivo se revisa primero
    }

    public function test_panel_entra_con_google_si_el_correo_es_del_personal(): void
    {
        $user = $this->user('gerente@ejemplo.mx', $this->branch(), 'MANAGER');
        $this->google->accept('token-ana.jwt.de.prueba', 'sub-ana', 'Gerente@Ejemplo.mx');

        $r = $this->webGoogle('token-ana.jwt.de.prueba')->assertOk()->assertNoStore()
            ->assertJsonPath('data.user.email', 'gerente@ejemplo.mx')
            ->assertJsonPath('data.session.clientType', 'WEB');
        $this->browser('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.session.id', $r->json('data.session.id'));

        $this->assertSame('sub-ana', User::query()->find($user->getKey())->google_sub);
        $this->assertSame(1, $this->auditCount('user.google_linked'));
        $this->assertSame(1, $this->auditCount('auth.login'));
        $this->assertArrayNotHasKey('googleSub', $r->json('data.user'));
    }

    public function test_no_crea_cuentas_ni_deja_entrar_a_inactivos(): void
    {
        $this->google->accept('token-extrano.jwt.de.prueba', 'sub-x', 'nadie@gmail.com');
        $this->webGoogle('token-extrano.jwt.de.prueba')->assertStatus(401)->assertJsonPath('error.code', 'AUTHENTICATION_INVALID');
        $this->assertSame(0, User::query()->where('normalized_email', 'nadie@gmail.com')->count());

        $user = $this->user('baja@ejemplo.mx', $this->branch(), 'WAITER');
        $user->update(['status' => User::INACTIVE]);
        $this->google->accept('token-baja.jwt.de.prueba', 'sub-baja', 'baja@ejemplo.mx');
        $this->webGoogle('token-baja.jwt.de.prueba')->assertStatus(401);

        $this->webGoogle('token-falso.jwt.de.prueba')->assertStatus(401);
    }

    public function test_despues_de_ligarla_solo_entra_esa_cuenta_de_google(): void
    {
        $this->user('mesero@ejemplo.mx', $this->branch(), 'WAITER');
        $this->google->accept('primera.jwt.de.prueba', 'sub-1', 'mesero@ejemplo.mx');
        $this->google->accept('otra.jwt.de.prueba', 'sub-2', 'mesero@ejemplo.mx');
        $this->webGoogle('primera.jwt.de.prueba')->assertOk();
        $this->jar = [];
        $this->xsrf = null;
        $this->webGoogle('otra.jwt.de.prueba')->assertStatus(401);
    }

    public function test_apagado_responde_404(): void
    {
        $this->app->instance(GoogleTokens::class, new FakeGoogleTokens(null));
        $this->webGoogle('cualquiera-token')->assertNotFound();
    }

    public function test_la_app_entra_con_google_en_un_dispositivo_dado_de_alta(): void
    {
        $branch = $this->branch();
        $this->user('mesero@ejemplo.mx', $branch, 'WAITER');
        $device = $this->enrolledDevice($branch);
        $this->google->accept('token-app.jwt.de.prueba', 'sub-m', 'mesero@ejemplo.mx');

        $this->postJson('/api/v1/auth/mobile/google', ['idToken' => 'token-app.jwt.de.prueba', 'deviceUuid' => $device['uuid'], 'deviceCredential' => $device['credential']])
            ->assertCreated()->assertNoStore()
            ->assertJsonStructure(['data' => ['accessToken', 'refreshToken']]);
        $this->postJson('/api/v1/auth/mobile/google', ['idToken' => 'token-app.jwt.de.prueba', 'deviceUuid' => $device['uuid'], 'deviceCredential' => str_repeat('x', 40)])
            ->assertStatus(401)->assertJsonPath('error.code', 'DEVICE_NOT_ENROLLED');

        // Sin membresía en la sucursal del dispositivo, igual que con contraseña.
        $this->user('otra@ejemplo.mx', $this->branch('NORTE'), 'WAITER');
        $this->google->accept('token-otra.jwt.de.prueba', 'sub-o', 'otra@ejemplo.mx');
        $this->postJson('/api/v1/auth/mobile/google', ['idToken' => 'token-otra.jwt.de.prueba', 'deviceUuid' => $device['uuid'], 'deviceCredential' => $device['credential']])
            ->assertStatus(403)->assertJsonPath('error.code', 'BRANCH_ACCESS_DENIED');
    }

    public function test_verificador_real_revisa_firma_audiencia_emisor_y_correo(): void
    {
        [$private, $jwk] = $this->rsaKey('llave-1');
        $published = [$jwk];
        Http::fake(['www.googleapis.com/*' => function () use (&$published) {
            return Http::response(['keys' => $published], 200, ['Cache-Control' => 'public, max-age=3600']);
        }]);
        $verifier = new GoogleIdTokenVerifier(self::CLIENT);
        $claims = fn (array $extra = []) => $extra + [
            'iss' => 'https://accounts.google.com', 'aud' => self::CLIENT, 'sub' => '1098', 'email' => 'ana@ejemplo.mx',
            'email_verified' => true, 'iat' => time(), 'exp' => time() + 600,
        ];
        $sign = fn (array $c, string $kid = 'llave-1', $key = null) => JWT::encode($c, $key ?? $private, 'RS256', $kid);

        $this->assertSame('1098', $verifier->verify($sign($claims()))['sub']);
        $this->assertNull($verifier->verify($sign($claims(['aud' => 'otro-cliente']))));
        $this->assertNull($verifier->verify($sign($claims(['iss' => 'https://evil.example']))));
        $this->assertNull($verifier->verify($sign($claims(['email_verified' => false]))));
        $this->assertNull($verifier->verify($sign($claims(['exp' => time() - 3600]))));
        [$otherKey] = $this->rsaKey('llave-1');
        $this->assertNull($verifier->verify($sign($claims(), 'llave-1', $otherKey)), 'firma con otra llave');
        $this->assertNull($verifier->verify('no.es.jwt'));
        // Las llaves quedaron en caché: una sola petición a Google.
        Http::assertSentCount(1);

        // Google rotó las llaves: con un kid desconocido se piden de nuevo, una vez.
        [$private2, $jwk2] = $this->rsaKey('llave-2');
        $published = [$jwk, $jwk2];
        $this->assertSame('1098', $verifier->verify($sign($claims(), 'llave-2', $private2))['sub']);
        Http::assertSentCount(2);

        $this->assertNull((new GoogleIdTokenVerifier(null))->verify($sign($claims())));
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function rsaKey(string $kid): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        $d = openssl_pkey_get_details($res)['rsa'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return [$pem, ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($d['n']), 'e' => $b64($d['e'])]];
    }
}

final class FakeGoogleTokens implements GoogleTokens
{
    /** @var array<string, array<string, mixed>> */
    private array $valid = [];

    public function __construct(private readonly ?string $client) {}

    public function accept(string $token, string $sub, string $email): void
    {
        $this->valid[$token] = ['sub' => $sub, 'email' => $email, 'email_verified' => true];
    }

    public function clientId(): ?string
    {
        return $this->client;
    }

    public function verify(string $idToken): ?array
    {
        return $this->valid[$idToken] ?? null;
    }
}
