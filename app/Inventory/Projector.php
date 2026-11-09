<?php

namespace App\Inventory;

use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Support\Mongo;
use App\Support\Outbox;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

/**
 * Proyección de `stock_actual` (I-1): aplica con `$inc` los movimientos pendientes y los marca, en la
 * misma transacción, para que nada se aplique dos veces. Si el stock baja del mínimo publica
 * `ingredient.low_stock`; marcar el producto como agotado sigue siendo manual (I-10).
 */
final class Projector
{
    /** @return array{applied: int, low: int} */
    public static function run(int $limit = 500): array
    {
        $count = ['applied' => 0, 'low' => 0];
        $pending = InventoryMovement::query()->whereNull('projected_at')->orderBy('created_at')->orderBy('_id')->limit($limit)->get();
        foreach ($pending->groupBy(fn ($m) => (string) $m->ingredient_id) as $ingredientId => $movements) {
            try {
                $low = Mongo::transaction(function () use ($ingredientId, $movements) {
                    $ids = $movements->map(fn ($m) => new ObjectId($m->getKey()))->values()->all();
                    $now = new UTCDateTime;
                    // Otro proceso pudo aplicarlos ya: si no se marcan todos, se deshace y se reintenta luego.
                    if (InventoryMovement::query()->whereIn('_id', $ids)->whereNull('projected_at')->update(['projected_at' => $now]) !== count($ids)) {
                        throw new RuntimeException('Movimientos ya proyectados');
                    }
                    $sum = $movements->reduce(fn ($carry, $m) => Quantity::add($carry, (string) $m->quantity), '0');
                    $before = Ingredient::query()->find($ingredientId);
                    Ingredient::query()->where('_id', new ObjectId($ingredientId))->update([
                        '$inc' => ['stock_actual' => Quantity::decimal($sum)],
                        '$set' => ['stock_as_of' => $now],
                    ]);
                    $previous = Quantity::out($before->stock_actual);
                    $after = Quantity::add($previous, $sum);
                    $min = Quantity::out($before->min_stock);
                    if (! Quantity::less($previous, $min) && Quantity::less($after, $min)) {
                        Outbox::publish('ingredient.low_stock', 'ingredient', $before->getKey(), (string) $before->branch_id, [
                            'ingredientId' => $before->uuid, 'stock' => Quantity::out($after), 'minStock' => $min,
                        ]);

                        return 1;
                    }

                    return 0;
                });
            } catch (RuntimeException) {
                continue;
            }
            $count['applied'] += $movements->count();
            $count['low'] += $low;
        }

        return $count;
    }

    /**
     * Reconstruye `stock_actual` de cada ingrediente desde sus movimientos ya proyectados: la verdad está
     * en inventory_movements. Corre primero la proyección de lo pendiente.
     *
     * @return int ingredientes corregidos
     */
    public static function rebuild(): int
    {
        while (self::run()['applied'] > 0) {
            // Aplicar todo lo pendiente antes de recontar.
        }
        $fixed = 0;
        foreach (Ingredient::query()->get() as $ingredient) {
            $sum = InventoryMovement::query()->where('ingredient_id', new ObjectId($ingredient->getKey()))->whereNotNull('projected_at')->get(['quantity'])
                ->reduce(fn ($carry, $m) => Quantity::add($carry, (string) $m->quantity), '0');
            if (Quantity::out($sum) !== Quantity::out($ingredient->stock_actual)) {
                Ingredient::query()->where('_id', $ingredient->getKey())->update(['stock_actual' => Quantity::decimal($sum)]);
                $fixed++;
            }
        }

        return $fixed;
    }
}
