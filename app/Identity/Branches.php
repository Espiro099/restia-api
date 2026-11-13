<?php

namespace App\Identity;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;
use Throwable;

/**
 * Sucursales (Decisiones_Branches). No se borran: se desactivan. El código y la moneda no cambian, y
 * la zona horaria y el corte del día solo cambian sin operación activa, porque definen business_date.
 */
final class Branches
{
    /** Campos editables y su columna. */
    private const FIELDS = [
        'name' => 'name', 'timezone' => 'timezone', 'businessDayCutoff' => 'business_day_cutoff', 'phone' => 'phone', 'address' => 'address',
        'reservationHours' => 'reservation_hours', 'reservationNoShowToleranceMinutes' => 'reservation_no_show_tolerance_minutes',
        'reservationAiEnabled' => 'reservation_ai_enabled', 'reservationAiMaxPartySize' => 'reservation_ai_max_party_size',
    ];

    public static function present(Branch $b): array
    {
        $time = fn ($t) => $t ? Carbon::instance($t)->utc()->format('Y-m-d\TH:i:s\Z') : null;
        $address = $b->address ? (array) json_decode(json_encode($b->address), true) : null;

        return [
            'id' => $b->uuid,
            'code' => $b->code,
            'name' => $b->name,
            'status' => $b->status,
            'timezone' => $b->timezone ?: 'America/Mexico_City',
            'currency' => $b->currency ?: 'MXN',
            'businessDayCutoff' => $b->business_day_cutoff ?: config('restia.floor.business_day_cutoff'),
            'phone' => $b->phone,
            'address' => $address,
            'createdAt' => $time($b->created_at),
            'updatedAt' => $time($b->updated_at),
            'version' => (int) $b->version,
            'reservationHours' => array_values(array_map(fn ($h) => (array) $h, json_decode(json_encode($b->reservation_hours ?? []), true))),
            'reservationNoShowToleranceMinutes' => (int) ($b->reservation_no_show_tolerance_minutes ?? 15),
            'fiscalIssuerId' => null,
            'reservationAiEnabled' => (bool) ($b->reservation_ai_enabled ?? false),
            'reservationAiMaxPartySize' => (int) ($b->reservation_ai_max_party_size ?? 10),
        ];
    }

    /** B-2: quien la crea queda como su ADMIN. La primera sucursal se crea con restia:install. */
    public static function create(User $actor, array $in): Branch
    {
        $actorId = new ObjectId($actor->getKey());
        try {
            return Mongo::transaction(function () use ($actorId, $in) {
                $changes = ['code' => $in['code'], 'status' => Branch::ACTIVE, 'currency' => $in['currency'], 'operations_epoch' => 0, 'menu_version' => 0];
                foreach (self::FIELDS as $field => $column) {
                    if (array_key_exists($field, $in)) {
                        $changes[$column] = $in[$field];
                    }
                }
                $branch = Branch::query()->create($changes);
                $branchId = new ObjectId($branch->getKey());
                Membership::query()->create(['user_id' => $actorId, 'branch_id' => $branchId, 'role' => 'ADMIN', 'status' => Membership::ACTIVE]);
                Audit::record('branch.created', 'branch', $branchId, $branchId, $actorId, changes: ['code' => $in['code'], 'name' => $in['name']]);
                Audit::record('member.created', 'user', $actorId, $branchId, $actorId, changes: ['role' => 'ADMIN', 'userCreated' => false]);
                Outbox::publish('branch.created', 'branch', $branchId, $branchId, ['branchId' => $branch->uuid]);

                return $branch;
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('BRANCH_CODE_TAKEN') : $e;
        }
    }

    public static function update(User $actor, Branch $branch, int $version, array $in): Branch
    {
        return self::change($actor, $branch, $version, function (Branch $fresh) use ($in) {
            $changes = [];
            $before = [];
            foreach (self::FIELDS as $field => $column) {
                if (array_key_exists($field, $in) && $in[$field] !== $fresh->{$column}) {
                    $changes[$column] = $in[$field];
                    $before[$field] = $fresh->{$column};
                }
            }
            $touchesDate = array_key_exists('timezone', $changes) || array_key_exists('business_day_cutoff', $changes);
            if ($touchesDate) {
                self::assertIdle($fresh, false);
            }

            return [$changes, 'branch.updated', ['before' => $before, 'after' => array_intersect_key($in, $before)], null];
        });
    }

    /** Sin operación activa (Decisiones_Branches). Desactivar también exige no tener reservas futuras confirmadas. */
    public static function deactivate(User $actor, Branch $branch, int $version, string $reason): Branch
    {
        return self::change($actor, $branch, $version, function (Branch $fresh) use ($reason) {
            if ($fresh->status === Branch::INACTIVE) {
                return [[], null, [], null];
            }
            self::assertIdle($fresh, true);

            return [['status' => Branch::INACTIVE], 'branch.deactivated', [], $reason];
        });
    }

    public static function activate(User $actor, Branch $branch, int $version): Branch
    {
        return self::change($actor, $branch, $version, fn (Branch $fresh) => $fresh->status === Branch::ACTIVE
            ? [[], null, [], null]
            : [['status' => Branch::ACTIVE], 'branch.activated', [], null]);
    }

    /** Sesiones de mesa abiertas, turnos de caja abiertos o en cierre, pagos sin resolver y, al desactivar, reservas futuras confirmadas. */
    private static function assertIdle(Branch $b, bool $withReservations): void
    {
        $id = new ObjectId($b->getKey());
        $active = [
            'tableSessions' => TableSession::query()->where('branch_id', $id)->whereIn('status', [TableSession::ACTIVE, TableSession::PENDING_CLEANING])->count(),
            'cashShifts' => CashShift::query()->where('branch_id', $id)->where('active', true)->count(),
            'unknownPayments' => Payment::query()->where('branch_id', $id)->where('status', 'UNKNOWN')->count(),
            'reservations' => $withReservations
                ? Reservation::query()->where('branch_id', $id)->whereIn('status', Reservation::HOLDING)->where('end_at', '>', now())->count() : 0,
        ];
        if (array_filter($active)) {
            throw new DomainConflict('BRANCH_HAS_ACTIVE_OPERATIONS', array_filter($active));
        }
    }

    /**
     * If-Match, epoch primero (choca con abrir sesiones, turnos o confirmar reservas), auditoría y
     * outbox, en una transacción. $work devuelve [cambios, evento, auditoría, motivo]; sin evento no
     * hay cambios.
     */
    private static function change(User $actor, Branch $branch, int $version, \Closure $work): Branch
    {
        $id = new ObjectId($branch->getKey());
        Mongo::transaction(function () use ($actor, $id, $version, $work) {
            Branch::query()->where('_id', $id)->increment('operations_epoch');
            $fresh = Branch::query()->find($id);
            if ((int) $fresh->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            [$changes, $event, $audit, $reason] = $work($fresh);
            if (! $event || ! $changes) {
                return;
            }
            if (! $fresh->updateIfVersion($version, $changes)) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record($event, 'branch', $id, $id, new ObjectId($actor->getKey()), reason: $reason, changes: $audit);
            Outbox::publish($event, 'branch', $id, $id, ['branchId' => $fresh->uuid]);
        });

        return $branch->refresh();
    }
}
