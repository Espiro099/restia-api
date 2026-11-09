<?php

namespace App\Inventory;

use App\Exceptions\DomainConflict;
use App\Floor\Approvals;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Modifier;
use App\Models\OrderItem;
use App\Models\OrderItemUnit;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;

/**
 * Consumo al pasar a PREPARING (O-6, I-1, I-2). START lee `stock_actual` sin escribirlo y crea un
 * movimiento por unidad e ingrediente en su misma transacción; el stock se actualiza segundos después.
 * Así los platillos que comparten un ingrediente no chocan en hora pico.
 */
final class Consumption
{
    /**
     * Lo que consume cada unidad: receta vigente de su producto más los ajustes de las opciones elegidas.
     * Una unidad de un producto sin receta no consume nada.
     *
     * @param  Collection<string, OrderItemUnit>  $units  por UUID
     * @return array<string, array{recipe_version: ?int, lines: array<string, string>}> por UUID de unidad; lines: ingrediente → cantidad
     */
    public static function plan(ObjectId $branchId, Collection $units): array
    {
        $items = OrderItem::query()->whereIn('_id', $units->map(fn ($u) => new ObjectId((string) $u->order_item_id))->unique()->values()->all())->get()
            ->keyBy(fn ($i) => (string) $i->getKey());
        $products = Product::query()->whereIn('_id', $items->map(fn ($i) => new ObjectId((string) $i->product_id))->unique()->values()->all())->get()
            ->keyBy(fn ($p) => (string) $p->getKey());
        $recipes = Recipe::query()->where('branch_id', $branchId)->where('status', Recipe::ACTIVE)
            ->whereIn('product_id', $products->map(fn ($p) => new ObjectId($p->getKey()))->values()->all())->get()
            ->keyBy(fn ($r) => (string) $r->product_id);
        $modifiers = Modifier::query()->where('branch_id', $branchId)
            ->whereIn('uuid', $items->flatMap(fn ($i) => array_column($i->modifiers_snapshot ?? [], 'modifier_id'))->unique()->values()->all())->get()
            ->keyBy('uuid');
        $adjusted = $modifiers->flatMap(fn ($m) => collect($m->options ?? [])->flatMap(fn ($o) => array_column($o['ingredient_adjustments'] ?? [], 'ingredient_id')));
        $ingredientIds = Ingredient::query()->where('branch_id', $branchId)->whereIn('uuid', $adjusted->unique()->values()->all())->get()
            ->mapWithKeys(fn ($i) => [$i->uuid => (string) $i->getKey()]);

        $plan = [];
        foreach ($units as $uuid => $u) {
            $item = $items[(string) $u->order_item_id];
            $recipe = $recipes->get((string) $item->product_id);
            $lines = [];
            foreach ($recipe?->items ?? [] as $line) {
                $id = (string) $line['ingredient_id'];
                $lines[$id] = Quantity::add($lines[$id] ?? '0', (string) $line['quantity']);
            }
            foreach ($item->modifiers_snapshot ?? [] as $chosen) {
                $option = collect($modifiers->get($chosen['modifier_id'])?->options ?? [])->firstWhere('uuid', $chosen['option_id']);
                foreach ($option['ingredient_adjustments'] ?? [] as $adj) {
                    $id = $ingredientIds->get($adj['ingredient_id']);
                    if ($id !== null) {
                        $lines[$id] = Quantity::add($lines[$id] ?? '0', (string) $adj['quantity']);
                    }
                }
            }
            $plan[$uuid] = ['recipe_version' => $recipe ? (int) $recipe->recipe_version : null, 'lines' => $lines];
        }

        return $plan;
    }

    /**
     * Un ingrediente bloqueado frena el START y ningún token lo salta (D-11). Si no alcanza el stock,
     * responde INVENTORY_INSUFFICIENT con los faltantes, salvo con un token INVENTORY_OVERRIDE ligado a
     * estas unidades y al motivo; entonces el stock puede quedar negativo. Devuelve quién aprobó.
     *
     * @param  array<string, array{recipe_version: ?int, lines: array<string, string>}>  $plan
     * @param  list<string>  $unitUuids
     */
    public static function authorize(ObjectId $branchId, array $plan, ?string $approvalToken, array $unitUuids, ?string $reason): ?ObjectId
    {
        $totals = [];
        foreach ($plan as $p) {
            foreach ($p['lines'] as $id => $qty) {
                $totals[$id] = Quantity::add($totals[$id] ?? '0', $qty);
            }
        }
        if (! $totals) {
            return null;
        }
        $ingredients = Ingredient::query()->whereIn('_id', array_map(fn ($id) => new ObjectId($id), array_keys($totals)))->get()
            ->keyBy(fn ($i) => (string) $i->getKey());
        $blocked = $ingredients->filter(fn ($i) => (bool) $i->food_safety_block)->pluck('uuid')->values()->all();
        if ($blocked) {
            throw new DomainConflict('INVENTORY_BLOCKED_FOOD_SAFETY', ['ingredientIds' => $blocked]);
        }
        $short = [];
        foreach ($totals as $id => $required) {
            $available = Quantity::out($ingredients[$id]->stock_actual);
            if (Quantity::less($available, $required)) {
                $short[] = ['ingredientId' => $ingredients[$id]->uuid, 'required' => Quantity::out($required), 'available' => $available];
            }
        }
        if (! $short) {
            return null;
        }
        if ($approvalToken === null || $approvalToken === '') {
            throw new DomainConflict('INVENTORY_INSUFFICIENT', ['ingredients' => $short]);
        }

        return Approvals::consume($branchId, $approvalToken, 'INVENTORY_OVERRIDE', $unitUuids, $reason);
    }

    /**
     * Un movimiento CONSUMPTION por unidad e ingrediente. La clave deriva de unidad, ingrediente y versión
     * de receta (I-2), así que repetirlo no consume dos veces.
     *
     * @param  array<string, array{recipe_version: ?int, lines: array<string, string>}>  $plan
     * @param  Collection<string, OrderItemUnit>  $units
     */
    public static function record(ObjectId $branchId, array $plan, Collection $units, ObjectId $actorId, ?ObjectId $approvedBy, ?string $reason): void
    {
        $ingredientUuids = Ingredient::query()->whereIn('_id', collect($plan)->flatMap(fn ($p) => array_keys($p['lines']))->unique()->map(fn ($id) => new ObjectId($id))->values()->all())
            ->get()->mapWithKeys(fn ($i) => [(string) $i->getKey() => $i->uuid]);
        foreach ($plan as $unitUuid => $p) {
            $unit = $units[$unitUuid];
            foreach ($p['lines'] as $ingredientId => $qty) {
                InventoryMovement::query()->create([
                    'branch_id' => $branchId,
                    'ingredient_id' => new ObjectId($ingredientId),
                    'type' => InventoryMovement::CONSUMPTION,
                    'quantity' => Quantity::decimal('-'.$qty),
                    'source_type' => 'order_unit',
                    'source_id' => new ObjectId($unit->getKey()),
                    'source_uuid' => $unit->uuid,
                    'recipe_version' => $p['recipe_version'],
                    'reason' => $approvedBy ? $reason : null,
                    'override' => $approvedBy !== null,
                    'approved_by' => $approvedBy,
                    // I-3: un gerente puede aprobarse a sí mismo, y queda marcado para revisión.
                    'self_approved' => $approvedBy !== null && (string) $approvedBy === (string) $actorId,
                    'idempotency_key' => 'CONSUMPTION:'.$unitUuid.':'.$ingredientUuids->get($ingredientId).':'.($p['recipe_version'] ?? 0),
                    'projected_at' => null,
                    'created_by' => $actorId,
                ]);
            }
        }
    }
}
