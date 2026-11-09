<?php

namespace App\Console\Commands;

use App\Printing\Dispatcher;
use App\Printing\Sweeper;
use Illuminate\Console\Command;

/**
 * Proceso interno de impresión: crea los trabajos automáticos desde outbox y vence los plazos.
 * Lo corre el scheduler cada 5 segundos (`php artisan schedule:work` en local).
 */
class Printing extends Command
{
    protected $signature = 'restia:printing';

    protected $description = 'Crea comandas y avisos desde outbox y vence los plazos de impresión';

    public function handle(): int
    {
        $events = Dispatcher::run();
        $swept = Sweeper::run();
        $this->line(sprintf('eventos %d, liberados %d, desconocidos %d, vencidos %d, agentes sin latido %d',
            $events, $swept['released'], $swept['unknown'], $swept['expired'], $swept['offline']));

        return self::SUCCESS;
    }
}
