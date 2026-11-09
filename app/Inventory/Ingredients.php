<?php

namespace App\Inventory;

use App\Catalog\Catalog;
use App\Exceptions\DomainConflict;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/** Ingredientes (I-8) y su bloqueo alimentario (I-4). Ningún override permite usar uno bloqueado (D-11). */
final class Ingredients
{
    /**
     * `stockAsOf`: todo lo anterior a esa hora ya está en `stock`. Si hay movimientos sin proyectar, es
     * la hora del más antiguo de ellos; si no, ahora.
     *
     * @param  Collection<int, Ingredient>  $ingredients
     * @return list<array<string, mixed>>
     */
    public static function present(Branch $branch, Collection $ingredients): array
    {
        $pending = InventoryMovement::query()->whereNull('projected_at')
            ->whereIn('ingredient_id', $ingredients->map(fn ($i) => new ObjectId($i->getKey()))->all())->get(['ingredient_id', 'created_at'])
            ->groupBy(fn ($m) => (string) $m->ingredient_id)->map(fn ($rows) => $rows->min('created_at'));
        $now = now();

        return $ingredients->map(function (Ingredient $i) use ($branch, $pending, $now) {
            $block = $i->food_safety_block ? (array) $i->food_safety_block : null;

            return [
                'id' => $i->uuid,
                'branchId' => $branch->uuid,
                'code' => $i->code,
                'name' => $i->name,
                'baseUnit' => $i->base_unit,
                'minStock' => Quantity::out($i->min_stock),
                'stock' => Quantity::out($i->stock_actual),
                'stockAsOf' => MobileAuth::time($pending->get((string) $i->getKey()) ?? $now),
                'status' => $i->status,
                'foodSafetyBlock' => $block ? ['reason' => $block['reason'], 'blockedAt' => MobileAuth::time(Carbon::instance($block['blocked_at']->toDateTime()))] : null,
                'version' => (int) $i->version,
            ];
        })->values()->all();
    }

    /** @param array{code: string, name: string, baseUnit: string, minStock?: string} $in */
    public static function create(Branch $branch, User $actor, array $in): Ingredient
    {
        return Catalog::create($branch, $actor, 'ingredient', 'CATALOG_CODE_TAKEN', fn (ObjectId $branchId) => Ingredient::query()->create([
            'branch_id' => $branchId, 'code' => $in['code'], 'name' => $in['name'], 'base_unit' => $in['baseUnit'],
            'min_stock' => Quantity::decimal($in['minStock'] ?? '0'), 'stock_actual' => Quantity::decimal('0'),
            'status' => Ingredient::ACTIVE, 'food_safety_block' => null,
        ]), menu: false);
    }

    /** `baseUnit` no se cambia: afectaría a recetas y movimientos ya registrados. */
    public static function update(Branch $branch, User $actor, Ingredient $i, int $version, array $in): Ingredient
    {
        return Catalog::change($branch, $actor, $i, $version, 'ingredient.updated', 'ingredient', function () use ($in) {
            $changes = [];
            if (array_key_exists('name', $in)) {
                $changes['name'] = $in['name'];
            }
            if (array_key_exists('minStock', $in)) {
                $changes['min_stock'] = Quantity::decimal($in['minStock']);
            }

            return $changes;
        }, menu: false);
    }

    /** INGREDIENT_IN_USE si una receta vigente lo usa; los productos afectados van en `details`. */
    public static function setStatus(Branch $branch, User $actor, Ingredient $i, int $version, string $status, string $reason): Ingredient
    {
        if ($i->status === $status) {
            return $i;
        }

        return Catalog::change($branch, $actor, $i, $version, 'ingredient.status_changed', 'ingredient', function (Ingredient $fresh) use ($status) {
            if ($status === Ingredient::INACTIVE) {
                $productIds = Recipe::query()->where('branch_id', new ObjectId((string) $fresh->branch_id))->where('status', Recipe::ACTIVE)
                    ->where('items.ingredient_id', new ObjectId($fresh->getKey()))->get(['product_id'])
                    ->map(fn ($r) => new ObjectId((string) $r->product_id))->all();
                if ($productIds) {
                    throw new DomainConflict('INGREDIENT_IN_USE', [
                        'productIds' => Product::query()->whereIn('_id', $productIds)->pluck('uuid')->values()->all(),
                    ]);
                }
            }

            return ['status' => $status];
        }, reason: $reason, menu: false);
    }

    /**
     * Bloqueo alimentario, sin If-Match: una medida de seguridad no debe perder ante una edición
     * simultánea. KITCHEN puede bloquear porque suele detectarlo primero; solo un gerente desbloquea.
     * Repetirlo no cambia nada.
     */
    public static function foodSafety(Branch $branch, User $actor, Membership $membership, Ingredient $i, bool $blocked, string $reason): Ingredient
    {
        if (! $blocked && ! in_array($membership->role, ['ADMIN', 'MANAGER'], true)) {
            throw new DomainConflict('FORBIDDEN');
        }
        $branchId = new ObjectId($branch->getKey());
        Mongo::transaction(function () use ($branchId, $actor, $i, $blocked, $reason) {
            $fresh = Ingredient::query()->find($i->getKey());
            if ((bool) $fresh->food_safety_block === $blocked) {
                return;
            }
            $value = $blocked ? ['reason' => $reason, 'blocked_at' => new UTCDateTime, 'blocked_by' => new ObjectId($actor->getKey())] : null;
            if (! $fresh->updateIfVersion((int) $fresh->version, ['food_safety_block' => $value])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            $event = $blocked ? 'ingredient.food_safety_blocked' : 'ingredient.food_safety_unblocked';
            Audit::record($event, 'ingredient', $fresh->getKey(), $branchId, new ObjectId($actor->getKey()), reason: $reason, changes: [
                'before' => ['blocked' => ! $blocked], 'after' => ['blocked' => $blocked],
            ]);
            // Sirve para revisar lo que ya esté en preparación con ese ingrediente.
            Outbox::publish($event, 'ingredient', $fresh->getKey(), $branchId, ['ingredientId' => $fresh->uuid]);
        });

        return $i->refresh();
    }

    /**
     * Ingredientes de la sucursal por UUID, para validar referencias: si alguno no existe, 422 en su campo.
     *
     * @param  array<string, string>  $byField  campo → UUID
     * @return Collection<string, Ingredient> por UUID
     */
    public static function resolve(Branch|ObjectId $branch, array $byField, bool $active = false): Collection
    {
        $branchId = $branch instanceof Branch ? new ObjectId($branch->getKey()) : $branch;
        $found = Ingredient::query()->where('branch_id', $branchId)->whereIn('uuid', array_map('strtolower', array_values($byField)))->get()->keyBy('uuid');
        $errors = [];
        foreach ($byField as $field => $uuid) {
            $i = $found->get(strtolower($uuid));
            if (! $i) {
                $errors[$field] = 'El ingrediente no existe en esta sucursal.';
            } elseif ($active && $i->status !== Ingredient::ACTIVE) {
                $errors[$field] = 'El ingrediente está inactivo.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $found;
    }
}
