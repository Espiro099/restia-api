<?php

namespace App\Inventory;

use App\Catalog\Catalog;
use App\Identity\MobileAuth;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/**
 * Recetas de producto. Cada publicación es una versión nueva e inmutable; la anterior pasa a SUPERSEDED
 * y el producto apunta a la nueva en `current_recipe_version`. START registra con qué versión consumió.
 */
final class Recipes
{
    /**
     * @param  Collection<int, Recipe>  $recipes
     * @return list<array<string, mixed>>
     */
    public static function present(Product $product, Collection $recipes): array
    {
        $ids = $recipes->flatMap(fn ($r) => array_map(fn ($i) => (string) $i['ingredient_id'], $r->items ?? []))->unique()->values();
        $uuids = Ingredient::query()->whereIn('_id', $ids->map(fn ($id) => new ObjectId($id))->all())->get()
            ->mapWithKeys(fn ($i) => [(string) $i->getKey() => $i->uuid]);

        return $recipes->map(fn (Recipe $r) => [
            'id' => $r->uuid,
            'productId' => $product->uuid,
            'recipeVersion' => (int) $r->recipe_version,
            'status' => $r->status,
            'items' => array_map(fn ($i) => [
                'ingredientId' => $uuids->get((string) $i['ingredient_id']),
                'quantity' => Quantity::out($i['quantity']),
            ], $r->items ?? []),
            'createdAt' => MobileAuth::time($r->created_at),
        ])->values()->all();
    }

    /**
     * Publica la versión siguiente, con If-Match sobre el producto: dos publicaciones simultáneas no
     * pueden tomar el mismo número. Cada ingrediente debe existir, estar activo y aparecer una vez.
     *
     * @param  list<array{ingredientId: string, quantity: string}>  $items
     */
    public static function publish(Branch $branch, User $actor, Product $product, int $version, array $items): Recipe
    {
        $byField = [];
        foreach ($items as $n => $item) {
            $byField["items.$n.ingredientId"] = $item['ingredientId'];
            if (bccomp($item['quantity'], '0', Quantity::SCALE) <= 0) {
                throw ValidationException::withMessages(["items.$n.quantity" => 'La cantidad debe ser mayor que cero.']);
            }
        }
        if (collect($byField)->map(fn ($u) => strtolower($u))->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Cada ingrediente puede aparecer una sola vez; suma las cantidades.']);
        }
        $ingredients = Ingredients::resolve($branch, $byField, active: true);
        $rows = array_map(fn ($item) => [
            'ingredient_id' => new ObjectId($ingredients->get(strtolower($item['ingredientId']))->getKey()),
            'quantity' => Quantity::decimal($item['quantity']),
        ], $items);
        $published = null;

        Catalog::change($branch, $actor, $product, $version, 'recipe.published', 'product', function (Product $fresh) {
            return ['current_recipe_version' => (int) $fresh->current_recipe_version + 1];
        }, after: function (Product $fresh, ObjectId $branchId) use ($actor, $rows, &$published) {
            $productId = new ObjectId($fresh->getKey());
            Recipe::query()->where('branch_id', $branchId)->where('product_id', $productId)->where('status', Recipe::ACTIVE)
                ->update(['status' => Recipe::SUPERSEDED]);
            $published = Recipe::query()->create([
                'branch_id' => $branchId, 'product_id' => $productId, 'recipe_version' => (int) $fresh->current_recipe_version,
                'status' => Recipe::ACTIVE, 'items' => $rows, 'created_by' => new ObjectId($actor->getKey()),
            ]);

            return ['before' => [], 'after' => ['recipe' => $published->uuid, 'items' => count($rows)]];
        });

        return $published;
    }
}
