<?php

namespace App\Console\Commands;

use App\Fiscal\Stamping;
use Illuminate\Console\Command;

/**
 * Proceso interno fiscal: timbra lo pendiente, pasa a UNKNOWN lo que quedó sin respuesta y atiende
 * las reconciliaciones. Lo corre el scheduler cada 5 segundos (`php artisan schedule:work` en local).
 */
class Fiscal extends Command
{
    protected $signature = 'restia:fiscal';

    protected $description = 'Timbra facturas pendientes, vence plazos y reconcilia las que quedaron en UNKNOWN';

    public function handle(): int
    {
        $c = Stamping::run();
        $this->line(sprintf('timbradas %d, rechazadas %d, desconocidas %d, plazos vencidos %d, reconciliadas %d',
            $c['stamped'], $c['failed'], $c['unknown'], $c['expired'], $c['reconciled']));

        return self::SUCCESS;
    }
}
