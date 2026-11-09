<?php

namespace App\Floor;

use App\Checks\Checks;
use App\Exceptions\DomainConflict;
use App\Inventory\Consumption;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemUnit;
use App\Models\User;
use App\Support\Audit;
use App\Support\Idempotency;
use App\Support\Outbox;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cambios de estado por lote (O-5): hasta 50 unidades, todo o nada, con la versión de cada una.
 *
 * START consume inventario según la receta vigente (O-6, I-1). CANCEL y VOID recalculan la cuenta y
 * respetan ORDER_UNIT_PAID (C-2).
 */
final class UnitTransitions
{
    /** Estado previo válido, estado nuevo, campo de hora, permiso y evento de cada acción (sección 3 de Orders). */
    public const ACTIONS = [
        'START' => ['from' => [OrderItemUnit::PENDING], 'to' => OrderItemUnit::PREPARING, 'at' => 'preparing_at', 'permission' => 'order_units.prepare', 'event' => 'order_unit.started'],
        'READY' => ['from' => [OrderItemUnit::PREPARING], 'to' => OrderItemUnit::READY, 'at' => 'ready_at', 'permission' => 'order_units.prepare', 'event' => 'order_unit.ready'],
        'DELIVER' => ['from' => [OrderItemUnit::READY], 'to' => OrderItemUnit::DELIVERED, 'at' => 'delivered_at', 'permission' => 'order_units.deliver', 'event' => 'order_unit.delivered'],
        'CANCEL' => ['from' => [OrderItemUnit::PENDING], 'to' => OrderItemUnit::CANCELLED, 'at' => 'cancelled_at', 'permission' => 'order_units.cancel', 'event' => 'order_unit.cancelled'],
        // O-3: también desde READY.
        'VOID' => ['from' => [OrderItemUnit::PREPARING, OrderItemUnit::READY], 'to' => OrderItemUnit::VOIDED, 'at' => 'voided_at', 'permission' => 'order_units.void', 'event' => 'order_unit.voided'],
    ];

    /**
     * Sin el permiso de la acción responde FORBIDDEN, salvo VOID con un token de aprobación (D-37).
     *
     * @param  array{action: string, units: list<array{id: string, version: int}>, reason?: string}  $in
     * @return array{0: int, 1: list<array<string, mixed>>}
     */
    public static function apply(Branch $branch, User $actor, Membership $membership, string $key, array $in, ?string $approvalToken): array
    {
        $action = self::ACTIONS[$in['action']];
        $withApproval = ! $membership->can($action['permission']);
        if ($withApproval && ($in['action'] !== 'VOID' || $approvalToken === null)) {
            throw new DomainConflict('FORBIDDEN');
        }

        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        $reason = $in['reason'] ?? null;
        $requested = collect($in['units'])->mapWithKeys(fn ($u) => [strtolower($u['id']) => (int) $u['version']]);

        return Idempotency::run($branchId, $key, $actorId, 'transitionOrderUnits', Idempotency::hash('transitionOrderUnits', $in), function () use ($branchId, $actorId, $in, $action, $withApproval, $approvalToken, $reason, $requested) {
            $units = OrderItemUnit::query()->where('branch_id', $branchId)->whereIn('uuid', $requested->keys()->all())->get()->keyBy('uuid');
            if ($units->count() !== $requested->count()) {
                throw new NotFoundHttpException;
            }

            // Primero el estado: dice más que la versión cuando alguien ya movió la unidad.
            $invalid = $units->filter(fn ($u) => ! in_array($u->status, $action['from'], true))->keys()->values()->all();
            if ($invalid) {
                throw new DomainConflict('ORDER_UNIT_INVALID_STATE', ['unitIds' => $invalid]);
            }
            $stale = $units->filter(fn ($u) => (int) $u->version !== $requested[$u->uuid])->keys()->values()->all();
            if ($stale) {
                throw new DomainConflict('VERSION_CONFLICT', ['unitIds' => $stale]);
            }

            // C-2: una unidad de una cuenta con pago confirmado ya no se cancela ni anula.
            if (in_array($in['action'], ['CANCEL', 'VOID'], true)) {
                $paid = Checks::paidUnits($units->map(fn ($u) => new ObjectId($u->getKey()))->values()->all());
                if ($paid) {
                    throw new DomainConflict('ORDER_UNIT_PAID', ['unitIds' => $units->filter(fn ($u) => in_array((string) $u->getKey(), array_map('strval', $paid), true))->keys()->values()->all()]);
                }
            }
            $approvedBy = $withApproval ? Approvals::consume($branchId, $approvalToken, 'ORDER_UNIT_VOID', $requested->keys()->all(), $reason) : null;
            // START consume inventario (O-6, I-1); un faltante se salta con un token INVENTORY_OVERRIDE.
            $consumption = $in['action'] === 'START' ? Consumption::plan($branchId, $units) : null;
            $overrideBy = $consumption ? Consumption::authorize($branchId, $consumption, $approvalToken, $requested->keys()->all(), $reason) : null;

            $now = now();
            $changes = ['status' => $action['to'], $action['at'] => $now, 'updated_at' => $now];
            if (in_array($in['action'], ['CANCEL', 'VOID'], true)) {
                $changes['reason'] = $reason;
            }
            foreach ($units as $u) {
                $unitChanges = $consumption ? $changes + ['recipe_version' => $consumption[$u->uuid]['recipe_version']] : $changes;
                $modified = OrderItemUnit::query()->where('_id', new ObjectId($u->getKey()))
                    ->where('version', (int) $u->version)->whereIn('status', $action['from'])
                    ->increment('version', 1, $unitChanges);
                if ($modified === 0) {
                    throw new DomainConflict('VERSION_CONFLICT', ['unitIds' => [$u->uuid]]);
                }
                Audit::record($action['event'], 'order_unit', $u->getKey(), $branchId, $actorId, reason: $reason, changes: array_filter([
                    'status' => ['from' => $u->status, 'to' => $action['to']],
                    'approvedBy' => $approvedBy ? (string) $approvedBy : null,
                    'inventoryOverrideBy' => $overrideBy ? (string) $overrideBy : null,
                ]));
                Outbox::publish($action['event'], 'order_unit', $u->getKey(), $branchId, ['unitId' => $u->uuid]);
            }

            // Lo cancelado o anulado deja de cobrarse (C-6).
            if (in_array($in['action'], ['CANCEL', 'VOID'], true)) {
                Checks::unitsChanged($units->map(fn ($u) => new ObjectId($u->getKey()))->values()->all());
            }
            if ($consumption) {
                Consumption::record($branchId, $consumption, $units, $actorId, $overrideBy, $reason);
            }

            $orderIds = $units->map(fn ($u) => (string) $u->order_id)->unique()->values();
            foreach ($orderIds as $orderId) {
                self::refreshOrder(new ObjectId($orderId));
            }

            $updated = OrderItemUnit::query()->whereIn('_id', $units->map(fn ($u) => new ObjectId($u->getKey()))->values()->all())->get();
            $refs = Refs::load(collect(), $updated->pluck('station_id'), collect(), collect());
            $orderUuids = Order::query()->whereIn('_id', $orderIds->map(fn ($id) => new ObjectId($id))->all())->get(['_id', 'uuid'])
                ->mapWithKeys(fn ($o) => [(string) $o->getKey() => $o->uuid]);
            $itemUuids = OrderItem::query()->whereIn('_id', $updated->map(fn ($u) => new ObjectId((string) $u->order_item_id))->unique()->values()->all())->get(['_id', 'uuid'])
                ->mapWithKeys(fn ($i) => [(string) $i->getKey() => $i->uuid]);
            $byUuid = $updated->keyBy('uuid');
            $data = $requested->keys()->map(fn ($id) => Orders::unit($byUuid[$id], $refs, $orderUuids, $itemUuids))->values()->all();

            return [200, $data, null];
        });
    }

    /** `orders.status` se deriva de sus unidades en la misma transacción (O-1). */
    private static function refreshOrder(ObjectId $orderId): void
    {
        $states = OrderItemUnit::query()->where('order_id', $orderId)->pluck('status')->unique()->all();
        $status = match (true) {
            (bool) array_intersect($states, Tables::ACTIVE_UNIT_STATES) => Order::OPEN,
            in_array(OrderItemUnit::DELIVERED, $states, true) => Order::COMPLETED,
            default => Order::CANCELLED,
        };
        Order::query()->where('_id', $orderId)->where('status', '!=', $status)
            ->increment('version', 1, ['status' => $status, 'updated_at' => now()]);
    }
}
