<?php

namespace App\Console\Commands;

use App\Notifications\Notifications;
use Illuminate\Console\Command;

/** Crea los avisos de la campana desde outbox. Lo corre el scheduler cada 5 segundos. */
class NotificationsCommand extends Command
{
    protected $signature = 'restia:notifications';

    protected $description = 'Crea los avisos de los empleados desde outbox';

    public function handle(): int
    {
        $this->line(sprintf('eventos %d', Notifications::run()));

        return self::SUCCESS;
    }
}
