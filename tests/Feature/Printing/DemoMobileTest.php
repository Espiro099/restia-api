<?php

namespace Tests\Feature\Printing;

use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\PrintStation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Floor\FloorTestCase;

/** restia:demo-mobile deja la sucursal lista para la app Android y se puede repetir sin duplicar nada. */
class DemoMobileTest extends FloorTestCase
{
    private function demo(): string
    {
        $this->assertSame(0, Artisan::call('restia:demo-mobile', ['--branch' => 'CENTRO']));

        return Artisan::output();
    }

    public function test_abre_caja_configura_impresion_y_da_un_codigo_de_alta(): void
    {
        $this->staffMember('admin@ejemplo.mx', $this->branch, 'ADMIN');
        // Sin impresoras todavía: avisa, pero la caja y el código sí quedan.
        $this->assertStringContainsString('sin impresoras', $this->demo());
        $this->assertSame(1, CashShift::query()->where('status', CashShift::OPEN)->count());

        $this->assertSame(0, Artisan::call('restia:demo-printing', ['--branch' => 'CENTRO']));
        $output = $this->demo();
        $this->assertStringContainsString('ruta CAJA', $output);
        $this->assertStringContainsString('desde antes', $output);
        $this->assertSame(1, CashRegister::query()->count());
        $this->assertSame(1, CashShift::query()->count());
        $route = PrintStation::query()->where('code', 'CAJA')->first();
        $this->assertSame(['PRE_CHECK', 'PAYMENT_RECEIPT', 'CASH_SHIFT_REPORT'], array_values($route->job_types));

        // El código de la última línea da de alta un dispositivo.
        $code = trim(collect(explode("\n", trim($output)))->last());
        $this->postJson('/api/v1/auth/mobile/enroll', ['code' => $code, 'deviceUuid' => (string) Str::uuid(), 'displayName' => 'Emulador'])
            ->assertCreated();

        $this->assertStringContainsString('ya había una ruta', $this->demo());
        $this->assertSame(1, PrintStation::query()->where('code', 'CAJA')->count());
    }

    public function test_sin_administrador_no_hace_nada(): void
    {
        $this->assertSame(1, Artisan::call('restia:demo-mobile', ['--branch' => 'CENTRO']));
        $this->assertSame(0, CashRegister::query()->count());
    }
}
