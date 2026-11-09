<?php

namespace App\Cash;

use App\Catalog\Catalog;
use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\User;
use App\Support\Audit;
use App\Support\Floor;
use App\Support\Idempotency;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Throwable;

/**
 * Cajas y turnos (Decisiones_Cash). El cierre va en dos pasos (CA-3): CLOSING frena operaciones nuevas y
 * CLOSED registra el conteo. Un turno cerrado es inmutable (CA-10).
 */
final class Cash
{
    // ---------------------------------------------------------------- Cajas

    /** `differenceTolerance` solo con cash.read. */
    public static function presentRegister(Branch $branch, CashRegister $r, bool $amounts): array
    {
        return [
            'id' => $r->uuid,
            'branchId' => $branch->uuid,
            'code' => $r->code,
            'name' => $r->name,
            'status' => $r->status,
            ...($amounts ? ['differenceTolerance' => Floor::money($r->difference_tolerance)] : []),
            'version' => (int) $r->version,
        ];
    }

    /** @param array{code: string, name: string, differenceTolerance?: array{amount: string}} $in */
    public static function createRegister(Branch $branch, User $actor, array $in): CashRegister
    {
        return Catalog::create($branch, $actor, 'cash_register', 'CATALOG_CODE_TAKEN', fn (ObjectId $branchId) => CashRegister::query()->create([
            'branch_id' => $branchId, 'code' => $in['code'], 'name' => $in['name'], 'status' => CashRegister::ACTIVE,
            'difference_tolerance' => Floor::decimal($in['differenceTolerance']['amount'] ?? '0'),
        ]), menu: false);
    }

    /** Una tolerancia nueva solo se aplica a los cierres posteriores. */
    public static function updateRegister(Branch $branch, User $actor, CashRegister $r, int $version, array $in): CashRegister
    {
        return Catalog::change($branch, $actor, $r, $version, 'cash_register.updated', 'cash_register', function () use ($in) {
            $changes = [];
            if (array_key_exists('name', $in)) {
                $changes['name'] = $in['name'];
            }
            if (array_key_exists('differenceTolerance', $in)) {
                $changes['difference_tolerance'] = Floor::decimal($in['differenceTolerance']['amount']);
            }

            return $changes;
        }, menu: false);
    }

    /** Escribe en `operations_epoch`, igual que abrir un turno: las dos operaciones chocan. */
    public static function setRegisterStatus(Branch $branch, User $actor, CashRegister $r, int $version, string $status, string $reason): CashRegister
    {
        if ($r->status === $status) {
            return $r;
        }

        return Catalog::change($branch, $actor, $r, $version, 'cash_register.status_changed', 'cash_register', function (CashRegister $fresh) use ($status) {
            Branch::query()->where('_id', new ObjectId((string) $fresh->branch_id))->increment('operations_epoch');
            if ($status === CashRegister::INACTIVE && CashShift::query()->where('cash_register_id', new ObjectId($fresh->getKey()))->where('active', true)->exists()) {
                throw new DomainConflict('CASH_REGISTER_HAS_ACTIVE_SHIFT');
            }

            return ['status' => $status];
        }, reason: $reason, menu: false);
    }

    // ---------------------------------------------------------------- Turnos

    /**
     * Con solo cash.read_open, sin montos ni motivos.
     *
     * @param  Collection<int, CashShift>  $shifts
     */
    public static function presentShifts(Branch $branch, Collection $shifts, bool $amounts): array
    {
        $uuids = fn (string $model, string $field) => $model::query()
            ->whereIn('_id', $shifts->pluck($field)->filter()->map(fn ($id) => new ObjectId((string) $id))->unique()->values()->all())
            ->get()->mapWithKeys(fn ($d) => [(string) $d->getKey() => $d->uuid]);
        $registers = $uuids(CashRegister::class, 'cash_register_id');
        $users = $uuids(User::class, 'opened_by')->union($uuids(User::class, 'closed_by'));
        $money = fn ($v) => $v === null ? null : Floor::money($v);

        return $shifts->map(fn (CashShift $s) => [
            'id' => $s->uuid,
            'branchId' => $branch->uuid,
            'cashRegisterId' => $registers->get((string) $s->cash_register_id),
            'status' => $s->status,
            'businessDate' => $s->business_date,
            'openedBy' => $users->get((string) $s->opened_by),
            'openedAt' => MobileAuth::time($s->opened_at),
            'closingStartedAt' => MobileAuth::time($s->closing_started_at),
            'closedBy' => $s->closed_by ? $users->get((string) $s->closed_by) : null,
            'closedAt' => MobileAuth::time($s->closed_at),
            ...($amounts ? [
                'openingAmount' => Floor::money($s->opening_amount),
                'openingDifference' => $money($s->opening_difference),
                'openingDifferenceReason' => $s->opening_difference_reason,
                'expectedCash' => $money($s->expected_cash),
                'countedCash' => $money($s->counted_cash),
                'difference' => $money($s->difference),
                'differenceReason' => $s->difference_reason,
                'cashLeftInDrawer' => $money($s->cash_left_in_drawer),
                'reviewRequired' => (bool) $s->review_required,
            ] : []),
            'version' => (int) $s->version,
        ])->values()->all();
    }

    /**
     * Abrir (CA-9): si el fondo difiere de lo que dejó el turno anterior de la caja, exige motivo. El
     * primer turno de una caja no se compara.
     *
     * @param  array{cashRegisterId: string, openingAmount: array{amount: string}, openingDifferenceReason?: string}  $in
     * @return array{0: int, 1: CashShift|array<string, mixed>}
     */
    public static function open(Branch $branch, User $actor, string $key, array $in, \Closure $present): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        $register = CashRegister::query()->where('branch_id', $branchId)->where('uuid', strtolower($in['cashRegisterId']))->first()
            ?? throw ValidationException::withMessages(['cashRegisterId' => 'La caja no existe en esta sucursal.']);

        try {
            return Idempotency::run($branchId, $key, $actorId, 'openCashShift', Idempotency::hash('openCashShift', $in), function () use ($branch, $branchId, $actorId, $key, $in, $register, $present) {
                // Primero el epoch: choca con desactivar la sucursal o la caja.
                Branch::query()->where('_id', $branchId)->increment('operations_epoch');
                if (! Branch::query()->find($branchId)?->isActive()) {
                    throw new DomainConflict('BRANCH_INACTIVE');
                }
                if (CashRegister::query()->find($register->getKey())->status !== CashRegister::ACTIVE) {
                    throw new DomainConflict('CASH_REGISTER_INACTIVE');
                }
                $registerId = new ObjectId($register->getKey());
                if (CashShift::query()->where('cash_register_id', $registerId)->where('active', true)->exists()) {
                    throw new DomainConflict('CASH_SHIFT_ALREADY_OPEN');
                }
                $amount = bcadd($in['openingAmount']['amount'], '0', 2);
                $previous = CashShift::query()->where('cash_register_id', $registerId)->where('status', CashShift::CLOSED)->orderBy('closed_at', 'desc')->first();
                $difference = $previous ? bcsub($amount, (string) $previous->cash_left_in_drawer, 2) : null;
                $reason = $in['openingDifferenceReason'] ?? null;
                if ($difference !== null && bccomp($difference, '0', 2) !== 0 && $reason === null) {
                    throw new DomainConflict('CASH_SHIFT_REASON_REQUIRED', ['openingDifference' => $difference]);
                }
                $now = now();
                $shift = CashShift::query()->create([
                    'branch_id' => $branchId, 'cash_register_id' => $registerId, 'status' => CashShift::OPEN, 'active' => true,
                    'business_date' => Floor::businessDate($branch, $now), 'opening_amount' => Floor::decimal($amount),
                    'opening_difference' => $difference === null ? null : Floor::decimal($difference), 'opening_difference_reason' => $reason,
                    'opened_by' => $actorId, 'opened_at' => $now, 'closing_started_at' => null, 'closed_by' => null, 'closed_at' => null,
                    'expected_cash' => null, 'counted_cash' => null, 'difference' => null, 'difference_reason' => null,
                    'cash_left_in_drawer' => null, 'review_required' => false, 'closing_summary' => null, 'activity_epoch' => 0,
                    'idempotency_key' => $key,
                ]);
                Audit::record('cash_shift.opened', 'cash_shift', $shift->getKey(), $branchId, $actorId, reason: $reason, changes: [
                    'after' => ['register' => $register->uuid, 'opening_amount' => $amount, 'opening_difference' => $difference],
                ]);
                Outbox::publish('cash_shift.opened', 'cash_shift', $shift->getKey(), $branchId, ['cashShiftId' => $shift->uuid, 'cashRegisterId' => $register->uuid]);

                return [201, $present($shift), $shift->uuid];
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('CASH_SHIFT_ALREADY_OPEN') : $e;
        }
    }

    /**
     * Depósito o retiro (CA-5), solo en OPEN. Escribe en `activity_epoch` del turno, no en `version`: choca
     * con iniciar el cierre sin romper su If-Match.
     *
     * @param  array{type: string, amount: array{amount: string}, reason: string}  $in
     */
    public static function move(Branch $branch, User $actor, CashShift $shift, string $key, array $in, \Closure $present): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        return Idempotency::run($branchId, $key, $actorId, 'createCashMovement', Idempotency::hash('createCashMovement', ['shift' => $shift->uuid] + $in), function () use ($branchId, $actorId, $shift, $key, $in, $present) {
            self::touch($shift);
            $amount = bcadd($in['amount']['amount'], '0', 2);
            $m = CashMovement::query()->create([
                'branch_id' => $branchId, 'cash_shift_id' => new ObjectId($shift->getKey()), 'type' => $in['type'],
                'amount' => Floor::decimal($amount), 'reason' => $in['reason'], 'idempotency_key' => $key, 'created_by' => $actorId,
            ]);
            Audit::record('cash_movement.created', 'cash_movement', $m->getKey(), $branchId, $actorId, reason: $in['reason'], changes: [
                'after' => ['shift' => $shift->uuid, 'type' => $in['type'], 'amount' => $amount],
            ]);
            Outbox::publish('cash_movement.created', 'cash_movement', $m->getKey(), $branchId, ['cashMovementId' => $m->uuid, 'cashShiftId' => $shift->uuid]);

            return [201, $present($m), $m->uuid];
        });
    }

    /**
     * Para cobros y movimientos: el turno debe seguir OPEN. Sube `activity_epoch` primero, dentro de la
     * transacción, así que un cierre simultáneo choca y quien reintente ve el estado nuevo.
     */
    public static function touch(CashShift $shift): void
    {
        CashShift::query()->where('_id', new ObjectId($shift->getKey()))->increment('activity_epoch');
        if (CashShift::query()->find($shift->getKey())->status !== CashShift::OPEN) {
            throw new DomainConflict('CASH_SHIFT_NOT_OPEN');
        }
    }

    /** OPEN → CLOSING: frena pagos, reembolsos y movimientos nuevos. Repetirlo no cambia nada. */
    public static function startClosing(Branch $branch, User $actor, CashShift $shift, int $version): CashShift
    {
        if ($shift->status === CashShift::CLOSING) {
            return $shift;
        }

        return self::change($branch, $actor, $shift, $version, CashShift::OPEN, 'cash_shift.closing_started', null,
            fn () => ['status' => CashShift::CLOSING, 'closing_started_at' => now()]);
    }

    /** CLOSING → OPEN, con motivo. Repetirlo no cambia nada. */
    public static function cancelClosing(Branch $branch, User $actor, CashShift $shift, int $version, string $reason): CashShift
    {
        if ($shift->status === CashShift::OPEN) {
            return $shift;
        }

        return self::change($branch, $actor, $shift, $version, CashShift::CLOSING, 'cash_shift.closing_cancelled', $reason,
            fn () => ['status' => CashShift::OPEN, 'closing_started_at' => null]);
    }

    /**
     * CLOSING → CLOSED (CA-3, CA-4, CA-8): sin pagos ni reembolsos sin resolver. Toda diferencia exige
     * motivo; si supera la tolerancia de la caja, el turno queda para revisión y se publica una alerta. No
     * impide cerrar: bloquearlo impediría abrir el siguiente turno. El resumen queda congelado.
     *
     * @param  array{countedCash: array{amount: string}, cashLeftInDrawer: array{amount: string}, differenceReason?: string}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function close(Branch $branch, User $actor, CashShift $shift, int $version, string $key, array $in, \Closure $present): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        $counted = bcadd($in['countedCash']['amount'], '0', 2);
        $left = bcadd($in['cashLeftInDrawer']['amount'], '0', 2);
        if (bccomp($left, $counted, 2) > 0) {
            throw ValidationException::withMessages(['cashLeftInDrawer.amount' => 'No puede superar lo contado.']);
        }
        $hash = Idempotency::hash('closeCashShift', ['shift' => $shift->uuid, 'version' => $version] + $in);

        return Idempotency::run($branchId, $key, $actorId, 'closeCashShift', $hash, function () use ($branchId, $actorId, $shift, $version, $in, $counted, $left, $present) {
            $fresh = self::fresh($shift, $version, CashShift::CLOSING);
            $summary = Summary::of($fresh);
            $unresolved = array_sum($summary['unresolved']);
            if ($unresolved > 0) {
                throw new DomainConflict('CASH_SHIFT_HAS_UNRESOLVED_OPERATIONS', $summary['unresolved']);
            }
            $expected = $summary['expectedCash']['amount'];
            $difference = bcsub($counted, $expected, 2);
            $reason = $in['differenceReason'] ?? null;
            if (bccomp($difference, '0', 2) !== 0 && $reason === null) {
                throw new DomainConflict('CASH_SHIFT_REASON_REQUIRED', ['difference' => $difference]);
            }
            $tolerance = (string) CashRegister::query()->find((string) $fresh->cash_register_id)->difference_tolerance;
            $review = bccomp(ltrim($difference, '-'), $tolerance, 2) > 0;
            $summary['countedCash'] = Floor::money($counted);
            $summary['difference'] = Floor::money($difference);
            $summary['status'] = CashShift::CLOSED;
            unset($summary['frozen']);
            $now = now();
            if (! $fresh->updateIfVersion($version, [
                'status' => CashShift::CLOSED, 'active' => false, 'closed_by' => $actorId, 'closed_at' => $now,
                'expected_cash' => Floor::decimal($expected), 'counted_cash' => Floor::decimal($counted), 'difference' => Floor::decimal($difference),
                'difference_reason' => $reason, 'cash_left_in_drawer' => Floor::decimal($left), 'review_required' => $review,
                'closing_summary' => $summary,
            ])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record('cash_shift.closed', 'cash_shift', $fresh->getKey(), $branchId, $actorId, reason: $reason, changes: [
                'after' => ['expected' => $expected, 'counted' => $counted, 'difference' => $difference, 'left' => $left, 'review' => $review],
            ]);
            Outbox::publish('cash_shift.closed', 'cash_shift', $fresh->getKey(), $branchId, ['cashShiftId' => $fresh->uuid]);
            if ($review) {
                Outbox::publish('cash_shift.difference_detected', 'cash_shift', $fresh->getKey(), $branchId, ['cashShiftId' => $fresh->uuid, 'difference' => $difference]);
            }

            return [200, $present($fresh), $fresh->uuid];
        });
    }

    /** CA-11: un turno activo por más de 24 horas genera una alerta, una vez; no se cierra solo. */
    public static function forgotten(): int
    {
        $old = CashShift::query()->where('active', true)->where('opened_at', '<', now()->subHours(24))->where('forgotten_alerted', '!=', true)->get();
        foreach ($old as $shift) {
            Mongo::transaction(function () use ($shift) {
                CashShift::query()->where('_id', $shift->getKey())->update(['forgotten_alerted' => true]);
                Outbox::publish('cash_shift.forgotten', 'cash_shift', $shift->getKey(), (string) $shift->branch_id, ['cashShiftId' => $shift->uuid]);
            });
        }

        return $old->count();
    }

    private static function change(Branch $branch, User $actor, CashShift $shift, int $version, string $from, string $event, ?string $reason, \Closure $work): CashShift
    {
        $branchId = new ObjectId($branch->getKey());
        Mongo::transaction(function () use ($branchId, $actor, $shift, $version, $from, $event, $reason, $work) {
            $fresh = self::fresh($shift, $version, $from);
            $changes = $work($fresh);
            if (! $fresh->updateIfVersion($version, $changes)) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record($event, 'cash_shift', $fresh->getKey(), $branchId, new ObjectId($actor->getKey()), reason: $reason, changes: [
                'before' => ['status' => $from], 'after' => ['status' => $changes['status']],
            ]);
            Outbox::publish($event, 'cash_shift', $fresh->getKey(), $branchId, ['cashShiftId' => $fresh->uuid]);
        });

        return $shift->refresh();
    }

    private static function fresh(CashShift $shift, int $version, string $state): CashShift
    {
        $fresh = CashShift::query()->find($shift->getKey());
        if ((int) $fresh->version !== $version) {
            throw new DomainConflict('VERSION_CONFLICT');
        }
        if ($fresh->status !== $state) {
            throw new DomainConflict('CASH_SHIFT_INVALID_STATE');
        }

        return $fresh;
    }
}
