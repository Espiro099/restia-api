<?php

use App\Cash\Cash;
use App\Payments\Payments;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Impresión: comandas desde outbox y plazos (PR-5, PR-9). Una comanda no debe esperar un minuto.
Schedule::command('restia:printing')->everyFiveSeconds()->withoutOverlapping();

// Inventario: stock_actual se actualiza segundos después de cada movimiento (I-1).
Schedule::command('restia:inventory')->everyFiveSeconds()->withoutOverlapping();

// Reportes: se actualizan desde outbox y atienden reconstrucciones (RP-3). Unos segundos de retraso bastan.
Schedule::command('restia:reports')->everyFifteenSeconds()->withoutOverlapping();

// Reservas: vencer PENDING (R-7), NO_SHOW automático (R-6) y borrar datos personales a los 12 meses.
Schedule::command('restia:reservations')->everyMinute()->withoutOverlapping();

// Caja: un turno abierto más de 24 horas genera una alerta (CA-11).
Schedule::call(fn () => Cash::forgotten())->name('cash-forgotten-shifts')->everyFifteenMinutes()->withoutOverlapping();

// Pagos: un PENDING de más de 15 minutos pasa a UNKNOWN (P-4).
Schedule::call(fn () => Payments::expire())->name('payments-expire-pending')->everyMinute()->withoutOverlapping();
