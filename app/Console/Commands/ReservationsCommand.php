<?php

namespace App\Console\Commands;

use App\Reservations\Reservations;
use Illuminate\Console\Command;

/** Tareas automáticas de reservas: vencer PENDING (R-7), NO_SHOW (R-6) y borrar datos personales a los 12 meses. */
class ReservationsCommand extends Command
{
    protected $signature = 'restia:reservations';

    protected $description = 'Vence reservas sin confirmar, marca NO_SHOW y borra datos personales vencidos';

    public function handle(): int
    {
        $expired = Reservations::expirePending();
        $noShows = Reservations::markNoShows();
        $purged = Reservations::purgePersonalData();
        $this->line("vencidas $expired, no presentadas $noShows, datos borrados $purged");

        return self::SUCCESS;
    }
}
