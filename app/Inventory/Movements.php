<?php

namespace App\Inventory;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\OrderItemUnit;
use App\Models\User;
use App\Support\Audit;
use App\Support\Idempotency;
use App\Support\Outbox;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/**
 * Movimientos a mano (I-5): ADJUSTMENT, con signo, para corregir o cargar el stock inicial, y WASTE,
 * positivo, que resta. Son inmutables: un error se corrige con otro movimiento. Como START, no escriben
 * en el ingrediente; el stock se actualiza después (I-1).
 */
final class Movements
{
    /**
     * @param  array{ingredientId: string, type: string, quantity: string, reason: string, orderUnitId?: string}  $in
     * @return array{0: int, 1: InventoryMovement|array<string, mixed>}
     */
    public static function record(Branch $branch, User $actor, string $key, array $in, \Closure $present): array
    {
        if ($branch->status !== Branch::ACTIVE) {
            throw new DomainConflict('BRANCH_INACTIVE');
        }
        if (bccomp($in['quantity'], '0', Quantity::SCALE) === 0 || ($in['type'] === 'WASTE' && str_starts_with($in['quantity'], '-'))) {
            throw ValidationException::withMessages(['quantity' => $in['type'] === 'WASTE' ? 'El desperdicio es una cantidad positiva.' : 'El ajuste no puede ser cero.']);
        }
        $ingredient = Ingredients::resolve($branch, ['ingredientId' => $in['ingredientId']])->first();
        $branchId = new ObjectId($branch->getKey());
        $unit = null;
        if (isset($in['orderUnitId'])) {
            $unit = OrderItemUnit::query()->where('branch_id', $branchId)->where('uuid', strtolower($in['orderUnitId']))->first()
                ?? throw ValidationException::withMessages(['orderUnitId' => 'La unidad no existe en esta sucursal.']);
        }
        $actorId = new ObjectId($actor->getKey());
        $quantity = $in['type'] === 'WASTE' ? '-'.$in['quantity'] : $in['quantity'];

        return Idempotency::run($branchId, $key, $actorId, 'createInventoryMovement', Idempotency::hash('createInventoryMovement', $in), function () use ($branchId, $actorId, $key, $in, $ingredient, $unit, $quantity, $present) {
            $m = InventoryMovement::query()->create([
                'branch_id' => $branchId, 'ingredient_id' => new ObjectId($ingredient->getKey()), 'type' => $in['type'],
                'quantity' => Quantity::decimal($quantity), 'source_type' => $unit ? 'order_unit' : null,
                'source_id' => $unit ? new ObjectId($unit->getKey()) : null, 'source_uuid' => $unit?->uuid, 'recipe_version' => null,
                'reason' => $in['reason'], 'override' => false, 'approved_by' => null, 'self_approved' => false,
                'idempotency_key' => 'MANUAL:'.$key, 'projected_at' => null, 'created_by' => $actorId,
            ]);
            Audit::record('inventory.movement_created', 'inventory_movement', $m->getKey(), $branchId, $actorId, reason: $in['reason'], changes: [
                'after' => ['ingredient_id' => (string) $ingredient->getKey(), 'type' => $in['type'], 'quantity' => Quantity::out($quantity)],
            ]);
            Outbox::publish('inventory.movement_created', 'inventory_movement', $m->getKey(), $branchId, ['movementId' => $m->uuid, 'ingredientId' => $ingredient->uuid]);

            return [201, $present($m), $m->uuid];
        });
    }
}
