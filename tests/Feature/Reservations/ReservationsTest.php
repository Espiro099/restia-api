<?php

namespace Tests\Feature\Reservations;

use App\Models\Branch;
use App\Models\RestaurantTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Floor\FloorTestCase;

/** Reservas (R-1 a R-12): disponibilidad, confirmación con mesas, llegada, prioridad, walk-ins, cierres y retención. */
class ReservationsTest extends FloorTestCase
{
    private const TZ = 'America/Mexico_City';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-11-20 10:00');
    }

    /** Mueve el reloj a una hora local y vuelve a iniciar sesión: el token de acceso dura 15 minutos. */
    private function clock(string $local): void
    {
        $this->travelTo(Carbon::parse($local, self::TZ));
        foreach ($this->staff as $name => $member) {
            $this->staff[$name]['access'] = $this->tokens($member['user'], $this->enrolledDevice($this->branch))['access'];
        }
    }

    private function at(string $time): string
    {
        return Carbon::parse("2026-11-20 $time", self::TZ)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function reserve(int $party = 4, string $start = '14:00', string $end = '16:00', array $customer = []): array
    {
        return $this->as('mesero', 'POST', 'reservations', [
            'startAt' => $this->at($start), 'endAt' => $this->at($end), 'partySize' => $party,
            'customer' => $customer + ['name' => 'Ana López', 'phone' => '+523312345678'],
        ], $this->key())->assertCreated()->json('data');
    }

    private function act(string $who, string $action, array $r, array $body = []): TestResponse
    {
        return $this->as($who, 'POST', "reservations/{$r['id']}/$action", $body, ['If-Match' => '"'.$r['version'].'"'] + $this->key());
    }

    private function confirm(array $r): array
    {
        return $this->act('mesero', 'confirm', $r)->assertOk()->json('data');
    }

    private function tableIds(int ...$numbers): array
    {
        return array_map(fn ($n) => $this->table($n)->uuid, $numbers);
    }

    public function test_disponibilidad_y_confirmacion_eligen_la_mesa_que_menos_sobra(): void
    {
        $this->as('cocina', 'GET', 'reservations/availability?startAt='.urlencode($this->at('14:00')).'&endAt='.urlencode($this->at('16:00')).'&partySize=4')->assertStatus(403);
        $query = fn ($start, $end, $party) => 'reservations/availability?startAt='.urlencode($this->at($start)).'&endAt='.urlencode($this->at($end))."&partySize=$party";
        $options = $this->as('mesero', 'GET', $query('14:00', '16:00', 4))->assertOk()->assertJsonPath('data.available', true)->json('data.tableOptions');
        $this->assertSame([$this->tableIds(1), $this->tableIds(2), $this->tableIds(4)], array_slice(array_column($options, 'tableIds'), 0, 3));
        $this->assertSame(4, $options[0]['totalCapacity']);
        // Fuera de horario no hay lugar, pero sugiere otras horas.
        $late = $this->as('mesero', 'GET', $query('22:30', '23:30', 2))->assertOk()->assertJsonPath('data.available', false)->json('data');
        $this->assertSame([], $late['tableOptions']);
        $this->assertNotEmpty($late['alternativeStartTimes']);

        $this->as('mesero', 'POST', 'reservations', ['startAt' => $this->at('22:30'), 'endAt' => $this->at('23:30'), 'partySize' => 2,
            'customer' => ['name' => 'Ana', 'phone' => '+523312345678']], $this->key())->assertStatus(422);
        $first = $this->reserve();
        $this->assertSame('PENDING', $first['status']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $first['confirmationCode']);
        $this->assertSame([], $first['plannedTableIds']);

        // Cada confirmación toma la siguiente mejor opción libre en ese horario.
        $planned = [];
        foreach (range(1, 4) as $_) {
            $planned[] = $this->confirm($this->reserve())['plannedTableIds'];
        }
        $this->assertSame([$this->tableIds(1), $this->tableIds(2), $this->tableIds(4), $this->tableIds(6)], $planned);
        // Ya no queda una mesa sola de 4 ni una combinación libre: la quinta no se confirma.
        $this->act('mesero', 'confirm', $first)->assertStatus(409)->assertJsonPath('error.code', 'RESERVATION_NOT_AVAILABLE');
    }

    public function test_sin_lugar_no_confirma_ni_cambia_lo_planeado(): void
    {
        $big = $this->reserve(8);
        $this->confirm($big);
        $other = $this->reserve(8);
        $other = $this->confirm($other);
        $this->assertSame($this->tableIds(1, 2), $other['plannedTableIds']);
        // La 3 y la 4 del salón juntas también dan 8.
        $this->assertSame($this->tableIds(3, 4), $this->confirm($this->reserve(8))['plannedTableIds']);
        $third = $this->reserve(8);
        $this->act('mesero', 'confirm', $third)->assertStatus(409)->assertJsonPath('error.code', 'RESERVATION_NOT_AVAILABLE');
        $this->as('mesero', 'GET', "reservations/{$third['id']}")->assertJsonPath('data.status', 'PENDING');

        // Editar una confirmada a algo que no cabe conserva lo anterior.
        $this->as('mesero', 'PATCH', "reservations/{$other['id']}", ['partySize' => 20], ['If-Match' => '"'.$other['version'].'"'] + $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'RESERVATION_NOT_AVAILABLE');
        $this->as('mesero', 'PATCH', "reservations/{$other['id']}", ['plannedTableIds' => $this->tableIds(6)], ['If-Match' => '"'.$other['version'].'"'] + $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'NO_TABLE_AVAILABLE');
        $moved = $this->as('mesero', 'PATCH', "reservations/{$other['id']}", ['startAt' => $this->at('17:00'), 'endAt' => $this->at('19:00')],
            ['If-Match' => '"'.$other['version'].'"'] + $this->key())->assertOk()->json('data');
        $this->assertSame($this->at('17:00'), $moved['startAt']);
        $this->assertSame(8, $moved['partySize']);
        $this->as('mesero', 'GET', "reservations/{$other['id']}")->assertJsonPath('data.plannedTableIds', $this->tableIds(6));
    }

    public function test_abrir_una_mesa_reservada_pronto_exige_confirmarlo_y_replanea(): void
    {
        $r = $this->confirm($this->reserve());
        $this->assertSame($this->tableIds(1), $r['plannedTableIds']);
        $this->clock('2026-11-20 13:30');

        $body = ['tableIds' => $this->tableIds(1), 'guestCount' => 2];
        $this->as('mesero', 'POST', 'table-sessions', $body, $this->key())->assertStatus(409)
            ->assertJsonPath('error.code', 'TABLE_RESERVED_SOON')->assertJsonPath('error.details.reservationStartAt', $this->at('14:00'));
        $this->as('mesero', 'POST', 'table-sessions', $body + ['acknowledgeReservation' => true], $this->key())->assertCreated();
        $this->as('mesero', 'GET', "reservations/{$r['id']}")->assertJsonPath('data.plannedTableIds', $this->tableIds(2))->assertJsonPath('data.planConflict', false);
        $this->assertSame(1, DB::connection('mongodb')->table('audit_log')->where('action', 'reservation.replanned')->count());
    }

    public function test_llegada_prioridad_check_in_y_cierre(): void
    {
        $customer = $this->as('mesero', 'POST', 'customers', ['name' => 'Beto Ruiz', 'phone' => '+523398765432'], $this->key())->json('data');
        $a = $this->confirm($this->reserve(2));
        $b = $this->confirm($this->reserve(2, '14:00', '16:00', ['customerId' => $customer['id'], 'name' => 'Beto Ruiz', 'phone' => '+523398765432']));
        $this->clock('2026-11-20 13:55');
        $a = $this->act('mesero', 'arrive', $a)->assertOk()->assertJsonPath('data.status', 'ARRIVED_WAITING')->json('data');
        $this->clock('2026-11-20 13:56');
        $b = $this->act('mesero', 'arrive', $b)->assertOk()->json('data');
        $this->act('mesero', 'arrive', $b)->assertStatus(409)->assertJsonPath('error.code', 'RESERVATION_INVALID_STATE');

        $queue = $this->as('mesero', 'GET', 'reservations/waiting-queue')->assertOk()->json('data');
        $this->assertSame([1, 2], array_column($queue, 'position'));
        $this->assertSame($a['id'], $queue[0]['reservation']['id']);
        $this->assertNotEmpty($queue[0]['tableOptions']);

        // B no puede pasar antes que A a una mesa donde A cabe (R-8).
        $body = ['tableIds' => $this->tableIds(3)];
        $this->act('mesero', 'check-in', $b, $body)->assertStatus(409)->assertJsonPath('error.code', 'RESERVATION_PRIORITY_CONFLICT');
        $this->act('mesero', 'check-in', $b, $body + ['overridePriority' => true, 'overrideReason' => 'Mesa del cumpleañero'])->assertStatus(403);
        $this->act('gerente', 'check-in', $b, $body + ['overridePriority' => true])->assertStatus(422);
        $seated = $this->act('gerente', 'check-in', $b, $body + ['overridePriority' => true, 'overrideReason' => 'Mesa del cumpleañero'])->assertOk()
            ->assertJsonPath('data.status', 'CHECKED_IN')->json('data');
        $session = $seated['sessionId'];
        $this->as('mesero', 'GET', "table-sessions/$session")->assertJsonPath('data.reservationId', $b['id'])->assertJsonPath('data.guestCount', 2);
        $this->as('mesero', 'GET', "customers/{$customer['id']}")->assertJsonPath('data.profile.visitCount', 1);
        $this->act('mesero', 'check-in', $a, ['tableIds' => $this->tableIds(3)])->assertStatus(409)->assertJsonPath('error.code', 'TABLE_ALREADY_ASSIGNED');
        $this->act('mesero', 'check-in', $a, ['tableIds' => $this->tableIds(1, 3)])->assertStatus(409);
        $this->act('mesero', 'check-in', $a, ['tableIds' => $this->tableIds(1)])->assertOk();

        // R-9: la sesión pasa a limpieza y la reserva se completa.
        $version = $this->as('mesero', 'GET', "table-sessions/$session")->json('data.version');
        $this->as('mesero', 'POST', "table-sessions/$session/close", [], ['If-Match' => '"'.$version.'"'])->assertOk();
        $this->as('mesero', 'GET', "reservations/{$b['id']}")->assertJsonPath('data.status', 'COMPLETED');
    }

    public function test_cancelar_no_show_y_busqueda_por_codigo(): void
    {
        $r = $this->confirm($this->reserve());
        $this->act('mesero', 'no-show', $r)->assertStatus(409)->assertJsonPath('error.code', 'RESERVATION_INVALID_STATE');
        $this->clock('2026-11-20 14:16');
        $this->act('mesero', 'no-show', $r)->assertOk()->assertJsonPath('data.status', 'NO_SHOW')->assertJsonPath('data.plannedTableIds', []);

        $other = $this->reserve(2, '18:00', '19:00');
        $this->act('mesero', 'cancel', $other, ['reason' => 'x'])->assertStatus(422);
        $this->act('mesero', 'cancel', $other, ['reason' => 'El cliente llamó'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $this->as('mesero', 'POST', 'reservations/lookup', ['confirmationCode' => $other['confirmationCode'], 'phone' => '+523312345678'])
            ->assertOk()->assertJsonPath('data.id', $other['id']);
        $this->as('mesero', 'POST', 'reservations/lookup', ['confirmationCode' => $other['confirmationCode'], 'phone' => '+523300000000'])->assertStatus(404);
        $failed = DB::connection('mongodb')->table('audit_log')->where('action', 'reservation.lookup_failed')->get();
        $this->assertCount(1, $failed);
        $this->assertStringNotContainsString('3300000000', json_encode($failed));

        $this->as('mesero', 'GET', 'reservations?status=CANCELLED')->assertOk()->assertJsonCount(1, 'data');
        $this->as('mesero', 'GET', 'reservations?from='.urlencode($this->at('17:30')))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_tareas_automaticas_y_retencion_de_doce_meses(): void
    {
        $pending = $this->reserve(2, '13:00', '14:00');
        $confirmed = $this->confirm($this->reserve(2, '13:00', '14:00'));
        $this->clock('2026-11-20 13:20');
        $this->artisan('restia:reservations')->assertSuccessful();
        $this->as('mesero', 'GET', "reservations/{$pending['id']}")->assertJsonPath('data.status', 'CANCELLED');
        $this->as('mesero', 'GET', "reservations/{$confirmed['id']}")->assertJsonPath('data.status', 'NO_SHOW');

        // A los 12 meses del fin se borran nombre, teléfono y notas; antes no.
        $this->clock('2027-11-20 13:59');
        $this->artisan('restia:reservations')->assertSuccessful();
        $this->as('mesero', 'GET', "reservations/{$pending['id']}")->assertJsonPath('data.customer.name', 'Ana López');
        $this->clock('2027-11-20 14:01');
        $this->artisan('restia:reservations')->expectsOutputToContain('datos borrados 2')->assertSuccessful();
        $this->as('mesero', 'GET', "reservations/{$pending['id']}")->assertJsonPath('data.customer.name', 'Cliente anonimizado')
            ->assertJsonPath('data.customer.phone', null)->assertJsonPath('data.notes', null);
    }

    /** Casos negativos de casos_negativos.csv para las reservas del personal. Los RESERVATION_AI_* llegan con IA. */
    public function test_casos_negativos_de_reservas(): void
    {
        $code = fn (TestResponse $r, string $code) => $r->assertStatus(409)->assertJsonPath('error.code', $code);
        $stale = fn (array $r) => ['If-Match' => '"'.($r['version'] + 5).'"'] + $this->key();

        // Una clave usada con otro cuerpo, en cada operación con Idempotency-Key.
        $key = $this->key();
        $body = ['startAt' => $this->at('14:00'), 'endAt' => $this->at('16:00'), 'partySize' => 2, 'customer' => ['name' => 'Ana', 'phone' => '+523312345678']];
        $a = $this->as('mesero', 'POST', 'reservations', $body, $key)->assertCreated()->json('data');
        $code($this->as('mesero', 'POST', 'reservations', ['partySize' => 3] + $body, $key), 'IDEMPOTENCY_KEY_REUSED');
        $b = $this->reserve(2);
        $reuse = function (string $action, array $first, array $second, array $body = []) use ($code) {
            $key = $this->key();
            $this->as('mesero', 'POST', "reservations/{$first['id']}/$action", $body, ['If-Match' => '"'.$first['version'].'"'] + $key);
            $code($this->as('mesero', 'POST', "reservations/{$second['id']}/$action", $body, ['If-Match' => '"'.$second['version'].'"'] + $key), 'IDEMPOTENCY_KEY_REUSED');
        };
        $reuse('confirm', $a, $b);
        $a = $this->as('mesero', 'GET', "reservations/{$a['id']}")->json('data');
        $b = $this->confirm($b);
        $key = $this->key();
        $this->as('mesero', 'PATCH', "reservations/{$a['id']}", ['notes' => 'Ventana'], ['If-Match' => '"'.$a['version'].'"'] + $key)->assertOk();
        $code($this->as('mesero', 'PATCH', "reservations/{$b['id']}", ['notes' => 'Ventana'], ['If-Match' => '"'.$b['version'].'"'] + $key), 'IDEMPOTENCY_KEY_REUSED');
        $a = $this->as('mesero', 'GET', "reservations/{$a['id']}")->json('data');
        $b = $this->as('mesero', 'GET', "reservations/{$b['id']}")->json('data');
        $this->clock('2026-11-20 13:50');
        $reuse('arrive', $a, $b);
        $a = $this->as('mesero', 'GET', "reservations/{$a['id']}")->json('data');
        $reuse('check-in', $a, $b, ['tableIds' => $this->tableIds(5)]);
        $c = $this->reserve(2, '18:00', '19:00');
        $d = $this->reserve(2, '18:00', '19:00');
        $reuse('cancel', $c, $d, ['reason' => 'Prueba de clave']);
        $this->clock('2026-11-20 10:00');
        $e = $this->confirm($this->reserve(2, '12:00', '13:00'));
        $f = $this->confirm($this->reserve(2, '12:00', '13:00'));
        $this->clock('2026-11-20 12:20');
        $reuse('no-show', $e, $f);

        // Versión vieja en cada transición.
        $g = $this->reserve(2, '20:00', '21:00');
        $code($this->as('mesero', 'PATCH', "reservations/{$g['id']}", ['notes' => 'x'], $stale($g)), 'VERSION_CONFLICT');
        foreach (['confirm', 'arrive', 'check-in', 'cancel', 'no-show'] as $action) {
            $body = ['check-in' => ['tableIds' => $this->tableIds(6)], 'cancel' => ['reason' => 'Prueba']][$action] ?? [];
            $code($this->as('mesero', 'POST', "reservations/{$g['id']}/$action", $body, $stale($g)), 'VERSION_CONFLICT');
        }

        // Estado que no permite la operación.
        $h = $this->act('mesero', 'cancel', $this->reserve(2, '20:00', '21:00'), ['reason' => 'Ya no viene'])->json('data');
        $code($this->as('mesero', 'PATCH', "reservations/{$h['id']}", ['notes' => 'x'], ['If-Match' => '"'.$h['version'].'"'] + $this->key()), 'RESERVATION_INVALID_STATE');
        $code($this->act('mesero', 'confirm', $h), 'RESERVATION_INVALID_STATE');
        $code($this->act('mesero', 'check-in', $h, ['tableIds' => $this->tableIds(6)]), 'RESERVATION_INVALID_STATE');
        $code($this->act('mesero', 'cancel', $h, ['reason' => 'Otra vez']), 'RESERVATION_INVALID_STATE');

        // Mesas que no sirven al sentar.
        $i = $this->act('mesero', 'arrive', $this->confirm($this->reserve(2, '12:30', '14:00')))->json('data');
        $this->table(6)->update(['status' => RestaurantTable::MAINTENANCE]);
        $code($this->act('mesero', 'check-in', $i, ['tableIds' => $this->tableIds(6)]), 'TABLE_NOT_AVAILABLE');
        $code($this->act('mesero', 'check-in', $i, ['tableIds' => $this->tableIds(2, 3)]), 'NO_TABLE_AVAILABLE');
        $code($this->act('mesero', 'check-in', $i, ['tableIds' => $this->tableIds(3), 'guestCount' => 6]), 'NO_TABLE_AVAILABLE');

        Branch::query()->where('_id', $this->branch->getKey())->update(['status' => Branch::INACTIVE]);
        $code($this->as('mesero', 'POST', 'reservations', ['startAt' => $this->at('18:00'), 'endAt' => $this->at('19:00'), 'partySize' => 2,
            'customer' => ['name' => 'Ana', 'phone' => '+523312345678']], $this->key()), 'BRANCH_INACTIVE');
    }
}
