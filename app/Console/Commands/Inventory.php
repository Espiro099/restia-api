<?php

namespace App\Console\Commands;

use App\Inventory\Projector;
use Illuminate\Console\Command;

/**
 * Proyección de stock (I-1): aplica a `stock_actual` los movimientos pendientes. Lo corre el scheduler
 * cada 5 segundos; con --rebuild recalcula el stock de cada ingrediente desde sus movimientos.
 */
class Inventory extends Command
{
    protected $signature = 'restia:inventory {--rebuild : Recalcula stock_actual desde inventory_movements}';

    protected $description = 'Aplica los movimientos de inventario pendientes a stock_actual';

    public function handle(): int
    {
        if ($this->option('rebuild')) {
            $this->line('ingredientes corregidos '.Projector::rebuild());

            return self::SUCCESS;
        }
        $done = Projector::run();
        $this->line("movimientos aplicados {$done['applied']}, alertas de stock bajo {$done['low']}");

        return self::SUCCESS;
    }
}
