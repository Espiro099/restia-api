<?php

namespace App\Payments;

use App\Cash\Cash;
use App\Checks\Checks;
use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\Check;
use App\Models\Payment;
use App\Models\Refund;
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
 * Pagos y reembolsos (Decisiones_Payments). El empleado registra el resultado de la terminal (P-1).
 * UNKNOWN nunca se supone: bloquea nuevos intentos en la cuenta hasta que un gerente lo concilie (P-4, P-5).
 */
final class Payments
{
    /** Cuentas que admiten un pago nuevo. */
    public const PAYABLE = [Check::OPEN, Check::REQUESTED, Check::PARTIALLY_PAID];

    // ---------------------------------------------------------------- Lectura

    /** @param Collection<int, Payment> $payments */
    public static function present(Branch $branch, Collection $payments): array
    {
        $refs = self::refs($payments, ['check_id' => Check::class, 'cash_shift_id' => CashShift::class, 'created_by' => User::class]);
        $refunds = Refund::query()->whereIn('payment_id', $payments->map(fn ($p) => new ObjectId($p->getKey()))->all())->where('active', true)->get()
            ->mapWithKeys(fn ($r) => [(string) $r->payment_id => $r->uuid]);
        $money = fn ($v) => $v === null ? null : Floor::money($v);

        return $payments->map(fn (Payment $p) => [
            'id' => $p->uuid,
            'branchId' => $branch->uuid,
            'checkId' => $refs['check_id']->get((string) $p->check_id),
            'cashShiftId' => $refs['cash_shift_id']->get((string) $p->cash_shift_id),
            'method' => $p->method,
            'status' => $p->status,
            'amount' => Floor::money($p->amount),
            'tip' => Floor::money($p->tip),
            'amountTendered' => $money($p->amount_tendered),
            'change' => $money($p->change),
            'card' => $p->card ? (array) $p->card : null,
            'reference' => $p->reference,
            'refundId' => $refunds->get((string) $p->getKey()),
            'createdBy' => $refs['created_by']->get((string) $p->created_by),
            'createdAt' => MobileAuth::time($p->created_at),
            'confirmedAt' => MobileAuth::time($p->confirmed_at),
            'version' => (int) $p->version,
        ])->values()->all();
    }

    /** @param Collection<int, Refund> $refunds */
    public static function presentRefunds(Branch $branch, Collection $refunds): array
    {
        $refs = self::refs($refunds, ['payment_id' => Payment::class, 'check_id' => Check::class, 'cash_shift_id' => CashShift::class, 'created_by' => User::class]);

        return $refunds->map(fn (Refund $r) => [
            'id' => $r->uuid,
            'branchId' => $branch->uuid,
            'paymentId' => $refs['payment_id']->get((string) $r->payment_id),
            'checkId' => $refs['check_id']->get((string) $r->check_id),
            'cashShiftId' => $refs['cash_shift_id']->get((string) $r->cash_shift_id),
            'status' => $r->status,
            'amount' => Floor::money($r->amount),
            'tip' => Floor::money($r->tip),
            'reference' => $r->reference,
            'reason' => $r->reason,
            'createdBy' => $refs['created_by']->get((string) $r->created_by),
            'createdAt' => MobileAuth::time($r->created_at),
            'confirmedAt' => MobileAuth::time($r->confirmed_at),
            'version' => (int) $r->version,
        ])->values()->all();
    }

    // ---------------------------------------------------------------- Pagos

    /**
     * Cobrar (P-2, P-3). El efectivo se confirma al crear y calcula el cambio; tarjeta y transferencia
     * quedan PENDING y reservan su monto. Sin If-Match: lo que importa es el saldo, y dos cobros
     * simultáneos chocan en la cuenta; quien reintenta recalcula y puede recibir PAYMENT_EXCEEDS_BALANCE.
     *
     * @param  array<string, mixed>  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function create(Branch $branch, User $actor, string $key, array $in): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        $cash = $in['method'] === 'CASH';
        $amount = bcadd($in['amount']['amount'], '0', 2);
        $tip = bcadd($in['tip']['amount'] ?? '0', '0', 2);
        if ($cash && ! isset($in['amountTendered'])) {
            throw ValidationException::withMessages(['amountTendered' => 'En efectivo indica cuánto entregó el cliente.']);
        }
        if (! $cash && isset($in['amountTendered'])) {
            throw ValidationException::withMessages(['amountTendered' => 'Solo aplica en efectivo.']);
        }
        $tendered = $cash ? bcadd($in['amountTendered']['amount'], '0', 2) : null;
        if ($cash && bccomp($tendered, bcadd($amount, $tip, 2), 2) < 0) {
            throw ValidationException::withMessages(['amountTendered' => 'Lo entregado no cubre el monto y la propina.']);
        }
        $check = self::find($branchId, Check::class, $in['checkId'], 'checkId');
        $shift = self::find($branchId, CashShift::class, $in['cashShiftId'], 'cashShiftId');

        return self::idempotent($branchId, $key, $actorId, 'createPayment', $in, function () use ($branch, $branchId, $actorId, $key, $in, $cash, $amount, $tip, $tendered, $check, $shift) {
            // El epoch del turno primero: choca con iniciar el cierre (sección 5).
            Cash::touch($shift);
            $fresh = Check::query()->find($check->getKey());
            if (! in_array($fresh->status, self::PAYABLE, true)) {
                throw new DomainConflict('CHECK_INVALID_STATE');
            }
            if (Payment::query()->where('check_id', new ObjectId($fresh->getKey()))->where('status', Payment::UNKNOWN)->exists()) {
                throw new DomainConflict('PAYMENT_REQUIRES_RECONCILIATION');
            }
            $available = Checks::available($fresh);
            if (bccomp($amount, $available, 2) > 0) {
                throw new DomainConflict('PAYMENT_EXCEEDS_BALANCE', ['available' => Floor::money($available)]);
            }
            $now = now();
            $p = Payment::query()->create([
                'branch_id' => $branchId, 'check_id' => new ObjectId($fresh->getKey()), 'cash_shift_id' => new ObjectId($shift->getKey()),
                'method' => $in['method'], 'status' => $cash ? Payment::CONFIRMED : Payment::PENDING,
                'amount' => Floor::decimal($amount), 'tip' => Floor::decimal($tip),
                'amount_tendered' => $tendered === null ? null : Floor::decimal($tendered),
                'change' => $tendered === null ? null : Floor::decimal(bcsub($tendered, bcadd($amount, $tip, 2), 2)),
                'card' => null, 'reference' => null, 'idempotency_key' => $key, 'created_by' => $actorId,
                'confirmed_at' => $cash ? $now : null, 'reconciled_by' => null, 'reconcile_reason' => null,
            ]);
            Checks::settle($fresh, starting: true);
            Audit::record('payment.created', 'payment', $p->getKey(), $branchId, $actorId, changes: [
                'after' => ['check' => $fresh->uuid, 'method' => $p->method, 'status' => $p->status, 'amount' => $amount, 'tip' => $tip],
            ]);
            Outbox::publish('payment.created', 'payment', $p->getKey(), $branchId, ['paymentId' => $p->uuid, 'checkId' => $fresh->uuid]);
            if ($cash) {
                Outbox::publish('payment.confirmed', 'payment', $p->getKey(), $branchId, ['paymentId' => $p->uuid, 'checkId' => $fresh->uuid]);
            }

            return [201, self::present($branch, collect([$p]))[0], $p->uuid];
        });
    }

    /**
     * Resultado de un PENDING. CONFIRMED exige la referencia de la terminal. Si no se pudo ver el
     * resultado, se registra UNKNOWN; nunca se supone. Se acepta aunque el turno esté en CLOSING.
     *
     * @param  array{outcome: string, reference?: string, card?: array<string, string>, note?: string}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function result(Branch $branch, User $actor, Payment $payment, int $version, string $key, array $in): array
    {
        self::requireReference($in);
        $hash = ['payment' => $payment->uuid, 'version' => $version] + $in;

        return self::idempotent(new ObjectId($branch->getKey()), $key, new ObjectId($actor->getKey()), 'recordPaymentResult', $hash, function () use ($branch, $actor, $payment, $version, $in) {
            $changes = ['status' => $in['outcome']];
            if ($in['outcome'] === Payment::CONFIRMED) {
                $changes += ['reference' => $in['reference'], 'card' => $in['card'] ?? null, 'confirmed_at' => now()];
            }
            $p = self::transition($actor, $payment, $version, [Payment::PENDING], 'PAYMENT_INVALID_STATE', $changes, 'payment.'.strtolower($in['outcome']), $in['note'] ?? null);

            return [200, self::present($branch, collect([$p]))[0], $p->uuid];
        });
    }

    /** P-5: un gerente resuelve un UNKNOWN con motivo; si se confirma, con la referencia. */
    public static function reconcile(Branch $branch, User $actor, Payment $payment, int $version, string $key, array $in): array
    {
        self::requireReference($in);
        $hash = ['payment' => $payment->uuid, 'version' => $version] + $in;

        return self::idempotent(new ObjectId($branch->getKey()), $key, new ObjectId($actor->getKey()), 'reconcilePayment', $hash, function () use ($branch, $actor, $payment, $version, $in) {
            $changes = ['status' => $in['outcome'], 'reconciled_by' => new ObjectId($actor->getKey()), 'reconcile_reason' => $in['reason']];
            if ($in['outcome'] === Payment::CONFIRMED) {
                $changes += ['reference' => $in['reference'], 'confirmed_at' => now()];
            }
            $p = self::transition($actor, $payment, $version, [Payment::UNKNOWN], 'PAYMENT_INVALID_STATE', $changes, 'payment.reconciled', $in['reason']);

            return [200, self::present($branch, collect([$p]))[0], $p->uuid];
        });
    }

    /** P-4: un PENDING de más de 15 minutos pasa a UNKNOWN, como proceso interno. */
    public static function expire(): int
    {
        $stale = Payment::query()->where('status', Payment::PENDING)->where('created_at', '<', now()->subMinutes((int) config('restia.payments.pending_minutes')))->get();
        $count = 0;
        foreach ($stale as $p) {
            $count += (int) Mongo::transaction(function () use ($p) {
                if (! $p->updateIfVersion((int) $p->version, ['status' => Payment::UNKNOWN])) {
                    return false;
                }
                Checks::settle(Check::query()->find((string) $p->check_id));
                Audit::record('payment.unknown', 'payment', $p->getKey(), (string) $p->branch_id, reason: 'Sin resultado tras 15 minutos.');
                Outbox::publish('payment.unknown', 'payment', $p->getKey(), (string) $p->branch_id, ['paymentId' => $p->uuid]);

                return true;
            });
        }

        return $count;
    }

    // ---------------------------------------------------------------- Reembolsos

    /**
     * Reembolso del pago completo (P-7, P-9, CA-7): mismo método, monto y propina, en un turno OPEN
     * actual. El efectivo se confirma al crear. No restaura inventario. Un solo reembolso activo por pago.
     *
     * @param  array{cashShiftId: string, reason: string}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function refund(Branch $branch, User $actor, Payment $payment, string $key, array $in): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        $shift = self::find($branchId, CashShift::class, $in['cashShiftId'], 'cashShiftId');

        try {
            return self::idempotent($branchId, $key, $actorId, 'createRefund', ['payment' => $payment->uuid] + $in, function () use ($branch, $branchId, $actorId, $key, $in, $payment, $shift) {
                Cash::touch($shift);
                $p = Payment::query()->find($payment->getKey());
                if ($p->status !== Payment::CONFIRMED) {
                    throw new DomainConflict('PAYMENT_NOT_REFUNDABLE');
                }
                if (Refund::query()->where('payment_id', new ObjectId($p->getKey()))->where('active', true)->exists()) {
                    throw new DomainConflict('REFUND_ALREADY_EXISTS');
                }
                $cash = $p->method === 'CASH';
                $r = Refund::query()->create([
                    'branch_id' => $branchId, 'payment_id' => new ObjectId($p->getKey()), 'check_id' => new ObjectId((string) $p->check_id),
                    'cash_shift_id' => new ObjectId($shift->getKey()), 'method' => $p->method, 'status' => $cash ? Refund::CONFIRMED : Refund::PENDING,
                    'active' => true, 'amount' => $p->amount, 'tip' => $p->tip, 'reference' => null, 'reason' => $in['reason'],
                    'idempotency_key' => $key, 'created_by' => $actorId, 'confirmed_at' => $cash ? now() : null, 'reconciled_by' => null,
                ]);
                Checks::settle(Check::query()->find((string) $p->check_id));
                Audit::record('refund.created', 'refund', $r->getKey(), $branchId, $actorId, reason: $in['reason'], changes: [
                    'after' => ['payment' => $p->uuid, 'method' => $r->method, 'status' => $r->status, 'amount' => (string) $r->amount, 'tip' => (string) $r->tip],
                ]);
                Outbox::publish('refund.created', 'refund', $r->getKey(), $branchId, ['refundId' => $r->uuid, 'paymentId' => $p->uuid]);
                if ($cash) {
                    // Fiscal decide qué hacer con un CFDI ya timbrado (24.11).
                    Outbox::publish('refund.confirmed', 'refund', $r->getKey(), $branchId, ['refundId' => $r->uuid, 'paymentId' => $p->uuid]);
                }

                return [201, self::presentRefunds($branch, collect([$r]))[0], $r->uuid];
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('REFUND_ALREADY_EXISTS') : $e;
        }
    }

    /** Resultado de un reembolso PENDING. FAILED lo deja inactivo y permite otro intento. */
    public static function refundResult(Branch $branch, User $actor, Refund $refund, int $version, string $key, array $in): array
    {
        self::requireReference($in);
        $hash = ['refund' => $refund->uuid, 'version' => $version] + $in;

        return self::idempotent(new ObjectId($branch->getKey()), $key, new ObjectId($actor->getKey()), 'recordRefundResult', $hash, function () use ($branch, $actor, $refund, $version, $in) {
            $r = self::transition($actor, $refund, $version, [Refund::PENDING], 'REFUND_INVALID_STATE', self::refundChanges($in), 'refund.'.strtolower($in['outcome']), $in['note'] ?? null);

            return [200, self::presentRefunds($branch, collect([$r]))[0], $r->uuid];
        });
    }

    public static function refundReconcile(Branch $branch, User $actor, Refund $refund, int $version, string $key, array $in): array
    {
        self::requireReference($in);
        $hash = ['refund' => $refund->uuid, 'version' => $version] + $in;

        return self::idempotent(new ObjectId($branch->getKey()), $key, new ObjectId($actor->getKey()), 'reconcileRefund', $hash, function () use ($branch, $actor, $refund, $version, $in) {
            $changes = self::refundChanges($in) + ['reconciled_by' => new ObjectId($actor->getKey())];
            $r = self::transition($actor, $refund, $version, [Refund::UNKNOWN], 'REFUND_INVALID_STATE', $changes, 'refund.reconciled', $in['reason']);

            return [200, self::presentRefunds($branch, collect([$r]))[0], $r->uuid];
        });
    }

    // ---------------------------------------------------------------- Ayudas

    private static function refundChanges(array $in): array
    {
        return match ($in['outcome']) {
            Refund::CONFIRMED => ['status' => Refund::CONFIRMED, 'reference' => $in['reference'], 'confirmed_at' => now()],
            Refund::FAILED => ['status' => Refund::FAILED, 'active' => false],
            default => ['status' => $in['outcome']],
        };
    }

    /**
     * Cambio de estado con If-Match, que vuelve a calcular la cuenta. Un pago o reembolso que se
     * resuelve solo reduce lo pendiente del turno, así que no escribe en él.
     */
    private static function transition(User $actor, Payment|Refund $doc, int $version, array $from, string $invalid, array $changes, string $event, ?string $reason): Payment|Refund
    {
        $fresh = $doc::query()->find($doc->getKey());
        if ((int) $fresh->version !== $version) {
            throw new DomainConflict('VERSION_CONFLICT');
        }
        if (! in_array($fresh->status, $from, true)) {
            throw new DomainConflict($invalid);
        }
        $before = $fresh->status;
        if (! $fresh->updateIfVersion($version, $changes)) {
            throw new DomainConflict('VERSION_CONFLICT');
        }
        Checks::settle(Check::query()->find((string) $fresh->check_id));
        $entity = $fresh instanceof Payment ? 'payment' : 'refund';
        Audit::record($event, $entity, $fresh->getKey(), (string) $fresh->branch_id, new ObjectId($actor->getKey()), reason: $reason, changes: [
            'before' => ['status' => $before], 'after' => ['status' => $fresh->status],
        ]);
        Outbox::publish($event, $entity, $fresh->getKey(), (string) $fresh->branch_id, [$entity.'Id' => $fresh->uuid]);

        return $fresh;
    }

    private static function requireReference(array $in): void
    {
        if ($in['outcome'] === 'CONFIRMED' && ! isset($in['reference'])) {
            throw ValidationException::withMessages(['reference' => 'Indica la referencia de la terminal o de la transferencia.']);
        }
    }

    private static function idempotent(ObjectId $branchId, string $key, ObjectId $actorId, string $operation, array $in, \Closure $work): array
    {
        return Idempotency::run($branchId, $key, $actorId, $operation, Idempotency::hash($operation, $in), $work);
    }

    /** @param class-string $model */
    private static function find(ObjectId $branchId, string $model, string $uuid, string $field): mixed
    {
        return $model::query()->where('branch_id', $branchId)->where('uuid', strtolower($uuid))->first()
            ?? throw ValidationException::withMessages([$field => 'No existe en esta sucursal.']);
    }

    /** @param array<string, class-string> $fields */
    private static function refs(Collection $rows, array $fields): array
    {
        $out = [];
        foreach ($fields as $field => $model) {
            $out[$field] = $model::query()->whereIn('_id', $rows->pluck($field)->filter()->map(fn ($id) => new ObjectId((string) $id))->unique()->values()->all())
                ->get()->mapWithKeys(fn ($d) => [(string) $d->getKey() => $d->uuid]);
        }

        return $out;
    }
}
