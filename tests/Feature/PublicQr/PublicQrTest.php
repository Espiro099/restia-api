<?php

namespace Tests\Feature\PublicQr;

use App\Models\RestaurantTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Floor\FloorTestCase;

/** Public QR: menú y presencia con el token de la mesa, sin exponer nada interno; consulta y rotación por el personal. */
class PublicQrTest extends FloorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
    }

    private function visitor(string $method, string $path, ?string $token, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/v1/public/'.$path, [], array_filter(['X-QR-Token' => $token]) + $headers);
    }

    private function token(int $number): string
    {
        return $this->as('gerente', 'GET', 'tables/'.$this->table($number)->uuid.'/qr')->assertOk()->json('data.token');
    }

    public function test_el_visitante_ve_el_menu_y_nada_interno(): void
    {
        $token = $this->token(1);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);

        $context = $this->visitor('GET', 'context', $token)->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->json('data');
        $this->assertSame(['restaurantName' => $this->branch->name, 'tableNumber' => 1, 'currency' => 'MXN', 'timezone' => 'America/Mexico_City'], $context);

        $menu = $this->visitor('GET', 'menu', $token)->assertOk()->assertHeader('ETag');
        $products = $menu->json('data.products');
        $this->assertNotEmpty($products);
        foreach ($products as $p) {
            $this->assertSame(['id', 'name', 'category', 'displayOrder', 'price', 'availability', 'modifierIds', 'imageUrl'], array_keys($p));
        }
        // Los agotados se ven, marcados (QR-7).
        $this->assertContains('SOLD_OUT', array_column($products, 'availability'));
        $this->assertArrayNotHasKey('branchId', $menu->json('data'));
        $this->assertStringNotContainsString($this->branch->uuid, $menu->getContent());
        $this->visitor('GET', 'menu', $token, ['If-None-Match' => $menu->headers->get('ETag')])->assertStatus(304);
    }

    public function test_un_token_invalido_siempre_responde_igual(): void
    {
        foreach ([null, 'corto', str_repeat('a', 43)] as $bad) {
            $this->visitor('GET', 'menu', $bad)->assertStatus(401)->assertJsonPath('error.code', 'QR_TOKEN_INVALID');
        }
        $token = $this->token(2);
        $this->as('admin', 'POST', 'tables/'.$this->table(2)->uuid.'/status', ['status' => 'INACTIVE', 'reason' => 'Se retiró'],
            ['If-Match' => '"'.$this->table(2)->version.'"'])->assertOk();
        $this->visitor('GET', 'context', $token)->assertStatus(401)->assertJsonPath('error.code', 'QR_TOKEN_INVALID');
    }

    public function test_presencia_marca_posible_ocupacion_y_vence(): void
    {
        $token = $this->token(3);
        $this->visitor('POST', 'presence', $token)->assertNoContent();
        $this->visitor('POST', 'presence', $token)->assertNoContent();
        $this->assertSame(1, DB::connection('mongodb')->table('table_presence_signals')->count());
        $state = fn () => collect($this->as('mesero', 'GET', 'tables')->json('data'))->firstWhere('number', 3)['operationalState'];
        $this->assertSame('POSIBLE_OCUPACION', $state());
        // Se puede abrir como cualquier mesa libre, y con sesión la presencia ya no cuenta.
        $session = $this->openSession(3);
        $this->assertSame('OCUPADA_SIN_COMANDA', $state());
        $this->as('gerente', 'POST', "table-sessions/$session/cancel", ['reason' => 'Prueba'], ['If-Match' => '"1"'])->assertOk();

        $this->travel(11)->minutes();
        $this->assertSame('LIBRE', $state());

        // En mantenimiento responde igual, pero no registra.
        DB::connection('mongodb')->table('table_presence_signals')->delete();
        $table = $this->table(4);
        $token4 = $this->token(4);
        $this->as('admin', 'POST', 'tables/'.$table->uuid.'/status', ['status' => 'MAINTENANCE', 'reason' => 'Pata rota'], ['If-Match' => '"'.$table->version.'"'])->assertOk();
        $this->visitor('POST', 'presence', $token4)->assertNoContent();
        $this->assertSame(0, DB::connection('mongodb')->table('table_presence_signals')->count());
        $this->visitor('GET', 'menu', $token4)->assertOk();
    }

    public function test_limites_de_uso(): void
    {
        $token = $this->token(5);
        for ($i = 0; $i < 6; $i++) {
            $this->visitor('POST', 'presence', $token)->assertNoContent();
        }
        $this->visitor('POST', 'presence', $token)->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS')->assertHeader('Retry-After');

        RateLimiter::clear('');
        for ($i = 0; $i < 20; $i++) {
            $this->visitor('GET', 'menu', str_repeat('b', 43))->assertStatus(401);
        }
        $this->visitor('GET', 'menu', str_repeat('b', 43))->assertStatus(429);
    }

    public function test_rotar_deja_el_anterior_siete_dias_y_una_segunda_rotacion_lo_invalida(): void
    {
        $table = $this->table(6);
        $path = "tables/{$table->uuid}/qr";
        $this->as('mesero', 'GET', $path)->assertStatus(403);
        $first = $this->as('gerente', 'GET', $path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.previousTokenExpiresAt', null)->json('data');
        $this->assertStringEndsWith('/m#'.$first['token'], $first['url']);

        $this->as('mesero', 'POST', "$path/rotate", ['reason' => 'Foto en redes'], ['If-Match' => '"'.$first['version'].'"'])->assertStatus(403);
        $this->as('gerente', 'POST', "$path/rotate", ['reason' => 'Foto en redes'])->assertStatus(400);
        $this->as('gerente', 'POST', "$path/rotate", ['reason' => 'x'], ['If-Match' => '"'.$first['version'].'"'])->assertStatus(422);
        $second = $this->as('gerente', 'POST', "$path/rotate", ['reason' => 'Foto en redes'], ['If-Match' => '"'.$first['version'].'"'])->assertOk()->json('data');
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertNotNull($second['previousTokenExpiresAt']);
        $this->as('gerente', 'POST', "$path/rotate", ['reason' => 'Otra vez'], ['If-Match' => '"'.$first['version'].'"'])->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');

        // El anterior sigue sirviendo en su gracia y deja de servir a los 7 días.
        $this->visitor('GET', 'context', $first['token'])->assertOk();
        $this->visitor('GET', 'context', $second['token'])->assertOk();
        $this->travel(7 * 24 * 60 + 1)->minutes();
        $this->visitor('GET', 'context', $first['token'])->assertStatus(401);
        $this->travelBack();

        // Una segunda rotación dentro de la gracia invalida de inmediato al primero.
        $third = $this->as('admin', 'POST', "$path/rotate", ['reason' => 'Otra foto'], ['If-Match' => '"'.$second['version'].'"'])->assertOk()->json('data');
        $this->visitor('GET', 'context', $first['token'])->assertStatus(401);
        $this->visitor('GET', 'context', $second['token'])->assertOk();
        $this->visitor('GET', 'context', $third['token'])->assertOk();

        $audit = DB::connection('mongodb')->table('audit_log')->where('action', 'table.qr_rotated')->get();
        $this->assertCount(2, $audit);
        $this->assertStringNotContainsString($first['token'], json_encode($audit));
        $this->assertTrue(((array) ((array) $audit[1])['changes'])['previousTokenRevoked']);
    }

    public function test_una_mesa_nueva_nace_con_token(): void
    {
        $id = $this->as('admin', 'POST', 'tables', ['number' => 40, 'capacity' => 4])->assertCreated()->json('data.id');
        $token = RestaurantTable::query()->where('uuid', $id)->first()->qr_token;
        $this->assertNotEmpty($token);
        $this->visitor('GET', 'context', $token)->assertOk()->assertJsonPath('data.tableNumber', 40);
        // La creación de la mesa no deja el token en la auditoría.
        $this->assertStringNotContainsString($token, json_encode(DB::connection('mongodb')->table('audit_log')->get()));
    }
}
