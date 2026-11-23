<?php

namespace Tests\Feature\Floor;

use App\Models\Notification;
use App\Models\Payment;
use App\Notifications\Notifications;
use App\Payments\Payments;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;

/** Avisos de la campana: quién recibe cada uno, agrupación de "orden lista" y lectura. */
class NotificationsTest extends FloorTestCase
{
    private function v(array $doc): array
    {
        return ['If-Match' => '"'.$doc['version'].'"'] + $this->key();
    }

    /** Sesión del mesero con una ronda (2 aguas y unos tacos) y su cuenta. @return array{0: string, 1: array, 2: array} */
    private function served(int $table = 1): array
    {
        $session = $this->openSession($table);
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated()->json('data');

        return [$session, $this->as('mesero', 'GET', "checks?sessionId=$session")->json('data.0'), $order];
    }

    private function inbox(string $who, string $query = ''): array
    {
        return $this->as($who, 'GET', 'notifications'.$query)->assertOk()->json();
    }

    public function test_orden_lista_llega_al_mesero_en_un_solo_aviso(): void
    {
        [$session, , $order] = $this->served();
        $all = [...$this->unitsOf($order), ...$this->unitsOf($order, 1)];
        $this->transition('cocina', 'START', $all)->assertOk();
        $this->transition('cocina', 'READY', $this->bump($all))->assertOk();
        Notifications::run();
        $this->assertSame(0, Notifications::run(), 'un evento ya atendido no se vuelve a procesar');

        $inbox = $this->inbox('mesero');
        $this->assertSame(1, $inbox['meta']['unreadCount']);
        $this->assertCount(1, $inbox['data']);
        $notice = $inbox['data'][0];
        $this->assertSame(['ORDER_READY', 'Mesa 1 · orden lista', '3 platillos listos para servir.', 1, $session, 3, null],
            [$notice['type'], $notice['title'], $notice['body'], $notice['tableNumber'], $notice['sessionId'], $notice['count'], $notice['readAt']]);
        $this->assertSame([], $this->inbox('cocina')['data']);
        $this->assertSame([], $this->inbox('gerente')['data']);

        // Ya leído, otra ronda lista abre un aviso nuevo.
        $this->as('mesero', 'POST', "notifications/{$notice['id']}/read")->assertOk()->assertJsonPath('data.id', $notice['id']);
        $second = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->json('data');
        $units = $this->unitsOf($second, 1);
        $this->transition('cocina', 'START', $units)->assertOk();
        $this->transition('cocina', 'READY', $this->bump($units))->assertOk();
        Notifications::run();
        $inbox = $this->inbox('mesero');
        $this->assertSame([1, 2], [$inbox['meta']['unreadCount'], count($inbox['data'])]);
        $this->assertSame('1 platillo listo para servir.', $inbox['data'][0]['body']);
        $this->assertCount(1, $this->inbox('mesero', '?unread=true')['data']);
    }

    public function test_cuenta_solicitada_llega_a_quien_cobra_y_no_a_quien_la_pidio(): void
    {
        [, $check] = $this->served();
        $this->as('mesero', 'POST', "checks/{$check['id']}/request", [], $this->v($check))->assertOk();
        Notifications::run();

        $this->assertSame([], $this->inbox('mesero')['data']);
        $notice = $this->inbox('gerente')['data'][0];
        $this->assertSame(['CHECK_REQUESTED', 'Mesa 1 · cuenta solicitada', 'Cuenta por $207.00 MXN.', $check['id']],
            [$notice['type'], $notice['title'], $notice['body'], $notice['checkId']]);
        $this->assertSame([], $this->inbox('cocina')['data']);
    }

    public function test_el_primer_pago_no_avisa_cuenta_solicitada(): void
    {
        [, $check] = $this->served();
        $this->as('mesero', 'POST', 'payments', ['checkId' => $check['id'], 'cashShiftId' => $this->cashShift(), 'method' => 'CASH',
            'amount' => ['amount' => '50', 'currency' => 'MXN'], 'amountTendered' => ['amount' => '50', 'currency' => 'MXN']], $this->key())->assertCreated();
        Notifications::run();
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_resultado_de_terminal_avisa_a_quien_cobro_si_lo_registro_otro(): void
    {
        [, $check] = $this->served();
        $shift = $this->cashShift();
        $pay = fn (string $amount) => $this->as('mesero', 'POST', 'payments', ['checkId' => $check['id'], 'cashShiftId' => $shift, 'method' => 'CARD_CREDIT',
            'amount' => ['amount' => $amount, 'currency' => 'MXN']], $this->key())->assertCreated()->json('data');

        // El mismo mesero registra el resultado: ya lo vio, no hay aviso.
        $own = $pay('50');
        $this->as('mesero', 'POST', "payments/{$own['id']}/result", ['outcome' => 'FAILED'], $this->v($own))->assertOk();
        // El gerente lo registra: el mesero se entera.
        $other = $pay('60');
        $this->as('gerente', 'POST', "payments/{$other['id']}/result", ['outcome' => 'FAILED'], $this->v($other))->assertOk();
        // Nadie registró nada y venció: queda sin confirmar.
        $stale = $pay('70');
        Payment::query()->where('uuid', $stale['id'])->update(['created_at' => now()->subHour()]);
        Payments::expire();
        Notifications::run();

        $types = array_column($this->inbox('mesero')['data'], 'type');
        sort($types);
        $this->assertSame(['PAYMENT_FAILED', 'PAYMENT_UNKNOWN'], $types);
        $failed = collect($this->inbox('mesero')['data'])->firstWhere('type', 'PAYMENT_FAILED');
        $this->assertSame('El pago con tarjeta por $60.00 MXN no pasó. Cóbralo de otra forma.', $failed['body']);
        $this->assertSame([], array_filter(array_column($this->inbox('gerente')['data'], 'type'), fn ($t) => str_starts_with($t, 'PAYMENT')));
    }

    public function test_cada_quien_solo_ve_y_marca_los_suyos(): void
    {
        [, $check] = $this->served();
        $this->as('mesero', 'POST', "checks/{$check['id']}/request", [], $this->v($check))->assertOk();
        Notifications::run();
        $notice = $this->inbox('gerente')['data'][0];

        $this->as('mesero', 'POST', "notifications/{$notice['id']}/read")->assertNotFound();
        $this->as('mesero', 'POST', 'notifications/no-es-uuid/read')->assertNotFound();
        $this->as('gerente', 'GET', 'notifications?unread=quizas')->assertStatus(422);

        $this->travel(2)->minutes();
        $read = $this->as('gerente', 'POST', "notifications/{$notice['id']}/read")->assertOk()->assertJsonPath('data.updatedAt', $notice['updatedAt'])->json('data.readAt');
        $this->assertNotNull($read);
        // Marcarlo otra vez no cambia la hora de lectura.
        $this->as('gerente', 'POST', "notifications/{$notice['id']}/read")->assertOk()->assertJsonPath('data.readAt', $read);

        $this->as('gerente', 'POST', 'notifications/read-all')->assertOk()->assertJsonPath('data.updated', 0);
        $this->assertSame(0, $this->inbox('gerente')['meta']['unreadCount']);
    }

    public function test_los_eventos_viejos_no_avisan(): void
    {
        [, , $order] = $this->served();
        $units = $this->unitsOf($order, 1);
        $this->transition('cocina', 'START', $units)->assertOk();
        $this->transition('cocina', 'READY', $this->bump($units))->assertOk();
        DB::connection('mongodb')->table('outbox_events')->update(['created_at' => new UTCDateTime(now()->subHour())]);
        $this->assertGreaterThan(0, Notifications::run());
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(0, Notifications::run(), 'quedan atendidos');
    }

    public function test_marcar_todo_como_leido(): void
    {
        [, , $order] = $this->served();
        [, , $other] = $this->served(2);
        foreach ([$order, $other] as $o) {
            $units = $this->unitsOf($o, 1);
            $this->transition('cocina', 'START', $units)->assertOk();
            $this->transition('cocina', 'READY', $this->bump($units))->assertOk();
        }
        Notifications::run();
        $this->assertSame(2, $this->inbox('mesero')['meta']['unreadCount']);
        $this->as('mesero', 'POST', 'notifications/read-all')->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertSame(0, $this->inbox('mesero')['meta']['unreadCount']);
        $this->assertCount(2, $this->inbox('mesero')['data']);
    }

    private function tomato(string $initial): string
    {
        $id = $this->as('gerente', 'POST', 'ingredients', ['code' => 'TOMATE', 'name' => 'Jitomate', 'baseUnit' => 'KG', 'minStock' => '3'])
            ->assertCreated()->json('data.id');
        $this->adjust($id, $initial, 'Stock inicial');

        return $id;
    }

    private function adjust(string $ingredient, string $quantity, string $reason): void
    {
        $this->as('gerente', 'POST', 'inventory-movements', ['ingredientId' => $ingredient, 'type' => 'ADJUSTMENT', 'quantity' => $quantity, 'reason' => $reason], $this->key())
            ->assertCreated();
        $this->artisan('restia:inventory')->assertSuccessful();
    }

    private function waste(string $ingredient, string $quantity): void
    {
        $this->as('cocina', 'POST', 'inventory-movements', ['ingredientId' => $ingredient, 'type' => 'WASTE', 'quantity' => $quantity, 'reason' => 'Se echó a perder'], $this->key())
            ->assertCreated();
        $this->artisan('restia:inventory')->assertSuccessful();
        Notifications::run();
    }

    public function test_stock_bajo_avisa_al_gerente_cuando_cocina_registra_merma(): void
    {
        $tomato = $this->tomato('5');
        $this->waste($tomato, '1');
        $this->assertSame([], $this->inbox('gerente')['data'], 'con 4 kg sigue arriba del mínimo');

        $this->waste($tomato, '2.5');
        $inbox = $this->inbox('gerente');
        $this->assertSame(1, $inbox['meta']['unreadCount']);
        $notice = $inbox['data'][0];
        $this->assertSame(['LOW_STOCK', 'Stock bajo · Jitomate', 'Quedan 1.5 kg; el mínimo es 3 kg.', null, null, null],
            [$notice['type'], $notice['title'], $notice['body'], $notice['tableNumber'], $notice['sessionId'], $notice['checkId']]);
        $this->assertSame([], $this->inbox('cocina')['data']);
        $this->assertSame([], $this->inbox('mesero')['data']);

        // Se surte y vuelve a bajar sin que el gerente lo haya leído: el mismo aviso, actualizado.
        $this->adjust($tomato, '4', 'Llegó el proveedor');
        $this->waste($tomato, '4.5');
        $inbox = $this->inbox('gerente');
        $this->assertSame([1, 1, 'Quedan 1 kg; el mínimo es 3 kg.'], [$inbox['meta']['unreadCount'], count($inbox['data']), $inbox['data'][0]['body']]);
    }

    public function test_stock_bajo_no_avisa_si_ya_se_surtio(): void
    {
        // Baja del mínimo y, antes de que se procese el aviso, llega mercancía.
        $tomato = $this->tomato('5');
        $this->adjust($tomato, '-3', 'Conteo rápido');
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'ingredient.low_stock')->count());
        $this->adjust($tomato, '10', 'Llegó el proveedor');
        Notifications::run();
        $this->assertSame([], $this->inbox('gerente')['data']);
    }
}
