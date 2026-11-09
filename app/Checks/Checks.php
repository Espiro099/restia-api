<?php

namespace App\Checks;

use App\Exceptions\DomainConflict;
use App\Floor\Approvals;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\Check;
use App\Models\CheckAdjustment;
use App\Models\CheckAllocation;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItemUnit;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\Floor;
use App\Support\Idempotency;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cuentas (Decisiones_Checks). La unidad es indivisible (C-1): dividir una cuenta es crear otra vacía y
 * mover unidades hacia ella. Toda operación es transaccional y recalcula los totales (C-6).
 *
 * Los pagos llegan con Payments: hasta entonces no hay pagos confirmados ni en curso, y las reglas que
 * dependen de ellos ya consultan la colección `payments`.
 */
final class Checks
{
    /** Pagos en curso: bloquean mover unidades, reabrir y dar cortesías (sección 2). */
    public const OPEN_PAYMENT_STATES = ['PENDING', 'UNKNOWN'];

    // ---------------------------------------------------------------- Lectura

    /**
     * @param  Collection<int, Check>  $checks
     * @return list<array<string, mixed>>
     */
    public static function present(Branch $branch, Collection $checks): array
    {
        $sessions = TableSession::query()->whereIn('_id', $checks->map(fn ($c) => new ObjectId((string) $c->session_id))->unique()->values()->all())->get()
            ->mapWithKeys(fn ($s) => [(string) $s->getKey() => $s->uuid]);

        return $checks->map(function (Check $c) use ($branch, $sessions) {
            $lines = Totals::lines($c);
            $orders = Order::query()->whereIn('_id', $lines->map(fn ($l) => new ObjectId((string) $l['unit']->order_id))->unique()->values()->all())->get()
                ->mapWithKeys(fn ($o) => [(string) $o->getKey() => $o->uuid]);

            return [
                'id' => $c->uuid,
                'branchId' => $branch->uuid,
                'sessionId' => $sessions->get((string) $c->session_id),
                'number' => (int) $c->number,
                'label' => $c->label,
                'status' => $c->status,
                'units' => $lines->map(fn ($l) => [
                    'unitId' => $l['unit']->uuid,
                    'orderId' => $orders->get((string) $l['unit']->order_id),
                    'productName' => $l['item']->name_snapshot,
                    'status' => $l['unit']->status,
                    'price' => Floor::money($l['price']),
                    'tax' => Floor::money($l['tax']),
                    'adjustment' => $l['adjustment'] ? [
                        'id' => $l['adjustment']->uuid,
                        'type' => $l['adjustment']->type,
                        'amount' => Floor::money($l['adjustment']->amount),
                        'reason' => $l['adjustment']->reason,
                        'createdAt' => MobileAuth::time($l['adjustment']->created_at),
                    ] : null,
                ])->values()->all(),
                'subtotal' => Floor::money($c->subtotal),
                'taxTotal' => Floor::money($c->tax_total),
                'adjustmentTotal' => Floor::money($c->adjustment_total),
                'total' => Floor::money($c->total),
                'paidTotal' => Floor::money($c->paid_total),
                'refundedTotal' => Floor::money($c->refunded_total),
                'pendingTotal' => Floor::money($c->pending_total),
                'tipTotal' => Floor::money($c->tip_total),
                'balance' => Floor::money($c->balance),
                'businessDate' => $c->business_date,
                'requestedAt' => MobileAuth::time($c->requested_at),
                'paidAt' => MobileAuth::time($c->paid_at),
                'activeInvoiceId' => null,
                'createdAt' => MobileAuth::time($c->created_at),
                'version' => (int) $c->version,
            ];
        })->values()->all();
    }

    // ---------------------------------------------------------------- Alta y asignación

    /**
     * Cuenta vacía en una sesión ACTIVE, para dividir. Escribe en `activity_epoch`, así que choca con el
     * cierre de la sesión (sección 5).
     *
     * @param  array{sessionId: string, label?: ?string}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function create(Branch $branch, User $actor, string $key, array $in): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        return Idempotency::run($branchId, $key, $actorId, 'createCheck', Idempotency::hash('createCheck', $in), function () use ($branch, $branchId, $actorId, $in) {
            $session = self::activeSession($branchId, $in['sessionId']);
            $check = self::open($branchId, $session, $actorId, $in['label'] ?? null);

            return [201, self::present($branch, collect([$check]))[0], $check->uuid];
        });
    }

    /**
     * Unidades nuevas de una comanda (C-4, D-17): van a `targetCheckId` si se indica, que debe estar
     * OPEN; si no, a la cuenta OPEN más reciente de la sesión, o a una nueva si no hay. Corre dentro de
     * la transacción de la comanda, después de su escritura en `activity_epoch`.
     *
     * @param  list<ObjectId>  $unitIds
     */
    public static function assign(ObjectId $branchId, TableSession $session, ?string $targetUuid, array $unitIds, ObjectId $actorId): Check
    {
        $sessionId = new ObjectId($session->getKey());
        if ($targetUuid !== null) {
            $check = Check::query()->where('branch_id', $branchId)->where('session_id', $sessionId)->where('uuid', strtolower($targetUuid))->first();
            if ($check?->status !== Check::OPEN) {
                throw new DomainConflict('CHECK_INVALID_STATE');
            }
        } else {
            $check = Check::query()->where('branch_id', $branchId)->where('session_id', $sessionId)->where('status', Check::OPEN)
                ->orderBy('number', 'desc')->first() ?? self::open($branchId, $session, $actorId, null);
        }
        foreach ($unitIds as $unitId) {
            CheckAllocation::query()->create([
                'branch_id' => $branchId, 'check_id' => new ObjectId($check->getKey()), 'order_item_unit_id' => $unitId,
                'active' => true, 'created_by' => $actorId, 'closed_at' => null,
            ]);
        }
        self::refresh($check);

        return $check->refresh();
    }

    /**
     * Recalcula las cuentas de unas unidades que cambiaron de estado: una cancelación o anulación deja de
     * cobrarse. Corre en la transacción de la transición.
     *
     * @param  list<ObjectId>  $unitIds
     */
    public static function unitsChanged(array $unitIds): void
    {
        $checkIds = CheckAllocation::query()->whereIn('order_item_unit_id', $unitIds)->where('active', true)->get(['check_id'])
            ->map(fn ($a) => (string) $a->check_id)->unique()->values();
        foreach (Check::query()->whereIn('_id', $checkIds->map(fn ($id) => new ObjectId($id))->all())->get() as $check) {
            self::refresh($check);
        }
    }

    /**
     * Saldo y estado de la cuenta después de un cambio en sus pagos o reembolsos (P-3, P-8), en la
     * transacción de ese cambio. `pending_total` reserva el saldo de los pagos en curso, así que un pago
     * nuevo nunca deja la cuenta pagada de más. Con lo pagado reembolsado por completo queda REFUNDED.
     */
    public static function settle(Check $check, bool $starting = false): Check
    {
        $fresh = Check::query()->find($check->getKey());
        $checkId = new ObjectId($fresh->getKey());
        $sum = fn ($rows, string $field) => $rows->reduce(fn ($carry, $r) => bcadd($carry, (string) $r->$field, 2), '0');
        $payments = Payment::query()->where('check_id', $checkId)->get(['status', 'amount', 'tip']);
        $confirmed = $payments->where('status', 'CONFIRMED');
        $paid = $sum($confirmed, 'amount');
        $refunded = $sum(Refund::query()->where('check_id', $checkId)->where('status', 'CONFIRMED')->get(['amount']), 'amount');
        [$totals] = Totals::compute($fresh);
        $balance = bcsub((string) $totals['total'], $paid, 2);
        $status = $fresh->status;
        // C-3: OPEN siempre significa sin pagos; el primero la pasa a REQUESTED.
        if ($starting && $status === Check::OPEN) {
            $status = Check::REQUESTED;
        }
        if (bccomp($paid, '0', 2) > 0) {
            $status = match (true) {
                bccomp($refunded, $paid, 2) >= 0 => Check::REFUNDED,
                bccomp($balance, '0', 2) <= 0 => Check::PAID,
                default => Check::PARTIALLY_PAID,
            };
        }
        $changes = [
            'paid_total' => Floor::decimal($paid),
            'pending_total' => Floor::decimal($sum($payments->whereIn('status', self::OPEN_PAYMENT_STATES), 'amount')),
            'tip_total' => Floor::decimal($sum($confirmed, 'tip')),
            'refunded_total' => Floor::decimal($refunded),
            'balance' => Floor::decimal($balance),
            'status' => $status,
        ];
        if ($status === Check::PAID && $fresh->status !== Check::PAID) {
            $changes['paid_at'] = now();
        }
        if ($status === Check::REQUESTED && $fresh->requested_at === null) {
            $changes['requested_at'] = now();
        }
        self::save($fresh, (int) $fresh->version, $changes);
        if ($status !== $fresh->status) {
            Audit::record('check.'.strtolower($status), 'check', $fresh->getKey(), (string) $fresh->branch_id, changes: ['before' => ['status' => $fresh->status], 'after' => ['status' => $status]]);
            Outbox::publish('check.'.strtolower($status), 'check', $fresh->getKey(), (string) $fresh->branch_id, ['checkId' => $fresh->uuid]);
        }

        return $fresh->refresh();
    }

    /** Lo disponible para un pago nuevo: total − pagado − reservado por pagos en curso (P-3). */
    public static function available(Check $check): string
    {
        return bcsub(bcsub((string) $check->total, (string) $check->paid_total, 2), (string) $check->pending_total, 2);
    }

    /**
     * C-2: un pago confirmado congela todas las unidades de su cuenta, que ya no se cancelan ni anulan.
     *
     * @param  list<ObjectId>  $unitIds
     * @return list<ObjectId> las que están en una cuenta con pago confirmado
     */
    public static function paidUnits(array $unitIds): array
    {
        $allocations = CheckAllocation::query()->whereIn('order_item_unit_id', $unitIds)->where('active', true)->get();
        $paid = self::payments($allocations->pluck('check_id')->map(fn ($id) => new ObjectId((string) $id))->unique()->values()->all(), ['CONFIRMED']);

        return $allocations->filter(fn ($a) => in_array((string) $a->check_id, $paid, true))
            ->map(fn ($a) => new ObjectId((string) $a->order_item_unit_id))->values()->all();
    }

    /**
     * Al cerrar la sesión (C-8, precisa T-4): una cuenta sin unidades que se cobren se cancela; una con
     * unidades y total 0 queda PAID.
     */
    public static function settleOnClose(ObjectId $branchId, TableSession $session): void
    {
        $checks = Check::query()->where('branch_id', $branchId)->where('session_id', new ObjectId($session->getKey()))
            ->whereIn('status', [Check::OPEN, Check::REQUESTED])->get();
        foreach ($checks as $check) {
            [, $billed] = Totals::compute($check);
            if ($billed === 0) {
                self::system($check, ['status' => Check::CANCELLED], 'check.cancelled', 'La sesión se cerró sin unidades en la cuenta.');
            } elseif (bccomp((string) $check->balance, '0', 2) === 0) {
                self::system($check, ['status' => Check::PAID, 'paid_at' => now()], 'check.paid', 'Total en cero al cerrar la sesión.');
            }
        }
    }

    /**
     * Cuentas de la sesión que impiden cerrarla (T-4): las que no están PAID, CANCELLED ni REFUNDED.
     *
     * @return list<string>
     */
    public static function unsettled(ObjectId $branchId, TableSession $session): array
    {
        return Check::query()->where('branch_id', $branchId)->where('session_id', new ObjectId($session->getKey()))
            ->whereNotIn('status', [Check::PAID, Check::CANCELLED, Check::REFUNDED])->pluck('uuid')->values()->all();
    }

    // ---------------------------------------------------------------- Operaciones de la cuenta

    /**
     * Mover unidades a otra cuenta de la misma sesión (C-5): If-Match en el origen y la versión del
     * destino en el cuerpo. Cierra las asignaciones y crea otras; las cortesías viajan con la unidad.
     *
     * @param  array{unitIds: list<string>, targetCheckId: string, targetCheckVersion: int}  $in
     */
    public static function moveUnits(Branch $branch, User $actor, Check $source, int $version, array $in): Check
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        Mongo::transaction(function () use ($branchId, $actorId, $source, $version, $in) {
            $from = self::fresh($source, $version, [Check::OPEN, Check::REQUESTED, Check::REFUNDED]);
            $to = Check::query()->where('branch_id', $branchId)->where('uuid', strtolower($in['targetCheckId']))->first()
                ?? throw ValidationException::withMessages(['targetCheckId' => 'La cuenta no existe en esta sucursal.']);
            if ((string) $to->getKey() === (string) $from->getKey()) {
                throw ValidationException::withMessages(['targetCheckId' => 'El destino debe ser otra cuenta.']);
            }
            if ((int) $to->version !== (int) $in['targetCheckVersion']) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            if ($to->status !== Check::OPEN) {
                throw new DomainConflict('CHECK_INVALID_STATE');
            }
            if ((string) $to->session_id !== (string) $from->session_id) {
                throw new DomainConflict('CHECK_SESSION_MISMATCH');
            }
            self::assertNoPayments([$from, $to]);

            $units = OrderItemUnit::query()->where('branch_id', $branchId)->whereIn('uuid', array_map('strtolower', $in['unitIds']))->get()->keyBy('uuid');
            $allocations = CheckAllocation::query()->where('check_id', new ObjectId($from->getKey()))->where('active', true)
                ->whereIn('order_item_unit_id', $units->map(fn ($u) => new ObjectId($u->getKey()))->values()->all())->get();
            $errors = [];
            foreach ($in['unitIds'] as $i => $uuid) {
                $unit = $units->get(strtolower($uuid));
                if (! $unit || ! $allocations->contains(fn ($a) => (string) $a->order_item_unit_id === (string) $unit->getKey())) {
                    $errors["unitIds.$i"] = 'La unidad no está en esta cuenta.';
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $now = now();
            foreach ($allocations as $a) {
                if (CheckAllocation::query()->where('_id', $a->getKey())->where('active', true)->update(['active' => false, 'closed_at' => $now]) !== 1) {
                    throw new DomainConflict('VERSION_CONFLICT');
                }
                CheckAllocation::query()->create([
                    'branch_id' => $branchId, 'check_id' => new ObjectId($to->getKey()), 'order_item_unit_id' => new ObjectId((string) $a->order_item_unit_id),
                    'active' => true, 'created_by' => $actorId, 'closed_at' => null,
                ]);
            }
            CheckAdjustment::query()->whereIn('order_item_unit_id', $allocations->map(fn ($a) => new ObjectId((string) $a->order_item_unit_id))->all())
                ->where('active', true)->update(['check_id' => new ObjectId($to->getKey())]);
            self::save($from, (int) $from->version, []);
            self::save($to, (int) $to->version, []);
            $moved = $units->keys()->values()->all();
            Audit::record('check.units_moved', 'check', $from->getKey(), $branchId, $actorId, changes: [
                'units' => $moved, 'from' => $from->uuid, 'to' => $to->uuid,
            ]);
            Outbox::publish('check.units_moved', 'check', $from->getKey(), $branchId, ['checkId' => $from->uuid, 'targetCheckId' => $to->uuid, 'unitIds' => $moved]);
        });

        return $source->refresh();
    }

    /** OPEN → REQUESTED, con al menos una unidad que se cobre. Ya no recibe unidades nuevas (D-17). */
    public static function request(Branch $branch, User $actor, Check $check, int $version): Check
    {
        return self::change($branch, $actor, $check, $version, [Check::OPEN], 'check.requested', null, function (Check $c, int $billed) {
            if ($billed === 0) {
                throw new DomainConflict('CHECK_EMPTY');
            }

            return ['status' => Check::REQUESTED, 'requested_at' => now()];
        });
    }

    /** REQUESTED → OPEN, con permiso directo o token CHECK_REOPEN (C-9), sin pagos. */
    public static function reopen(Branch $branch, User $actor, Membership $membership, Check $check, int $version, string $reason, ?string $token): Check
    {
        $branchId = new ObjectId($branch->getKey());

        return self::change($branch, $actor, $check, $version, [Check::REQUESTED], 'check.reopened', $reason, function (Check $c) use ($branchId, $membership, $reason, $token) {
            self::assertNoPayments([$c]);
            self::authorize($branchId, $membership, 'checks.reopen', 'CHECK_REOPEN', [$c->uuid], $reason, $token);

            return ['status' => Check::OPEN, 'requested_at' => null];
        });
    }

    /** Solo una cuenta sin unidades que se cobren y sin pagos. */
    public static function cancel(Branch $branch, User $actor, Check $check, int $version, string $reason): Check
    {
        return self::change($branch, $actor, $check, $version, [Check::OPEN, Check::REQUESTED], 'check.cancelled', $reason, function (Check $c, int $billed) {
            if ($billed > 0) {
                throw new DomainConflict('CHECK_NOT_EMPTY');
            }
            self::assertNoPayments([$c]);

            return ['status' => Check::CANCELLED];
        });
    }

    /**
     * Cortesía (C-7): lleva a cero una unidad completa, con motivo y permiso o token CHECK_ADJUSTMENT. La
     * unidad conserva su estado y el inventario no cambia. Si la cuenta llega a 0, queda PAID (C-8).
     */
    public static function courtesy(Branch $branch, User $actor, Membership $membership, Check $check, int $version, string $unitUuid, string $reason, ?string $token): Check
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        return self::change($branch, $actor, $check, $version, [Check::OPEN, Check::REQUESTED], 'check.adjustment_applied', $reason, function (Check $c) use ($branchId, $actorId, $membership, $unitUuid, $reason, $token) {
            self::assertNoPayments([$c]);
            $line = Totals::lines($c)->first(fn ($l) => $l['unit']->uuid === strtolower($unitUuid));
            if (! $line) {
                throw ValidationException::withMessages(['unitId' => 'La unidad no está en esta cuenta.']);
            }
            if (! $line['billed'] || $line['adjustment']) {
                throw new DomainConflict('ORDER_UNIT_INVALID_STATE', ['unitIds' => [$line['unit']->uuid]]);
            }
            $approvedBy = self::authorize($branchId, $membership, 'checks.adjust', 'CHECK_ADJUSTMENT', [$line['unit']->uuid], $reason, $token);
            CheckAdjustment::query()->create([
                'branch_id' => $branchId, 'check_id' => new ObjectId($c->getKey()), 'order_item_unit_id' => new ObjectId($line['unit']->getKey()),
                'type' => CheckAdjustment::COURTESY, 'amount' => Floor::decimal($line['price']), 'reason' => $reason,
                'approved_by' => $approvedBy, 'active' => true, 'created_by' => $actorId,
            ]);

            return [];
        }, zeroPays: true);
    }

    public static function revertCourtesy(Branch $branch, User $actor, Membership $membership, Check $check, int $version, string $adjustmentUuid, string $reason, ?string $token): Check
    {
        $branchId = new ObjectId($branch->getKey());
        $adjustment = CheckAdjustment::query()->where('branch_id', $branchId)->where('check_id', new ObjectId($check->getKey()))
            ->where('uuid', Floor::uuid($adjustmentUuid))->first() ?? throw new NotFoundHttpException;

        return self::change($branch, $actor, $check, $version, [Check::OPEN, Check::REQUESTED], 'check.adjustment_reverted', $reason, function (Check $c) use ($branchId, $membership, $adjustment, $reason, $token) {
            self::assertNoPayments([$c]);
            self::authorize($branchId, $membership, 'checks.adjust', 'CHECK_ADJUSTMENT', [$adjustment->uuid], $reason, $token);
            // Revertir dos veces no tiene efecto, pero la versión de la cuenta ya cambió con la primera.
            CheckAdjustment::query()->where('_id', $adjustment->getKey())->where('active', true)->update(['active' => false, 'reverted_at' => now()]);

            return [];
        });
    }

    // ---------------------------------------------------------------- Ayudas

    /**
     * Cambio con If-Match: lee la cuenta en la transacción, revisa versión y estado, aplica $work (que
     * recibe la cuenta y cuántas unidades se cobran) y guarda los totales recalculados.
     */
    private static function change(Branch $branch, User $actor, Check $check, int $version, array $states, string $event, ?string $reason, \Closure $work, bool $zeroPays = false): Check
    {
        $branchId = new ObjectId($branch->getKey());
        Mongo::transaction(function () use ($branchId, $actor, $check, $version, $states, $event, $reason, $work, $zeroPays) {
            $fresh = self::fresh($check, $version, $states);
            [, $billed] = Totals::compute($fresh);
            $before = $fresh->status;
            $changes = $work($fresh, $billed);
            self::save($fresh, $version, $changes, $zeroPays);
            Audit::record($event, 'check', $fresh->getKey(), $branchId, new ObjectId($actor->getKey()), reason: $reason, changes: [
                'before' => ['status' => $before], 'after' => ['status' => $fresh->status, 'total' => (string) $fresh->total],
            ]);
            Outbox::publish($event, 'check', $fresh->getKey(), $branchId, ['checkId' => $fresh->uuid]);
        });

        return $check->refresh();
    }

    /** Guarda cambios y totales con la versión esperada. Con $zeroPays, una cuenta con unidades y total 0 queda PAID. */
    private static function save(Check $check, int $expected, array $changes, bool $zeroPays = false): void
    {
        [$totals, $billed] = Totals::compute($check);
        $changes = array_merge($totals, $changes);
        if ($zeroPays && $billed > 0 && bccomp((string) $totals['balance'], '0', 2) === 0) {
            $changes += ['status' => Check::PAID, 'paid_at' => now()];
        }
        if (! $check->updateIfVersion($expected, $changes)) {
            throw new DomainConflict('VERSION_CONFLICT');
        }
    }

    /** Recalcula los totales de una cuenta tras un cambio de sus unidades, sin If-Match. */
    private static function refresh(Check $check): void
    {
        $fresh = Check::query()->find($check->getKey());
        self::save($fresh, (int) $fresh->version, []);
    }

    /** Cambio que hace el sistema, con auditoría sin actor. */
    private static function system(Check $check, array $changes, string $event, string $reason): void
    {
        self::save($check, (int) $check->version, $changes);
        Audit::record($event, 'check', $check->getKey(), (string) $check->branch_id, reason: $reason, changes: ['after' => ['status' => $changes['status']]]);
        Outbox::publish($event, 'check', $check->getKey(), (string) $check->branch_id, ['checkId' => $check->uuid]);
    }

    /** @param list<string> $states */
    private static function fresh(Check $check, int $version, array $states): Check
    {
        $fresh = Check::query()->find($check->getKey());
        if ((int) $fresh->version !== $version) {
            throw new DomainConflict('VERSION_CONFLICT');
        }
        if (! in_array($fresh->status, $states, true)) {
            throw new DomainConflict('CHECK_INVALID_STATE');
        }

        return $fresh;
    }

    private static function open(ObjectId $branchId, TableSession $session, ObjectId $actorId, ?string $label): Check
    {
        $sessionId = new ObjectId($session->getKey());
        $number = (int) Check::query()->where('branch_id', $branchId)->where('session_id', $sessionId)->max('number') + 1;
        $zero = Floor::decimal('0');
        $check = Check::query()->create([
            'branch_id' => $branchId, 'session_id' => $sessionId, 'number' => $number, 'label' => $label, 'status' => Check::OPEN,
            'subtotal' => $zero, 'tax_total' => $zero, 'adjustment_total' => $zero, 'total' => $zero, 'paid_total' => $zero,
            'refunded_total' => $zero, 'pending_total' => $zero, 'tip_total' => $zero, 'balance' => $zero,
            'requested_at' => null, 'paid_at' => null, 'business_date' => $session->business_date, 'created_by' => $actorId,
        ]);
        Audit::record('check.created', 'check', $check->getKey(), $branchId, $actorId, changes: ['after' => ['session' => $session->uuid, 'number' => $number]]);
        Outbox::publish('check.created', 'check', $check->getKey(), $branchId, ['checkId' => $check->uuid, 'sessionId' => $session->uuid]);

        return $check;
    }

    /** La sesión debe estar ACTIVE; su `activity_epoch` choca con el cierre. */
    private static function activeSession(ObjectId $branchId, string $uuid): TableSession
    {
        $session = TableSession::query()->where('branch_id', $branchId)->where('uuid', strtolower($uuid))->first()
            ?? throw ValidationException::withMessages(['sessionId' => 'La sesión no existe en esta sucursal.']);
        TableSession::query()->where('_id', new ObjectId($session->getKey()))->increment('activity_epoch');
        if ($session->refresh()->status !== TableSession::ACTIVE) {
            throw new DomainConflict('SESSION_NOT_ACTIVE');
        }

        return $session;
    }

    /** C-9: con el permiso, directo; sin él, con un token de aprobación ligado a los recursos y al motivo. */
    private static function authorize(ObjectId $branchId, Membership $membership, string $permission, string $action, array $resourceIds, string $reason, ?string $token): ?ObjectId
    {
        if ($membership->can($permission)) {
            return null;
        }
        if ($token === null || $token === '') {
            throw new DomainConflict('FORBIDDEN');
        }

        return Approvals::consume($branchId, $token, $action, $resourceIds, $reason);
    }

    /** @param list<Check> $checks */
    private static function assertNoPayments(array $checks): void
    {
        $ids = array_map(fn ($c) => new ObjectId($c->getKey()), $checks);
        if (self::payments($ids, ['CONFIRMED'])) {
            throw new DomainConflict('CHECK_HAS_CONFIRMED_PAYMENT');
        }
        if (self::payments($ids, self::OPEN_PAYMENT_STATES)) {
            throw new DomainConflict('CHECK_HAS_OPEN_PAYMENT');
        }
    }

    /**
     * Cuentas, de las dadas, con algún pago en esos estados.
     *
     * @param  list<ObjectId>  $checkIds
     * @return list<string>
     */
    private static function payments(array $checkIds, array $states): array
    {
        if (! $checkIds) {
            return [];
        }

        $payments = Payment::query()->whereIn('check_id', $checkIds)->whereIn('status', $states)->get(['_id', 'check_id']);
        if (in_array('CONFIRMED', $states, true)) {
            // Un pago ya reembolsado deja de estar vigente: su cuenta REFUNDED puede mover unidades (P-8).
            $refunded = Refund::query()->whereIn('payment_id', $payments->map(fn ($p) => new ObjectId($p->getKey()))->all())
                ->where('status', 'CONFIRMED')->get(['payment_id'])->map(fn ($r) => (string) $r->payment_id)->all();
            $payments = $payments->reject(fn ($p) => in_array((string) $p->getKey(), $refunded, true));
        }

        return $payments->map(fn ($p) => (string) $p->check_id)->unique()->values()->all();
    }
}
