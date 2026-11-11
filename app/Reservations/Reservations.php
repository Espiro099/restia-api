<?php

namespace App\Reservations;

use App\Customers\Customers;
use App\Exceptions\DomainConflict;
use App\Floor\TableSessions;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranchProfile;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\Idempotency;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Throwable;

/**
 * Reservas del personal (Decisiones_Reservations). Confirmar, editar y sentar escriben primero en
 * `branches.operations_epoch` y luego revisan disponibilidad, así que dos operaciones sobre la misma
 * sucursal chocan y la que reintenta ve a la otra (sección 5). Las de `reservation-ai` llegan con IA.
 */
final class Reservations
{
    /** Sin ambiguos (0/O, 1/I/L): el código se dicta por teléfono. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    /** R-5: abrir la mesa dentro de este plazo antes de su reserva exige confirmarlo. */
    public const RESERVED_SOON_MINUTES = 60;

    /** Retención de nombre y teléfono en la copia de la reserva (decidido el 2026-11-10). */
    public const RETENTION_MONTHS = 12;

    public static function present(Branch $branch, Reservation $r): array
    {
        $snapshot = (array) ($r->customer_snapshot ?? []);
        $tables = RestaurantTable::query()->whereIn('_id', array_map(fn ($id) => new ObjectId((string) $id), $r->planned_table_ids ?? []))->get()
            ->sortBy('number');
        $time = fn ($t) => $t ? Carbon::instance($t)->utc()->format('Y-m-d\TH:i:s\Z') : null;

        return [
            'id' => $r->uuid,
            'branchId' => $branch->uuid,
            'status' => $r->status,
            'startAt' => $time($r->start_at),
            'endAt' => $time($r->end_at),
            'partySize' => (int) $r->party_size,
            'customer' => [
                'customerId' => $r->customer_id ? Customer::query()->find((string) $r->customer_id)?->uuid : null,
                'name' => $snapshot['name'] ?? '',
                // Sin teléfono una vez borrados los datos personales (retención o anonimización).
                'phone' => $snapshot['phone'] ?? null,
            ],
            'source' => $r->source,
            'notes' => $r->notes,
            'confirmationCode' => $r->confirmation_code,
            'plannedTableIds' => $tables->pluck('uuid')->values()->all(),
            'planConflict' => (bool) $r->plan_conflict,
            'arrivalRegisteredAt' => $time($r->arrival_registered_at),
            'sessionId' => $r->session_id ? TableSession::query()->find((string) $r->session_id)?->uuid : null,
            'createdAt' => $time($r->created_at),
            'version' => (int) $r->version,
        ];
    }

    /** Fechas válidas y dentro del horario de reservas (R-3). @return array{0: Carbon, 1: Carbon} */
    public static function interval(Branch $branch, string $startAt, string $endAt): array
    {
        $start = Carbon::parse($startAt)->utc();
        $end = Carbon::parse($endAt)->utc();
        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['endAt' => 'Debe ser posterior a startAt.']);
        }
        if ($start->diffInHours($end) > 12) {
            throw ValidationException::withMessages(['endAt' => 'Una reserva no puede durar más de 12 horas.']);
        }
        if (! Availability::withinHours($branch, $start, $end)) {
            throw ValidationException::withMessages(['startAt' => 'Fuera del horario de reservas de la sucursal.']);
        }

        return [$start, $end];
    }

    public static function create(Branch $branch, User $actor, string $key, array $in, \Closure $present): array
    {
        if (! $branch->isActive()) {
            throw new DomainConflict('BRANCH_INACTIVE');
        }
        [$start, $end] = self::interval($branch, $in['startAt'], $in['endAt']);
        if ($start->isPast()) {
            throw ValidationException::withMessages(['startAt' => 'Debe ser una hora futura.']);
        }
        $customer = null;
        if (! empty($in['customer']['customerId'])) {
            // CU-2: el cliente debe tener perfil en la sucursal.
            [$customer] = rescue(fn () => Customers::find($branch, $in['customer']['customerId']), [null], false);
            if (! $customer) {
                throw ValidationException::withMessages(['customer.customerId' => 'El cliente no existe en esta sucursal.']);
            }
        }
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        return Idempotency::run($branchId, $key, $actorId, 'createReservation', Idempotency::hash('createReservation', $in),
            function () use ($branchId, $actorId, $in, $start, $end, $customer, $present) {
                $r = Reservation::query()->create([
                    'branch_id' => $branchId, 'status' => Reservation::PENDING,
                    'start_at' => $start, 'end_at' => $end, 'party_size' => (int) $in['partySize'],
                    'customer_id' => $customer ? new ObjectId($customer->getKey()) : null,
                    'customer_snapshot' => ['name' => $in['customer']['name'], 'phone' => $in['customer']['phone']],
                    'notes' => $in['notes'] ?? null, 'source' => 'STAFF', 'confirmation_code' => self::code($branchId),
                    'planned_table_ids' => [], 'plan_conflict' => false, 'arrival_registered_at' => null, 'session_id' => null,
                    'cancel_reason' => null, 'personal_data_removed_at' => null, 'created_by' => $actorId,
                ]);
                Audit::record('reservation.created', 'reservation', $r->getKey(), $branchId, $actorId,
                    changes: ['startAt' => $start->toIso8601ZuluString(), 'partySize' => (int) $in['partySize']]);
                Outbox::publish('reservation.created', 'reservation', $r->getKey(), $branchId, ['reservationId' => $r->uuid]);

                return [201, $present($r), $r->uuid];
            });
    }

    /**
     * PENDING o CONFIRMED. Si está CONFIRMED vuelve a planear: con las mesas indicadas, o con la mejor
     * opción. Si no hay lugar, conserva lo anterior y responde RESERVATION_NOT_AVAILABLE.
     */
    public static function update(Branch $branch, User $actor, Reservation $r, int $version, string $key, array $in, \Closure $present): array
    {
        return self::transition($branch, $actor, $r, $version, $key, 'updateReservation', $in, [Reservation::PENDING, Reservation::CONFIRMED],
            function (Reservation $fresh) use ($branch, $in) {
                $start = isset($in['startAt']) ? Carbon::parse($in['startAt']) : $fresh->start_at;
                $end = isset($in['endAt']) ? Carbon::parse($in['endAt']) : $fresh->end_at;
                if (isset($in['startAt']) || isset($in['endAt'])) {
                    [$start, $end] = self::interval($branch, $start->toIso8601String(), $end->toIso8601String());
                }
                $party = (int) ($in['partySize'] ?? $fresh->party_size);
                $changes = ['start_at' => $start, 'end_at' => $end, 'party_size' => $party];
                if (array_key_exists('notes', $in)) {
                    $changes['notes'] = $in['notes'];
                }
                if ($fresh->status === Reservation::CONFIRMED) {
                    $changes += self::plan($branch, $fresh, $start, $end, $party, $in['plannedTableIds'] ?? null);
                } elseif (isset($in['plannedTableIds'])) {
                    throw ValidationException::withMessages(['plannedTableIds' => 'Solo una reserva confirmada tiene mesas planeadas.']);
                }

                return [$changes, 'reservation.updated', ['partySize' => $party, 'startAt' => $start->toIso8601ZuluString()], true];
            }, $present);
    }

    /** PENDING → CONFIRMED con la mejor opción de mesas (R-1, R-2). */
    public static function confirm(Branch $branch, User $actor, Reservation $r, int $version, string $key, array $in, \Closure $present): array
    {
        return self::transition($branch, $actor, $r, $version, $key, 'confirmReservation', $in, [Reservation::PENDING],
            function (Reservation $fresh) use ($branch) {
                $changes = self::plan($branch, $fresh, $fresh->start_at, $fresh->end_at, (int) $fresh->party_size, null);

                return [$changes + ['status' => Reservation::CONFIRMED], 'reservation.confirmed', [], true];
            }, $present);
    }

    public static function arrive(Branch $branch, User $actor, Reservation $r, int $version, string $key, \Closure $present): array
    {
        return self::transition($branch, $actor, $r, $version, $key, 'arriveReservation', [], [Reservation::CONFIRMED],
            fn () => [['status' => Reservation::ARRIVED_WAITING, 'arrival_registered_at' => now()], 'reservation.arrived', [], false], $present);
    }

    public static function cancel(Branch $branch, User $actor, Reservation $r, int $version, string $key, array $in, \Closure $present): array
    {
        return self::transition($branch, $actor, $r, $version, $key, 'cancelReservation', $in, [Reservation::PENDING, Reservation::CONFIRMED, Reservation::ARRIVED_WAITING],
            fn () => [['status' => Reservation::CANCELLED, 'cancel_reason' => $in['reason'], 'planned_table_ids' => []], 'reservation.cancelled', ['reason' => $in['reason']], false], $present);
    }

    /** Solo CONFIRMED, sin llegada y pasada la tolerancia (R-6); ARRIVED_WAITING nunca es NO_SHOW (D-18). */
    public static function noShow(Branch $branch, User $actor, Reservation $r, int $version, string $key, \Closure $present): array
    {
        return self::transition($branch, $actor, $r, $version, $key, 'markNoShow', [], [Reservation::CONFIRMED],
            function (Reservation $fresh) use ($branch) {
                if (now()->lessThan($fresh->start_at->copy()->addMinutes(self::tolerance($branch)))) {
                    throw new DomainConflict('RESERVATION_INVALID_STATE', ['noShowAllowedAt' => $fresh->start_at->copy()->addMinutes(self::tolerance($branch))->toIso8601ZuluString()]);
                }

                return [['status' => Reservation::NO_SHOW, 'planned_table_ids' => []], 'reservation.no_show', [], false];
            }, $present);
    }

    /**
     * Sienta a la reserva: abre la sesión en la misma transacción. La espera tiene prioridad (R-8): si
     * otra reserva llegó antes y cabe en estas mesas, saltarla exige `reservations.override_priority`.
     */
    public static function checkIn(Branch $branch, User $actor, bool $canOverride, Reservation $r, int $version, string $key, array $in, \Closure $present): array
    {
        if (($in['overridePriority'] ?? false) && ! $canOverride) {
            throw new DomainConflict('FORBIDDEN');
        }
        if (($in['overridePriority'] ?? false) && empty($in['overrideReason'])) {
            throw ValidationException::withMessages(['overrideReason' => 'Indica por qué se salta la prioridad.']);
        }

        try {
            return self::transition($branch, $actor, $r, $version, $key, 'checkInReservation', $in, [Reservation::CONFIRMED, Reservation::ARRIVED_WAITING],
                function (Reservation $fresh) use ($branch, $actor, $in) {
                    $branchId = new ObjectId($branch->getKey());
                    $tables = RestaurantTable::query()->where('branch_id', $branchId)->whereIn('uuid', array_map('strtolower', $in['tableIds']))->get();
                    if ($tables->count() !== count($in['tableIds']) || $tables->contains(fn ($t) => $t->status !== RestaurantTable::ACTIVE)) {
                        throw new DomainConflict('TABLE_NOT_AVAILABLE');
                    }
                    if (TableAssignment::query()->where('branch_id', $branchId)->where('active', true)
                        ->whereIn('table_id', $tables->map(fn ($t) => new ObjectId($t->getKey()))->all())->exists()) {
                        throw new DomainConflict('TABLE_ALREADY_ASSIGNED');
                    }
                    $guests = (int) ($in['guestCount'] ?? $fresh->party_size);
                    if ($tables->count() > 1 && ($tables->pluck('combination_group')->unique()->count() > 1 || $tables->contains(fn ($t) => ! $t->combination_group))) {
                        throw new DomainConflict('NO_TABLE_AVAILABLE');
                    }
                    if ((int) $tables->sum('capacity') < $guests) {
                        throw new DomainConflict('NO_TABLE_AVAILABLE');
                    }
                    $ahead = self::aheadInQueue($branch, $fresh, $tables);
                    if ($ahead && ! ($in['overridePriority'] ?? false)) {
                        throw new DomainConflict('RESERVATION_PRIORITY_CONFLICT', ['reservationId' => $ahead->uuid]);
                    }
                    $session = TableSessions::seat($branch, new ObjectId($actor->getKey()), $tables, $guests, new ObjectId($actor->getKey()),
                        new ObjectId($fresh->getKey()), ['reservation' => $fresh->uuid]);
                    if ($fresh->customer_id) {
                        CustomerBranchProfile::query()->where('branch_id', $branchId)->where('customer_id', new ObjectId((string) $fresh->customer_id))
                            ->increment('visit_count', 1, ['last_visit_at' => now()]);
                    }
                    $audit = ['tables' => $tables->pluck('number')->sort()->values()->all()];
                    if ($ahead) {
                        $audit += ['overridePriority' => $ahead->uuid, 'overrideReason' => $in['overrideReason']];
                    }

                    return [[
                        'status' => Reservation::CHECKED_IN, 'session_id' => new ObjectId($session->getKey()),
                        'planned_table_ids' => $tables->map(fn ($t) => new ObjectId($t->getKey()))->values()->all(), 'plan_conflict' => false,
                    ], 'reservation.checked_in', $audit, true];
                }, $present);
        } catch (Throwable $e) {
            // Otra apertura tomó la mesa entre la revisión y la escritura.
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('TABLE_ALREADY_ASSIGNED') : $e;
        }
    }

    /** ARRIVED_WAITING por orden de llegada (R-8), cada una con las opciones que tiene ahora. */
    public static function queue(Branch $branch): array
    {
        $rows = Reservation::query()->forBranch($branch)->where('status', Reservation::ARRIVED_WAITING)
            ->orderBy('arrival_registered_at')->orderBy('_id')->get();

        return $rows->values()->map(fn (Reservation $r, int $i) => [
            'position' => $i + 1,
            'reservation' => self::present($branch, $r),
            'tableOptions' => self::presentOptions(self::seatableNow($branch, $r)),
        ])->all();
    }

    /** POST para que el teléfono no viaje en la URL. Un intento fallido queda auditado, sin el teléfono. */
    public static function lookup(Branch $branch, User $actor, string $code, string $phone): ?Reservation
    {
        $r = Reservation::query()->forBranch($branch)->where('confirmation_code', strtoupper(trim($code)))->first();
        $ok = $r && (((array) $r->customer_snapshot)['phone'] ?? null) === $phone;
        if (! $ok) {
            Audit::record('reservation.lookup_failed', 'branch', $branch->getKey(), $branch->getKey(), new ObjectId($actor->getKey()));
        }

        return $ok ? $r : null;
    }

    /** @param list<array{tables: Collection, capacity: int}> $options */
    public static function presentOptions(array $options, int $limit = 5): array
    {
        return array_map(fn ($o) => ['tableIds' => $o['tables']->pluck('uuid')->values()->all(), 'totalCapacity' => $o['capacity']],
            array_slice($options, 0, $limit));
    }

    // --- Mesas y sesiones ---

    /**
     * R-5 y sección 3: antes de abrir una mesa sin reserva. Si alguna está planeada para una reserva que
     * empieza en menos de 60 minutos, pide confirmarlo; confirmado, o si empieza entre 60 y 90 minutos,
     * esa reserva se replanea sin estas mesas o queda con planConflict.
     *
     * @param  Collection<int, RestaurantTable>  $tables
     */
    public static function beforeWalkIn(Branch $branch, Collection $tables, bool $acknowledged): void
    {
        $ids = $tables->map(fn ($t) => (string) $t->getKey())->all();
        $affected = Reservation::query()->forBranch($branch)->whereIn('status', Reservation::HOLDING)
            ->where('start_at', '<=', now()->addMinutes(Availability::OCCUPIED_WINDOW_MINUTES))->where('end_at', '>', now())
            ->whereIn('planned_table_ids', array_map(fn ($id) => new ObjectId($id), $ids))->orderBy('start_at')->get();
        foreach ($affected as $r) {
            if (! $acknowledged && $r->start_at->lessThanOrEqualTo(now()->addMinutes(self::RESERVED_SOON_MINUTES))) {
                throw new DomainConflict('TABLE_RESERVED_SOON', ['reservationStartAt' => $r->start_at->copy()->utc()->format('Y-m-d\TH:i:s\Z')]);
            }
        }
        foreach ($affected as $r) {
            self::replan($branch, $r, $ids);
        }
    }

    /** R-9: la sesión vinculada pasa a limpieza. */
    public static function sessionClosing(TableSession $session): void
    {
        self::followSession($session, Reservation::COMPLETED, 'reservation.completed');
    }

    /** R-9: la sesión vinculada se canceló; la reserva vuelve a esperar. */
    public static function sessionCancelled(TableSession $session): void
    {
        self::followSession($session, Reservation::ARRIVED_WAITING, 'reservation.returned_to_queue');
    }

    // --- Tareas del sistema ---

    /** R-7: una PENDING sin confirmar se cancela al llegar su hora. */
    public static function expirePending(): int
    {
        $count = 0;
        foreach (Reservation::query()->where('status', Reservation::PENDING)->where('start_at', '<=', now())->get() as $r) {
            $count += self::system($r, Reservation::PENDING, ['status' => Reservation::CANCELLED, 'cancel_reason' => 'EXPIRED'], 'reservation.expired');
        }

        return $count;
    }

    /** R-6: automático, pasada la tolerancia de la sucursal y sin llegada. */
    public static function markNoShows(): int
    {
        $count = 0;
        foreach (Reservation::query()->where('status', Reservation::CONFIRMED)->where('start_at', '<=', now())->get() as $r) {
            $branch = Branch::query()->find((string) $r->branch_id);
            if ($branch && now()->greaterThanOrEqualTo($r->start_at->copy()->addMinutes(self::tolerance($branch)))) {
                $count += self::system($r, Reservation::CONFIRMED, ['status' => Reservation::NO_SHOW, 'planned_table_ids' => []], 'reservation.no_show');
            }
        }

        return $count;
    }

    /** Retención: a los 12 meses del fin de la reserva se borran el nombre, el teléfono y las notas. */
    public static function purgePersonalData(): int
    {
        return Customers::anonymizeReservations(['end_at' => ['<', new UTCDateTime(now()->subMonths(self::RETENTION_MONTHS))]]);
    }

    // --- Internos ---

    /**
     * Esqueleto de cada cambio: Idempotency-Key, If-Match, estado permitido, epoch de la sucursal si la
     * operación aparta mesas, auditoría y outbox, en una transacción. $work devuelve
     * [cambios, evento, detalle de auditoría, ¿escribe el epoch?].
     */
    private static function transition(Branch $branch, User $actor, Reservation $r, int $version, string $key, string $operation, array $in,
        array $allowed, \Closure $work, \Closure $present): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        return Idempotency::run($branchId, $key, $actorId, $operation, Idempotency::hash($operation, $in + ['reservation' => $r->uuid, 'version' => $version]),
            function () use ($branchId, $actorId, $r, $version, $allowed, $work, $present) {
                Branch::query()->where('_id', $branchId)->increment('operations_epoch');
                if (! Branch::query()->find($branchId)?->isActive()) {
                    throw new DomainConflict('BRANCH_INACTIVE');
                }
                $fresh = Reservation::query()->find($r->getKey());
                if ((int) $fresh->version !== $version) {
                    throw new DomainConflict('VERSION_CONFLICT');
                }
                if (! in_array($fresh->status, $allowed, true)) {
                    throw new DomainConflict('RESERVATION_INVALID_STATE');
                }
                [$changes, $event, $audit] = $work($fresh);
                if (! $fresh->updateIfVersion($version, $changes)) {
                    throw new DomainConflict('VERSION_CONFLICT');
                }
                Audit::record($event, 'reservation', $fresh->getKey(), $branchId, $actorId, reason: $changes['cancel_reason'] ?? null, changes: $audit);
                Outbox::publish($event, 'reservation', $fresh->getKey(), $branchId, ['reservationId' => $fresh->uuid]);

                return [200, $present($fresh), $fresh->uuid];
            });
    }

    /** Mesas para una reserva confirmada. Con mesas pedidas, esas o nada; si no, la mejor opción. */
    private static function plan(Branch $branch, Reservation $r, Carbon $start, Carbon $end, int $party, ?array $tableUuids): array
    {
        if ($tableUuids !== null) {
            $tables = RestaurantTable::query()->forBranch($branch)->whereIn('uuid', array_map('strtolower', $tableUuids))->get();
            if ($tables->count() !== count($tableUuids) || ! Availability::fits($branch, $start, $end, $party, $tables->map(fn ($t) => $t->getKey())->all(), (string) $r->getKey())) {
                throw new DomainConflict('NO_TABLE_AVAILABLE');
            }
        } else {
            $option = Availability::options($branch, $start, $end, $party, (string) $r->getKey())[0] ?? null;
            if (! $option) {
                throw new DomainConflict('RESERVATION_NOT_AVAILABLE');
            }
            $tables = $option['tables'];
        }

        return ['planned_table_ids' => $tables->map(fn ($t) => new ObjectId($t->getKey()))->values()->all(), 'plan_conflict' => false];
    }

    /** Sección 3: busca otra opción sin las mesas dadas; si no hay, la marca para que el personal la resuelva. */
    private static function replan(Branch $branch, Reservation $r, array $withoutTables): void
    {
        $option = Availability::options($branch, $r->start_at, $r->end_at, (int) $r->party_size, (string) $r->getKey(), $withoutTables)[0] ?? null;
        $changes = $option
            ? ['planned_table_ids' => $option['tables']->map(fn ($t) => new ObjectId($t->getKey()))->values()->all(), 'plan_conflict' => false]
            : ['plan_conflict' => true];
        Reservation::query()->where('_id', new ObjectId($r->getKey()))->increment('version', 1, $changes + ['updated_at' => now()]);
        Audit::record('reservation.replanned', 'reservation', $r->getKey(), (string) $r->branch_id, changes: ['planConflict' => ! $option]);
        Outbox::publish('reservation.replanned', 'reservation', $r->getKey(), (string) $r->branch_id, ['reservationId' => $r->uuid]);
    }

    /** Primera reserva en espera, llegada antes que esta, que cabría en estas mesas (R-8). */
    private static function aheadInQueue(Branch $branch, Reservation $r, Collection $tables): ?Reservation
    {
        $capacity = (int) $tables->sum('capacity');
        $query = Reservation::query()->forBranch($branch)->where('status', Reservation::ARRIVED_WAITING)->where('_id', '!=', new ObjectId($r->getKey()));
        if ($r->arrival_registered_at) {
            $query->where('arrival_registered_at', '<', $r->arrival_registered_at);
        }

        return $query->orderBy('arrival_registered_at')->get()->first(fn ($w) => (int) $w->party_size <= $capacity);
    }

    /** Opciones para sentar ya: mesas libres ahora y hasta su hora de fin. */
    private static function seatableNow(Branch $branch, Reservation $r): array
    {
        $start = now();
        $end = $r->end_at->greaterThan($start) ? $r->end_at : $start->copy()->addHours(2);

        return Availability::options(self::alwaysOpen($branch), $start, $end, (int) $r->party_size, (string) $r->getKey());
    }

    /** Para sentar a quien ya llegó no importa el horario de reservas. */
    private static function alwaysOpen(Branch $branch): Branch
    {
        $copy = clone $branch;
        $copy->reservation_hours = array_map(fn ($d) => ['dayOfWeek' => $d, 'opensAt' => '00:00', 'closesAt' => '00:00'], range(1, 7));

        return $copy;
    }

    private static function followSession(TableSession $session, string $status, string $event): void
    {
        if (! $session->reservation_id) {
            return;
        }
        $r = Reservation::query()->find((string) $session->reservation_id);
        if (! $r || $r->status !== Reservation::CHECKED_IN) {
            return;
        }
        $changes = ['status' => $status] + ($status === Reservation::ARRIVED_WAITING ? ['session_id' => null] : []);
        Reservation::query()->where('_id', new ObjectId($r->getKey()))->increment('version', 1, $changes + ['updated_at' => now()]);
        Audit::record($event, 'reservation', $r->getKey(), (string) $r->branch_id, changes: ['session' => $session->uuid]);
        Outbox::publish($event, 'reservation', $r->getKey(), (string) $r->branch_id, ['reservationId' => $r->uuid]);
    }

    /** Cambio del sistema, solo si la reserva sigue en el estado esperado. */
    private static function system(Reservation $r, string $from, array $changes, string $event): int
    {
        return Mongo::transaction(function () use ($r, $from, $changes, $event) {
            $modified = Reservation::query()->where('_id', new ObjectId($r->getKey()))->where('status', $from)
                ->increment('version', 1, $changes + ['updated_at' => now()]);
            if ($modified) {
                Audit::record($event, 'reservation', $r->getKey(), (string) $r->branch_id, reason: $changes['cancel_reason'] ?? null);
                Outbox::publish($event, 'reservation', $r->getKey(), (string) $r->branch_id, ['reservationId' => $r->uuid]);
            }

            return $modified;
        });
    }

    private static function tolerance(Branch $branch): int
    {
        return (int) ($branch->reservation_no_show_tolerance_minutes ?? 15);
    }

    private static function code(ObjectId $branchId): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Reservation::query()->where('branch_id', $branchId)->where('confirmation_code', $code)->exists());

        return $code;
    }
}
