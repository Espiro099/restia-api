<?php

namespace App\Floor;

use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\TableSession;
use App\Models\User;
use App\Reservations\Reservations;
use App\Support\Audit;
use App\Support\Floor;
use App\Support\Idempotency;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/**
 * Apertura de sesiones (T-1, T-7, T-9) según el caso completo de docs/Guia_Base_de_Datos_MongoDB.md.
 * El resto del ciclo de vida está en SessionLifecycle.
 */
final class TableSessions
{
    /**
     * @param  array{tableIds: list<string>, guestCount: int, responsibleUserId?: string, acknowledgeReservation?: bool}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function open(Branch $branch, User $actor, string $key, array $in): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        $tableUuids = array_map('strtolower', $in['tableIds']);
        $responsible = isset($in['responsibleUserId']) ? self::responsible($branchId, strtolower($in['responsibleUserId'])) : $actorId;
        $hash = Idempotency::hash('openTableSession', $in);

        try {
            return Idempotency::run($branchId, $key, $actorId, 'openTableSession', $hash, function () use ($branch, $branchId, $actorId, $tableUuids, $responsible, $in) {
                // Primero el epoch: choca con desactivar la sucursal o cambiar el estado de una mesa (T-9).
                Branch::query()->where('_id', $branchId)->increment('operations_epoch');
                $current = Branch::query()->find($branchId);
                if (! $current?->isActive()) {
                    throw new DomainConflict('BRANCH_INACTIVE');
                }

                $tables = RestaurantTable::query()->where('branch_id', $branchId)->whereIn('uuid', $tableUuids)->get();
                if ($tables->count() !== count($tableUuids) || $tables->contains(fn ($t) => $t->status !== RestaurantTable::ACTIVE)) {
                    throw new DomainConflict('TABLE_NOT_AVAILABLE');
                }
                $tableIds = $tables->map(fn ($t) => new ObjectId($t->getKey()))->all();
                if (TableAssignment::query()->where('branch_id', $branchId)->where('active', true)->whereIn('table_id', $tableIds)->exists()) {
                    throw new DomainConflict('TABLE_ALREADY_ASSIGNED');
                }

                // R-5: una mesa con reserva próxima se abre solo con confirmación explícita, que queda auditada.
                Reservations::beforeWalkIn($current, $tables, (bool) ($in['acknowledgeReservation'] ?? false));
                $session = self::seat($current, $actorId, $tables, (int) $in['guestCount'], $responsible, null, [
                    'acknowledgeReservation' => (bool) ($in['acknowledgeReservation'] ?? false),
                ]);

                return [201, self::present($branch, $session), $session->uuid];
            });
        } catch (\Throwable $e) {
            // Dos aperturas sobre la misma mesa, ya repetida una vez: la otra ganó.
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('TABLE_ALREADY_ASSIGNED') : $e;
        }
    }

    /**
     * Crea la sesión y sus asignaciones, con auditoría y outbox. La llaman la apertura normal y el check-in
     * de una reserva, siempre dentro de su transacción y después de validar las mesas.
     *
     * @param  Collection<int, RestaurantTable>  $tables
     */
    public static function seat(Branch $branch, ObjectId $actorId, Collection $tables, int $guestCount, ObjectId $responsible, ?ObjectId $reservationId, array $audit = []): TableSession
    {
        $branchId = new ObjectId($branch->getKey());
        $now = now();
        $session = TableSession::query()->create([
            'branch_id' => $branchId,
            'status' => TableSession::ACTIVE,
            'guest_count' => $guestCount,
            'responsible_user_id' => $responsible,
            'reservation_id' => $reservationId,
            'membership_version' => 1,
            'activity_epoch' => 0,
            'business_date' => Floor::businessDate($branch, $now),
            'opened_by' => $actorId,
            'opened_at' => $now,
            'close_requested_at' => null,
            'closed_at' => null,
        ]);
        // El índice parcial deja pasar una sola asignación activa por mesa.
        foreach ($tables->sortBy('number') as $table) {
            TableAssignment::query()->create([
                'branch_id' => $branchId,
                'table_id' => new ObjectId($table->getKey()),
                'session_id' => new ObjectId($session->getKey()),
                'phase' => 'SEATED',
                'active' => true,
                'assigned_at' => $now,
            ]);
        }
        Audit::record('table_session.opened', 'table_session', $session->getKey(), $branchId, $actorId, changes: [
            'tables' => $tables->pluck('number')->sort()->values()->all(),
            'guestCount' => $guestCount,
        ] + $audit);
        Outbox::publish('table_session.opened', 'table_session', $session->getKey(), $branchId, ['sessionId' => $session->uuid]);

        return $session;
    }

    /** @return array<string, mixed> */
    public static function present(Branch $branch, TableSession $s): array
    {
        $assignments = TableAssignment::query()->where('session_id', new ObjectId($s->getKey()))->get();
        $active = $assignments->where('active', true);
        $shown = $active->isNotEmpty() ? $active : $assignments;
        $tables = RestaurantTable::query()->whereIn('_id', $shown->map(fn ($a) => new ObjectId((string) $a->table_id))->all())->get()->keyBy(fn ($t) => (string) $t->getKey());

        return [
            'id' => $s->uuid,
            'branchId' => $branch->uuid,
            'status' => $s->status,
            'tables' => self::tables($shown, $tables),
            'guestCount' => (int) $s->guest_count,
            'responsibleUserId' => User::query()->find((string) $s->responsible_user_id)?->uuid,
            'reservationId' => $s->reservation_id ? Reservation::query()->find((string) $s->reservation_id)?->uuid : null,
            'membershipVersion' => (int) $s->membership_version,
            'businessDate' => $s->business_date,
            'openedAt' => self::time($s->opened_at),
            'closeRequestedAt' => self::time($s->close_requested_at),
            'closedAt' => self::time($s->closed_at),
            'version' => (int) $s->version,
        ];
    }

    private static function tables(Collection $assignments, Collection $tables): array
    {
        return $assignments->map(fn ($a) => [
            'tableId' => $tables->get((string) $a->table_id)?->uuid,
            'number' => (int) $tables->get((string) $a->table_id)?->number,
            'phase' => $a->phase,
            'assignedAt' => self::time($a->assigned_at),
            // Lo que se envía al confirmar la limpieza de esta mesa.
            'cleaningMembershipVersion' => $a->phase === 'CLEANING' ? (int) $a->cleaning_membership_version : null,
        ])->sortBy('number')->values()->all();
    }

    /** El responsable debe ser un miembro activo de la sucursal; si no, es un dato inválido. */
    public static function responsible(ObjectId $branchId, string $uuid): ObjectId
    {
        $user = User::query()->where('uuid', $uuid)->where('status', User::ACTIVE)->first();
        $member = $user && Membership::query()->where('user_id', new ObjectId($user->getKey()))
            ->where('branch_id', $branchId)->where('status', Membership::ACTIVE)->exists();
        if (! $member) {
            throw ValidationException::withMessages(['responsibleUserId' => 'No es un empleado activo de esta sucursal.']);
        }

        return new ObjectId($user->getKey());
    }

    public static function time($t): ?string
    {
        return $t ? MobileAuth::time($t) : null;
    }
}
