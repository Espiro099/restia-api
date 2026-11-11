<?php

namespace App\Floor;

use App\Checks\Checks;
use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItemUnit;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\TableCleaningConfirmation;
use App\Models\TableSession;
use App\Models\User;
use App\Reservations\Reservations;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Closure;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Ciclo de vida de la sesión (sección 2 de Decisiones_Tables_Sessions): editar, agregar y separar mesas,
 * cerrar, confirmar limpieza y cancelar. Todo es transaccional y, salvo la limpieza, exige If-Match.
 *
 * Las cuentas llegan con Checks (fase 5): hasta entonces no hay SESSION_HAS_OPEN_CHECKS.
 */
final class SessionLifecycle
{
    public const SEATED = 'SEATED';

    public const CLEANING = 'CLEANING';

    /** @param array{guestCount?: int, responsibleUserId?: string} $in */
    public static function update(Branch $branch, TableSession $session, User $actor, int $version, array $in): TableSession
    {
        return self::change($branch, $session, $actor, $version, function (TableSession $s, ObjectId $branchId) use ($in) {
            $changes = [];
            $audit = [];
            if (isset($in['guestCount']) && (int) $in['guestCount'] !== (int) $s->guest_count) {
                $changes['guest_count'] = (int) $in['guestCount'];
                $audit['guestCount'] = ['from' => (int) $s->guest_count, 'to' => (int) $in['guestCount']];
            }
            if (isset($in['responsibleUserId'])) {
                $responsible = TableSessions::responsible($branchId, strtolower($in['responsibleUserId']));
                if ((string) $responsible !== (string) $s->responsible_user_id) {
                    $changes['responsible_user_id'] = $responsible;
                    $audit['responsibleUserId'] = ['from' => (string) $s->responsible_user_id, 'to' => (string) $responsible];
                }
            }

            return [$changes, 'table_session.updated', $audit];
        });
    }

    /** @param list<string> $tableUuids */
    public static function addTables(Branch $branch, TableSession $session, User $actor, int $version, array $tableUuids): TableSession
    {
        try {
            return self::change($branch, $session, $actor, $version, function (TableSession $s, ObjectId $branchId, $now) use ($tableUuids) {
                // Agregar mesas también escribe el epoch de la sucursal (T-9, sección 6).
                Branch::query()->where('_id', $branchId)->increment('operations_epoch');
                $tables = RestaurantTable::query()->where('branch_id', $branchId)->whereIn('uuid', array_map('strtolower', $tableUuids))->get();
                if ($tables->count() !== count($tableUuids) || $tables->contains(fn ($t) => $t->status !== RestaurantTable::ACTIVE)) {
                    throw new DomainConflict('TABLE_NOT_AVAILABLE');
                }
                $ids = $tables->map(fn ($t) => new ObjectId($t->getKey()))->all();
                if (TableAssignment::query()->where('branch_id', $branchId)->where('active', true)->whereIn('table_id', $ids)->exists()) {
                    throw new DomainConflict('TABLE_ALREADY_ASSIGNED');
                }
                foreach ($tables->sortBy('number') as $table) {
                    TableAssignment::query()->create([
                        'branch_id' => $branchId, 'table_id' => new ObjectId($table->getKey()), 'session_id' => new ObjectId($s->getKey()),
                        'phase' => self::SEATED, 'active' => true, 'assigned_at' => $now,
                    ]);
                }

                return [['membership_version' => (int) $s->membership_version + 1], 'table_session.tables_added',
                    ['tables' => $tables->pluck('number')->sort()->values()->all()]];
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('TABLE_ALREADY_ASSIGNED') : $e;
        }
    }

    /** No separa cuentas. Con limpieza, la mesa queda POR_LIMPIAR hasta confirmarla (T-3). */
    public static function releaseTable(Branch $branch, TableSession $session, User $actor, int $version, string $tableUuid, bool $requiresCleaning): TableSession
    {
        return self::change($branch, $session, $actor, $version, function (TableSession $s, ObjectId $branchId, $now) use ($tableUuid, $requiresCleaning) {
            $seated = self::assignments($s)->where('phase', self::SEATED);
            $table = RestaurantTable::query()->where('branch_id', $branchId)->where('uuid', strtolower($tableUuid))->first();
            $assignment = $table ? $seated->first(fn ($a) => (string) $a->table_id === (string) $table->getKey()) : null;
            if (! $assignment) {
                throw new NotFoundHttpException;
            }
            if ($seated->count() === 1) {
                throw new DomainConflict('SESSION_LAST_TABLE');
            }
            $membership = (int) $s->membership_version + 1;
            if ($requiresCleaning) {
                self::startCleaning($branchId, $s, $assignment, $membership);
            } else {
                TableAssignment::query()->where('_id', new ObjectId($assignment->getKey()))->update(['active' => false, 'closed_at' => $now]);
            }

            return [['membership_version' => $membership], 'table_session.table_released',
                ['table' => (int) $table->number, 'requiresCleaning' => $requiresCleaning]];
        });
    }

    /** T-4: sin unidades por terminar y con las cuentas liquidadas. Cada mesa queda en limpieza; la sesión se cierra al limpiar la última. */
    public static function close(Branch $branch, TableSession $session, User $actor, int $version): TableSession
    {
        return self::change($branch, $session, $actor, $version, function (TableSession $s, ObjectId $branchId, $now) {
            $pending = self::unitStates($branchId, $s)->filter(fn ($status) => in_array($status, Tables::ACTIVE_UNIT_STATES, true));
            if ($pending->isNotEmpty()) {
                throw new DomainConflict('SESSION_HAS_PENDING_UNITS', ['units' => $pending->count()]);
            }
            foreach (self::assignments($s)->where('phase', self::SEATED) as $assignment) {
                self::startCleaning($branchId, $s, $assignment, (int) $s->membership_version);
            }
            // C-8: las cuentas sin unidades se cancelan y las de total 0 quedan PAID. T-4: las demás
            // deben estar pagadas, canceladas o reembolsadas.
            Checks::settleOnClose($branchId, $s);
            $open = Checks::unsettled($branchId, $s);
            if ($open) {
                throw new DomainConflict('SESSION_HAS_OPEN_CHECKS', ['checkIds' => $open]);
            }

            // R-9: la reserva sentada aquí se completa.
            Reservations::sessionClosing($s);

            return [['status' => TableSession::PENDING_CLEANING, 'close_requested_at' => $now], 'table_session.close_requested', []];
        });
    }

    /** T-1: solo una apertura sin actividad. Las unidades canceladas no cuentan: nunca se prepararon. */
    public static function cancel(Branch $branch, TableSession $session, User $actor, int $version, string $reason): TableSession
    {
        return self::change($branch, $session, $actor, $version, function (TableSession $s, ObjectId $branchId, $now) use ($reason) {
            $used = self::unitStates($branchId, $s)->contains(fn ($status) => $status !== OrderItemUnit::CANCELLED);
            $cleaning = self::assignments($s)->contains('phase', self::CLEANING);
            if ($used || $cleaning) {
                throw new DomainConflict('SESSION_HAS_ACTIVITY');
            }
            TableAssignment::query()->where('session_id', new ObjectId($s->getKey()))->where('active', true)
                ->update(['active' => false, 'closed_at' => $now]);
            Checks::settleOnClose($branchId, $s);

            // R-9: la reserva sentada aquí vuelve a la espera.
            Reservations::sessionCancelled($s);

            return [['status' => TableSession::CANCELLED, 'cancel_reason' => $reason, 'closed_at' => $now], 'table_session.cancelled', []];
        });
    }

    /**
     * Sin If-Match: un cambio ajeno en la sesión no obliga a repetirla. Repetir una confirmación ya hecha
     * responde igual, sin cambios.
     */
    public static function confirmCleaning(Branch $branch, TableSession $session, User $actor, string $tableUuid, int $membershipVersion): TableSession
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        Mongo::transaction(function () use ($branchId, $actorId, $session, $tableUuid, $membershipVersion) {
            $table = RestaurantTable::query()->where('branch_id', $branchId)->where('uuid', strtolower($tableUuid))->first()
                ?? throw new NotFoundHttpException;
            $confirmation = TableCleaningConfirmation::query()->where('branch_id', $branchId)
                ->where('session_id', new ObjectId($session->getKey()))->where('table_id', new ObjectId($table->getKey()))
                ->where('session_membership_version', $membershipVersion)->first();
            if (! $confirmation) {
                throw new DomainConflict('CLEANING_CONFIRMATION_STALE');
            }
            if ($confirmation->status === TableCleaningConfirmation::CONFIRMED) {
                return;
            }

            $now = now();
            $sessionId = new ObjectId($session->getKey());
            TableCleaningConfirmation::query()->where('_id', new ObjectId($confirmation->getKey()))
                ->update(['status' => TableCleaningConfirmation::CONFIRMED, 'confirmed_by' => $actorId, 'confirmed_at' => $now]);
            TableAssignment::query()->where('session_id', $sessionId)->where('table_id', new ObjectId($table->getKey()))->where('active', true)
                ->update(['active' => false, 'closed_at' => $now]);
            // Siempre se escribe la sesión: dos confirmaciones finales simultáneas chocan y una ve el cierre de la otra.
            $remaining = TableAssignment::query()->where('session_id', $sessionId)->where('active', true)->count();
            $closes = $remaining === 0;
            TableSession::query()->where('_id', $sessionId)->increment('version', 1, $closes
                ? ['status' => TableSession::CLOSED, 'closed_at' => $now, 'updated_at' => $now]
                : ['updated_at' => $now]);

            Audit::record('table.cleaned', 'table', $table->getKey(), $branchId, $actorId, changes: ['session' => $session->uuid, 'table' => (int) $table->number]);
            Outbox::publish('table.cleaned', 'table', $table->getKey(), $branchId, ['tableId' => $table->uuid, 'sessionId' => $session->uuid]);
            if ($closes) {
                Audit::record('table_session.closed', 'table_session', $sessionId, $branchId, $actorId);
                Outbox::publish('table_session.closed', 'table_session', $sessionId, $branchId, ['sessionId' => $session->uuid]);
            }
        });

        return $session->refresh();
    }

    /**
     * Esqueleto común: versión con If-Match, sesión ACTIVE, cambios, auditoría y outbox en una transacción.
     * $work devuelve [cambios de la sesión, evento, detalle de auditoría]. El motivo de una cancelación
     * va a la auditoría desde cancel_reason.
     */
    private static function change(Branch $branch, TableSession $session, User $actor, int $version, Closure $work): TableSession
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        Mongo::transaction(function () use ($branchId, $actorId, $session, $version, $work) {
            $s = TableSession::query()->find($session->getKey());
            if ((int) $s->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            if ($s->status !== TableSession::ACTIVE) {
                throw new DomainConflict('SESSION_NOT_ACTIVE');
            }
            $now = now();
            [$changes, $event, $audit] = $work($s, $branchId, $now);
            $changes['updated_at'] = $now;
            $modified = TableSession::query()->where('_id', new ObjectId($s->getKey()))->where('version', $version)
                ->increment('version', 1, $changes);
            if ($modified === 0) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record($event, 'table_session', $s->getKey(), $branchId, $actorId, reason: $changes['cancel_reason'] ?? null, changes: $audit);
            Outbox::publish($event, 'table_session', $s->getKey(), $branchId, ['sessionId' => $s->uuid]);
        });

        return $session->refresh();
    }

    private static function startCleaning(ObjectId $branchId, TableSession $s, TableAssignment $assignment, int $membershipVersion): void
    {
        TableAssignment::query()->where('_id', new ObjectId($assignment->getKey()))
            ->update(['phase' => self::CLEANING, 'cleaning_membership_version' => $membershipVersion]);
        TableCleaningConfirmation::query()->create([
            'branch_id' => $branchId,
            'session_id' => new ObjectId($s->getKey()),
            'table_id' => new ObjectId((string) $assignment->table_id),
            'session_membership_version' => $membershipVersion,
            'status' => TableCleaningConfirmation::PENDING,
            'confirmed_by' => null,
            'confirmed_at' => null,
        ]);
    }

    /** @return Collection<int, TableAssignment> */
    private static function assignments(TableSession $s): Collection
    {
        return TableAssignment::query()->where('session_id', new ObjectId($s->getKey()))->where('active', true)->get();
    }

    /** @return Collection<int, string> */
    private static function unitStates(ObjectId $branchId, TableSession $s): Collection
    {
        $orders = Order::query()->where('branch_id', $branchId)->where('session_id', new ObjectId($s->getKey()))->get()
            ->map(fn ($o) => new ObjectId($o->getKey()))->values()->all();

        return $orders ? OrderItemUnit::query()->whereIn('order_id', $orders)->pluck('status') : collect();
    }
}
