<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/**
 * Reserva (Decisiones_Reservations). Separada de la sesión: solo CONFIRMED y ARRIVED_WAITING apartan
 * mesas concretas (R-1). Guarda su propia copia del nombre y teléfono del cliente (R-11), que se borra a
 * los 12 meses o al anonimizar al cliente.
 */
class Reservation extends Document
{
    use HasVersion;

    public const PENDING = 'PENDING';

    public const CONFIRMED = 'CONFIRMED';

    public const ARRIVED_WAITING = 'ARRIVED_WAITING';

    public const CHECKED_IN = 'CHECKED_IN';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    public const NO_SHOW = 'NO_SHOW';

    /** Estados que apartan mesas (R-1). */
    public const HOLDING = [self::CONFIRMED, self::ARRIVED_WAITING];

    protected $table = 'reservations';

    protected $casts = [
        'branch_id' => ObjectId::class,
        'customer_id' => ObjectId::class,
        'session_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'arrival_registered_at' => 'datetime',
        'party_size' => 'integer',
    ];
}
