<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Impresión: comandas desde outbox y plazos (PR-5, PR-9). Una comanda no debe esperar un minuto.
Schedule::command('restia:printing')->everyFiveSeconds()->withoutOverlapping();
