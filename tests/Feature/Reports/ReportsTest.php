<?php

namespace Tests\Feature\Reports;

use App\Support\Floor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Floor\FloorTestCase;

/** RP-1 a RP-3: ventas diarias y ocupación por hora, desde outbox y reconstruibles. */
class ReportsTest extends FloorTestCase
{
    private function today(): string
    {
        return Floor::businessDate($this->branch);
    }

    private function range(): string
    {
        return 'from='.$this->today().'&to='.$this->today();
    }

    private function reports(): void
    {
        $this->artisan('restia:reports')->assertSuccessful();
    }

    /** Una mesa: comanda de 207.00, una cortesía de 50.00 y un pago con tarjeta de 157.00 más 20.00 de propina. */
    private function service(): array
    {
        $session = $this->openSession(1);
        $order = $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated()->json('data');
        $check = $this->as('mesero', 'GET', "checks?sessionId=$session")->json('data.0');
        $unit = $order['items'][0]['units'][0]['id'];
        $this->as('gerente', 'POST', "checks/{$check['id']}/adjustments", ['type' => 'COURTESY', 'unitId' => $unit, 'reason' => 'Cliente frecuente'],
            ['If-Match' => '"'.$check['version'].'"'] + $this->key())->assertCreated();
        $check = $this->as('mesero', 'GET', "checks/{$check['id']}")->json('data');
        $payment = $this->as('mesero', 'POST', 'payments', [
            'checkId' => $check['id'], 'cashShiftId' => $this->cashShift(), 'method' => 'CARD_DEBIT',
            'amount' => $check['balance'], 'tip' => ['amount' => '20.00', 'currency' => 'MXN'],
        ], $this->key())->assertCreated()->json('data');
        $this->as('mesero', 'POST', "payments/{$payment['id']}/result", ['outcome' => 'CONFIRMED', 'reference' => 'T-1'],
            ['If-Match' => '"1"'] + $this->key())->assertOk();

        return [$session, $check, $payment];
    }

    public function test_ventas_y_ocupacion_del_dia_desde_outbox(): void
    {
        $this->as('mesero', 'GET', 'reports/daily-sales?'.$this->range())->assertStatus(403);
        $this->as('gerente', 'GET', 'reports/daily-sales')->assertStatus(422);
        $this->as('gerente', 'GET', 'reports/daily-sales?from=2026-01-01&to=2026-06-30')->assertStatus(422);
        $this->as('gerente', 'GET', 'reports/daily-sales?from=2026-02-01&to=2026-01-01')->assertStatus(422);

        [, $check] = $this->service();
        // Sin procesar outbox todavía no hay nada: el reporte es asíncrono.
        $this->as('gerente', 'GET', 'reports/daily-sales?'.$this->range())->assertOk()->assertJsonCount(0, 'data');
        $this->reports();

        $day = $this->as('gerente', 'GET', 'reports/daily-sales?'.$this->range())->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame($this->today(), $day['businessDate']);
        $this->assertSame($check['balance']['amount'], $day['netSales']['amount']);
        $this->assertSame('20.00', $day['tips']['amount']);
        $this->assertSame('50.00', $day['courtesies']['amount']);
        $this->assertSame('0.00', $day['refunds']['amount']);
        $this->assertSame('0.00', $day['invoiced']['amount']);
        $this->assertSame(1, $day['checkCount']);
        $this->assertSame(2, $day['guestCount']);
        $debit = collect($day['byMethod'])->firstWhere('method', 'CARD_DEBIT');
        $this->assertSame($check['balance']['amount'], $debit['sales']['amount']);
        $this->assertSame('0.00', collect($day['byMethod'])->firstWhere('method', 'CASH')['sales']['amount']);
        $this->assertNotEmpty($day['asOf']);

        $hours = $this->as('gerente', 'GET', 'reports/hourly-occupancy?'.$this->range())->assertOk()->json('data');
        $this->assertNotEmpty($hours);
        $this->assertSame(1, array_sum(array_column($hours, 'sessionsOpened')));
        $this->assertSame(1, $hours[0]['tablesOccupied']);
        $this->assertSame(2, $hours[0]['guestCount']);
        $localHour = (int) now()->setTimezone($this->branch->timezone)->format('G');
        $this->assertSame($localHour, $hours[0]['hour']);

        // Volver a correr no repite eventos ni cambia el resultado.
        $this->artisan('restia:reports')->expectsOutputToContain('eventos 0')->assertSuccessful();
        $this->assertSame($day['netSales'], $this->as('gerente', 'GET', 'reports/daily-sales?'.$this->range())->json('data.0.netSales'));
    }

    public function test_un_reembolso_resta_de_la_venta_del_dia(): void
    {
        $session = $this->openSession(2);
        $this->as('mesero', 'POST', 'orders', $this->round($session), $this->key())->assertCreated();
        $this->payAll($session);
        $payment = $this->as('gerente', 'GET', 'payments?status=CONFIRMED')->json('data.0');
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
        $this->as('admin', 'POST', "payments/{$payment['id']}/refunds", ['cashShiftId' => $this->cashShift(), 'reason' => 'Cobro duplicado'], $this->key())
            ->assertCreated();
        $this->reports();

        $day = $this->as('gerente', 'GET', 'reports/daily-sales?'.$this->range())->json('data.0');
        $this->assertSame('0.00', $day['netSales']['amount']);
        $this->assertSame('207.00', $day['refunds']['amount']);
        $cash = collect($day['byMethod'])->firstWhere('method', 'CASH');
        $this->assertSame('207.00', $cash['sales']['amount']);
        $this->assertSame('207.00', $cash['refunds']['amount']);
        $this->assertSame(0, $day['checkCount']);
    }

    public function test_reconstruccion_solo_admin_y_una_a_la_vez(): void
    {
        $this->service();
        $this->reports();
        $this->staff['admin'] = $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
        // Se pierde la proyección; la reconstrucción la recupera desde los datos de origen.
        DB::connection('mongodb')->table('daily_sales')->delete();
        DB::connection('mongodb')->table('hourly_occupancy')->delete();

        $body = ['from' => $this->today(), 'to' => $this->today()];
        $this->as('gerente', 'POST', 'reports/rebuilds', $body, $this->key())->assertStatus(403);
        $this->as('admin', 'POST', 'reports/rebuilds', $body)->assertStatus(422);
        $key = $this->key();
        $job = $this->as('admin', 'POST', 'reports/rebuilds', $body, $key)->assertStatus(202)
            ->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.finishedAt', null)->json('data');
        $this->as('admin', 'POST', 'reports/rebuilds', $body, $key)->assertStatus(202)->assertJsonPath('data.id', $job['id']);
        $this->as('admin', 'POST', 'reports/rebuilds', $body, $this->key())->assertStatus(409)->assertJsonPath('error.code', 'REPORT_REBUILD_IN_PROGRESS');

        $this->reports();
        $this->as('admin', 'GET', "reports/rebuilds/{$job['id']}")->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->assertNotNull($this->as('admin', 'GET', "reports/rebuilds/{$job['id']}")->json('data.finishedAt'));
        $this->as('gerente', 'GET', 'reports/daily-sales?'.$this->range())->assertJsonCount(1, 'data')->assertJsonPath('data.0.tips.amount', '20.00');
        $this->assertNotEmpty($this->as('gerente', 'GET', 'reports/hourly-occupancy?'.$this->range())->json('data'));

        // Terminada, ya se puede pedir otra.
        $this->as('admin', 'POST', 'reports/rebuilds', $body, $this->key())->assertStatus(202);
        $this->as('admin', 'GET', 'reports/rebuilds/'.Str::uuid())->assertStatus(404);
    }
}
