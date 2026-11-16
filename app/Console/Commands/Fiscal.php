<?php

namespace App\Console\Commands;

use App\Fiscal\Cancelling;
use App\Fiscal\Stamping;
use Illuminate\Console\Command;

/**
 * Proceso interno fiscal: timbra lo pendiente, pasa a UNKNOWN lo que quedó sin respuesta, atiende las
 * reconciliaciones y las cancelaciones. Lo corre el scheduler cada 5 segundos (`php artisan
 * schedule:work` en local).
 */
class Fiscal extends Command
{
    protected $signature = 'restia:fiscal';

    protected $description = 'Timbra y cancela facturas pendientes, vence plazos y reconcilia las que quedaron en UNKNOWN';

    public function handle(): int
    {
        $c = Stamping::run();
        $this->line(sprintf('timbradas %d, rechazadas %d, desconocidas %d, plazos vencidos %d, reconciliadas %d',
            $c['stamped'], $c['failed'], $c['unknown'], $c['expired'], $c['reconciled']));
        $c = Cancelling::run();
        $this->line(sprintf('canceladas %d, en espera del receptor %d, cancelaciones rechazadas %d, por reintentar %d',
            $c['cancelled'], $c['in_progress'], $c['rejected'], $c['retry']));

        return self::SUCCESS;
    }
}
