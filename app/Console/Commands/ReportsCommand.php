<?php

namespace App\Console\Commands;

use App\Reports\Reports;
use Illuminate\Console\Command;

/** Mantiene los reportes al día desde outbox y atiende una reconstrucción pendiente (RP-3). */
class ReportsCommand extends Command
{
    protected $signature = 'restia:reports';

    protected $description = 'Actualiza los reportes desde outbox y ejecuta una reconstrucción pendiente';

    public function handle(): int
    {
        $events = Reports::consume();
        $job = Reports::rebuildNext();
        $this->line("eventos $events".($job ? ", reconstrucción {$job->from} a {$job->to}: {$job->status}" : ''));

        return self::SUCCESS;
    }
}
