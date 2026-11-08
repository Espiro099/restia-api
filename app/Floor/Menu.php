<?php

namespace App\Floor;

use App\Models\Branch;
use App\Models\Modifier;
use App\Models\Product;
use App\Support\Floor;
use MongoDB\BSON\ObjectId;

/**
 * GET /menu (M-9): productos activos y sus modificadores, sin recetas ni costos. El ETag es
 * `branches.menu_version`, que cada cambio de catálogo incrementa. Un producto agotado aparece
 * con `availability: SOLD_OUT` para que la app lo muestre sin dejar pedirlo.
 */
final class Menu
{
    public static function version(Branch $branch): int
    {
        return max(1, (int) $branch->menu_version);
    }

    /** @return array<string, mixed> */
    public static function build(Branch $branch): array
    {
        $branchId = new ObjectId($branch->getKey());
        $products = Product::query()->where('branch_id', $branchId)->where('status', Product::ACTIVE)->get()
            ->sortBy([['category', 'asc'], ['display_order', 'asc'], ['name', 'asc']])->values();
        $modifiers = Modifier::query()->where('branch_id', $branchId)->where('status', Modifier::ACTIVE)->get()
            ->keyBy(fn ($m) => (string) $m->getKey());

        $used = [];
        $items = $products->map(function (Product $p) use ($modifiers, &$used) {
            $ids = [];
            foreach ($p->modifier_ids ?? [] as $id) {
                $m = $modifiers->get((string) $id);
                if ($m) {
                    $ids[] = $m->uuid;
                    $used[(string) $id] = $m;
                }
            }

            return [
                'id' => $p->uuid,
                'code' => $p->code,
                'name' => $p->name,
                'description' => $p->description,
                'category' => $p->category,
                'displayOrder' => (int) $p->display_order,
                'price' => Floor::money($p->price),
                'availability' => $p->availability,
                'modifierIds' => $ids,
                'imageUrl' => $p->image_url,
            ];
        })->all();

        return [
            'branchId' => $branch->uuid,
            'menuVersion' => self::version($branch),
            'products' => $items,
            'modifiers' => array_values(array_map(fn (Modifier $m) => self::modifier($m), $used)),
        ];
    }

    /** Solo las opciones activas: una desactivada no se puede pedir (M-3). */
    private static function modifier(Modifier $m): array
    {
        $options = array_values(array_filter($m->options ?? [], fn ($o) => ($o['status'] ?? 'ACTIVE') === 'ACTIVE'));

        return [
            'id' => $m->uuid,
            'name' => $m->name,
            'minSelections' => (int) $m->min_selections,
            'maxSelections' => (int) $m->max_selections,
            'options' => array_map(fn ($o) => [
                'id' => $o['uuid'],
                'name' => $o['name'],
                'priceDelta' => Floor::money($o['price_delta'] ?? '0'),
            ], $options),
        ];
    }
}
