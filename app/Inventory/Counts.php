<?php

namespace App\Inventory;

use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\InventoryCount;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Support\Audit;
use App\Support\Idempotency;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Throwable;

/**
 * Conteos físicos (I-7). Cada línea guarda lo contado y su hora; lo esperado es la suma de los movimientos
 * hasta esa hora, no `stock_actual`, que puede ir atrasado (I-1). Al aplicar se recalcula lo esperado, por
 * si un movimiento anterior a la hora del conteo se confirmó después, y se crea un COUNT_ADJUSTMENT por la
 * diferencia: los consumos posteriores a la hora del conteo se conservan. Un solo conteo abierto por
 * sucursal evita aplicar dos veces la misma diferencia.
 */
final class Counts
{
    /** Tolerancia para el reloj del dispositivo: una hora de conteo más adelantada se rechaza. */
    private const CLOCK_SKEW_SECONDS = 120;

    /** @param Collection<int, InventoryCount> $rows */
    public static function present(Branch $branch, Collection $rows): array
    {
        $users = User::query()->whereIn('_id', $rows->pluck('created_by')->filter()->map(fn ($id) => new ObjectId((string) $id))->unique()->values()->all())
            ->get()->mapWithKeys(fn ($u) => [(string) $u->getKey() => $u->uuid]);

        return $rows->map(fn (InventoryCount $c) => [
            'id' => $c->uuid,
            'branchId' => $branch->uuid,
            'status' => $c->status,
            'notes' => $c->notes,
            'lines' => array_map(fn ($l) => [
                'ingredientId' => $l['ingredient_uuid'],
                'counted' => Quantity::out($l['counted']),
                'countedAt' => MobileAuth::time(self::carbon($l['counted_at'])),
                'expected' => Quantity::out($l['expected']),
                'difference' => Quantity::out($l['difference']),
            ], (array) ($c->lines ?? [])),
            'createdBy' => $users->get((string) $c->created_by),
            'createdAt' => MobileAuth::time($c->created_at),
            'appliedAt' => $c->applied_at ? MobileAuth::time($c->applied_at) : null,
            'version' => (int) $c->version,
        ])->values()->all();
    }

    /**
     * @param  array{notes?: ?string}  $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function create(Branch $branch, User $actor, string $key, array $in, \Closure $present): array
    {
        if ($branch->status !== Branch::ACTIVE) {
            throw new DomainConflict('BRANCH_INACTIVE');
        }
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        try {
            return Idempotency::run($branchId, $key, $actorId, 'createInventoryCount', Idempotency::hash('createInventoryCount', $in), function () use ($branchId, $actorId, $in, $present) {
                if (InventoryCount::query()->where('branch_id', $branchId)->where('status', InventoryCount::OPEN)->exists()) {
                    throw new DomainConflict('INVENTORY_COUNT_ALREADY_OPEN');
                }
                $count = InventoryCount::query()->create([
                    'branch_id' => $branchId, 'status' => InventoryCount::OPEN, 'notes' => $in['notes'] ?? null,
                    'lines' => [], 'created_by' => $actorId,
                ]);
                Audit::record('inventory_count.opened', 'inventory_count', $count->getKey(), $branchId, $actorId);

                return [201, $present($count), $count->uuid];
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('INVENTORY_COUNT_ALREADY_OPEN') : $e;
        }
    }

    /**
     * Reemplaza las líneas: el conteo se captura en varias pasadas y cada envío trae la lista completa.
     *
     * @param  list<array{ingredientId: string, counted: string, countedAt: string}>  $lines
     */
    public static function replaceLines(Branch $branch, User $actor, InventoryCount $count, int $version, array $lines): InventoryCount
    {
        $byField = [];
        $seen = [];
        $errors = [];
        $limit = now()->addSeconds(self::CLOCK_SKEW_SECONDS);
        foreach ($lines as $i => $line) {
            $uuid = strtolower($line['ingredientId']);
            if (isset($seen[$uuid])) {
                $errors["lines.$i.ingredientId"] = 'El ingrediente ya aparece en otra línea.';
            }
            $seen[$uuid] = true;
            if (Carbon::parse($line['countedAt'])->greaterThan($limit)) {
                $errors["lines.$i.countedAt"] = 'La hora del conteo no puede estar en el futuro.';
            }
            $byField["lines.$i.ingredientId"] = $line['ingredientId'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $ingredients = $byField ? Ingredients::resolve($branch, $byField) : collect();
        $branchId = new ObjectId($branch->getKey());

        Mongo::transaction(function () use ($branchId, $actor, $count, $version, $lines, $ingredients) {
            $fresh = self::fresh($count, $version);
            $stored = array_map(function ($line) use ($ingredients) {
                $ingredient = $ingredients->get(strtolower($line['ingredientId']));

                return self::line(new ObjectId($ingredient->getKey()), $ingredient->uuid, bcadd($line['counted'], '0', Quantity::SCALE), Carbon::parse($line['countedAt']));
            }, $lines);
            if (! $fresh->updateIfVersion($version, ['lines' => $stored])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record('inventory_count.lines_replaced', 'inventory_count', $fresh->getKey(), $branchId, new ObjectId($actor->getKey()), changes: [
                'after' => ['lines' => count($stored)],
            ]);
        });

        return $count->refresh();
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    public static function apply(Branch $branch, User $actor, InventoryCount $count, int $version, string $key, \Closure $present): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        $hash = Idempotency::hash('applyInventoryCount', ['count' => $count->uuid, 'version' => $version]);

        return Idempotency::run($branchId, $key, $actorId, 'applyInventoryCount', $hash, function () use ($branchId, $actorId, $count, $version, $present) {
            $fresh = self::fresh($count, $version);
            if (! $fresh->lines) {
                throw new DomainConflict('INVENTORY_COUNT_INVALID_STATE', ['reason' => 'El conteo no tiene líneas.']);
            }
            $lines = [];
            $adjusted = [];
            foreach ($fresh->lines as $line) {
                $at = self::carbon($line['counted_at']);
                $line = self::line($line['ingredient_id'], $line['ingredient_uuid'], (string) $line['counted'], $at);
                $lines[] = $line;
                $difference = (string) $line['difference'];
                if (bccomp($difference, '0', Quantity::SCALE) === 0) {
                    continue;
                }
                InventoryMovement::query()->create([
                    'branch_id' => $branchId, 'ingredient_id' => $line['ingredient_id'], 'type' => 'COUNT_ADJUSTMENT',
                    'quantity' => Quantity::decimal($difference), 'source_type' => 'inventory_count', 'source_id' => new ObjectId($fresh->getKey()),
                    'source_uuid' => $fresh->uuid, 'recipe_version' => null, 'reason' => 'Conteo físico', 'override' => false,
                    'approved_by' => null, 'self_approved' => false, 'idempotency_key' => 'COUNT:'.$fresh->uuid.':'.$line['ingredient_uuid'],
                    'projected_at' => null, 'created_by' => $actorId,
                ]);
                $adjusted[] = ['ingredientId' => $line['ingredient_uuid'], 'difference' => Quantity::out($difference)];
            }
            if (! $fresh->updateIfVersion($version, [
                'status' => InventoryCount::APPLIED, 'lines' => $lines, 'applied_by' => $actorId, 'applied_at' => now(),
            ])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record('inventory_count.applied', 'inventory_count', $fresh->getKey(), $branchId, $actorId, changes: [
                'before' => ['status' => InventoryCount::OPEN], 'after' => ['status' => InventoryCount::APPLIED, 'adjustments' => $adjusted],
            ]);
            Outbox::publish('inventory.count_applied', 'inventory_count', $fresh->getKey(), $branchId, ['countId' => $fresh->uuid, 'adjustments' => $adjusted]);

            return [200, $present($fresh), $fresh->uuid];
        });
    }

    public static function cancel(Branch $branch, User $actor, InventoryCount $count, int $version, string $reason): InventoryCount
    {
        $branchId = new ObjectId($branch->getKey());
        Mongo::transaction(function () use ($branchId, $actor, $count, $version, $reason) {
            $fresh = self::fresh($count, $version);
            $actorId = new ObjectId($actor->getKey());
            if (! $fresh->updateIfVersion($version, [
                'status' => InventoryCount::CANCELLED, 'cancelled_by' => $actorId, 'cancelled_at' => now(), 'cancel_reason' => $reason,
            ])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record('inventory_count.cancelled', 'inventory_count', $fresh->getKey(), $branchId, $actorId, reason: $reason, changes: [
                'before' => ['status' => InventoryCount::OPEN], 'after' => ['status' => InventoryCount::CANCELLED],
            ]);
        });

        return $count->refresh();
    }

    /** Lo esperado a la hora del conteo: la suma de los movimientos del ingrediente creados hasta entonces. */
    public static function expected(ObjectId $ingredientId, Carbon $at): string
    {
        return InventoryMovement::query()->where('ingredient_id', $ingredientId)->where('created_at', '<=', $at)->get(['quantity'])
            ->reduce(fn ($carry, $m) => Quantity::add($carry, (string) $m->quantity), '0');
    }

    private static function line(ObjectId $ingredientId, string $uuid, string $counted, Carbon $at): array
    {
        $expected = self::expected($ingredientId, $at);

        return [
            'ingredient_id' => $ingredientId, 'ingredient_uuid' => $uuid,
            'counted' => Quantity::decimal($counted), 'counted_at' => new UTCDateTime($at),
            'expected' => Quantity::decimal($expected), 'difference' => Quantity::decimal(bcsub($counted, $expected, Quantity::SCALE)),
        ];
    }

    private static function fresh(InventoryCount $count, int $version): InventoryCount
    {
        $fresh = InventoryCount::query()->find($count->getKey());
        if ((int) $fresh->version !== $version) {
            throw new DomainConflict('VERSION_CONFLICT');
        }
        if ($fresh->status !== InventoryCount::OPEN) {
            throw new DomainConflict('INVENTORY_COUNT_INVALID_STATE');
        }

        return $fresh;
    }

    private static function carbon(mixed $value): Carbon
    {
        return $value instanceof UTCDateTime ? Carbon::instance($value->toDateTime()) : Carbon::parse($value);
    }
}
