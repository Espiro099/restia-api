<?php

namespace App\Inventory;

use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\User;
use App\Support\Audit;
use App\Support\Floor;
use App\Support\Idempotency;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;

/**
 * Recepciones de mercancía (I-6, I-9). Cada línea crea un movimiento RECEIPT que suma al stock cuando el
 * proceso de proyección lo aplica (I-1). Son inmutables: un error se corrige con un ADJUSTMENT. Guardan
 * el costo unitario, sin valuar el inventario en v1.
 */
final class Receipts
{
    /** @param Collection<int, InventoryReceipt> $rows */
    public static function present(Branch $branch, Collection $rows): array
    {
        $users = User::query()->whereIn('_id', $rows->pluck('created_by')->filter()->map(fn ($id) => new ObjectId((string) $id))->unique()->values()->all())
            ->get()->mapWithKeys(fn ($u) => [(string) $u->getKey() => $u->uuid]);

        return $rows->map(fn (InventoryReceipt $r) => [
            'id' => $r->uuid,
            'branchId' => $branch->uuid,
            'supplierName' => $r->supplier_name,
            'reference' => $r->reference,
            'lines' => array_map(fn ($l) => [
                'ingredientId' => $l['ingredient_uuid'],
                'quantity' => Quantity::out($l['quantity']),
                'unitCost' => ['amount' => self::cost($l['unit_cost']), 'currency' => Floor::CURRENCY],
            ], (array) $r->lines),
            'receivedAt' => MobileAuth::time($r->received_at),
            'createdBy' => $users->get((string) $r->created_by),
        ])->values()->all();
    }

    /**
     * @param  array{supplierName: string, reference?: ?string, receivedAt: string, lines: list<array{ingredientId: string, quantity: string, unitCost: array{amount: string}}>}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function create(Branch $branch, User $actor, string $key, array $in, \Closure $present): array
    {
        if ($branch->status !== Branch::ACTIVE) {
            throw new DomainConflict('BRANCH_INACTIVE');
        }
        $byField = [];
        foreach ($in['lines'] as $i => $line) {
            $byField["lines.$i.ingredientId"] = $line['ingredientId'];
        }
        $ingredients = Ingredients::resolve($branch, $byField);
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());

        return Idempotency::run($branchId, $key, $actorId, 'createInventoryReceipt', Idempotency::hash('createInventoryReceipt', $in), function () use ($branchId, $actorId, $in, $ingredients, $present) {
            $lines = array_map(function ($line) use ($ingredients) {
                $ingredient = $ingredients->get(strtolower($line['ingredientId']));

                return [
                    'ingredient_id' => new ObjectId($ingredient->getKey()), 'ingredient_uuid' => $ingredient->uuid,
                    'quantity' => Quantity::decimal($line['quantity']),
                    'unit_cost' => new Decimal128(bcadd($line['unitCost']['amount'], '0', 4)),
                ];
            }, $in['lines']);
            $receipt = InventoryReceipt::query()->create([
                'branch_id' => $branchId, 'supplier_name' => $in['supplierName'], 'reference' => $in['reference'] ?? null,
                'lines' => $lines, 'received_at' => Carbon::parse($in['receivedAt']), 'created_by' => $actorId,
            ]);
            foreach ($lines as $i => $line) {
                InventoryMovement::query()->create([
                    'branch_id' => $branchId, 'ingredient_id' => $line['ingredient_id'], 'type' => 'RECEIPT',
                    'quantity' => $line['quantity'], 'source_type' => 'inventory_receipt', 'source_id' => new ObjectId($receipt->getKey()),
                    'source_uuid' => $receipt->uuid, 'recipe_version' => null, 'reason' => null, 'override' => false,
                    'approved_by' => null, 'self_approved' => false, 'idempotency_key' => 'RECEIPT:'.$receipt->uuid.':'.$i,
                    'projected_at' => null, 'created_by' => $actorId,
                ]);
            }
            Audit::record('inventory.received', 'inventory_receipt', $receipt->getKey(), $branchId, $actorId, changes: [
                'after' => ['supplier' => $in['supplierName'], 'lines' => count($lines)],
            ]);
            Outbox::publish('inventory.received', 'inventory_receipt', $receipt->getKey(), $branchId, [
                'receiptId' => $receipt->uuid, 'ingredientIds' => array_values(array_unique(array_column($lines, 'ingredient_uuid'))),
            ]);

            return [201, $present($receipt), $receipt->uuid];
        });
    }

    /** MoneyNonNegative admite hasta 4 decimales: salen al menos 2 y sin ceros de más. */
    private static function cost(mixed $value): string
    {
        $text = rtrim(bcadd((string) $value, '0', 4), '0');
        [$int, $dec] = explode('.', $text);

        return $int.'.'.str_pad($dec, 2, '0');
    }
}
