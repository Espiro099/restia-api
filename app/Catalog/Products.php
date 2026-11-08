<?php

namespace App\Catalog;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductPreparationRoute;
use App\Models\Station;
use App\Models\User;
use App\Support\Floor;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/**
 * Productos del menú (M-1). Nacen inactivos: para venderse necesitan al menos una ruta a una
 * estación activa (M-2), que se define con PUT .../routes. Precio con impuestos incluidos (M-7).
 */
final class Products
{
    private const FIELDS = [
        'name' => 'name', 'description' => 'description', 'category' => 'category', 'displayOrder' => 'display_order',
        'imageUrl' => 'image_url',
    ];

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    public static function present(Branch $branch, Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }
        $branchId = new ObjectId($branch->getKey());
        $modifiers = Modifier::query()->where('branch_id', $branchId)->get()->mapWithKeys(fn ($m) => [(string) $m->getKey() => $m->uuid]);
        $routes = ProductPreparationRoute::query()->where('branch_id', $branchId)
            ->whereIn('product_id', $products->map(fn ($p) => new ObjectId($p->getKey()))->all())->get();
        $stations = Station::query()->where('branch_id', $branchId)->get()->mapWithKeys(fn ($s) => [(string) $s->getKey() => $s->uuid]);
        $byProduct = $routes->sortBy('priority')->groupBy(fn ($r) => (string) $r->product_id);

        return $products->map(fn (Product $p) => [
            'id' => $p->uuid,
            'branchId' => $branch->uuid,
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
            'category' => $p->category,
            'displayOrder' => (int) $p->display_order,
            'price' => Floor::money($p->price),
            'status' => $p->status,
            'availability' => $p->availability,
            'modifierIds' => collect($p->modifier_ids ?? [])->map(fn ($id) => $modifiers->get((string) $id))->filter()->values()->all(),
            'routes' => ($byProduct->get((string) $p->getKey()) ?? collect())->map(fn ($r) => [
                'stationId' => $stations->get((string) $r->station_id),
                'priority' => (int) $r->priority,
            ])->values()->all(),
            'currentRecipeVersion' => $p->current_recipe_version === null ? null : (int) $p->current_recipe_version,
            'fiscal' => self::fiscalOut($p->getAttribute('fiscal')),
            'imageUrl' => $p->image_url,
            'version' => (int) $p->version,
        ])->values()->all();
    }

    /** @param array<string, mixed> $in */
    public static function create(Branch $branch, User $actor, array $in): Product
    {
        $modifierIds = self::modifierIds($branch, $in['modifierIds'] ?? []);

        return Catalog::create($branch, $actor, 'product', 'CATALOG_CODE_TAKEN', fn (ObjectId $branchId) => Product::query()->create([
            'branch_id' => $branchId, 'code' => $in['code'], 'name' => $in['name'], 'description' => $in['description'] ?? null,
            'category' => $in['category'], 'display_order' => (int) ($in['displayOrder'] ?? 0), 'price' => self::price($in['price']),
            'status' => Product::INACTIVE, 'availability' => 'AVAILABLE', 'modifier_ids' => $modifierIds,
            'current_recipe_version' => null, 'fiscal' => self::fiscalIn($in['fiscal']), 'image_url' => $in['imageUrl'] ?? null,
        ]));
    }

    /** @param array<string, mixed> $in */
    public static function update(Branch $branch, User $actor, Product $p, int $version, array $in): Product
    {
        $changes = [];
        foreach (self::FIELDS as $field => $column) {
            if (array_key_exists($field, $in)) {
                $changes[$column] = $field === 'displayOrder' ? (int) $in[$field] : $in[$field];
            }
        }
        if (array_key_exists('price', $in)) {
            $changes['price'] = self::price($in['price']);
        }
        if (array_key_exists('modifierIds', $in)) {
            $changes['modifier_ids'] = self::modifierIds($branch, $in['modifierIds']);
        }
        if (array_key_exists('fiscal', $in)) {
            $changes['fiscal'] = self::fiscalIn($in['fiscal']);
        }

        return Catalog::change($branch, $actor, $p, $version, 'product.updated', 'product', fn () => $changes);
    }

    public static function setStatus(Branch $branch, User $actor, Product $p, int $version, string $status, string $reason): Product
    {
        if ($p->status === $status) {
            return $p;
        }

        return Catalog::change($branch, $actor, $p, $version, 'product.status_changed', 'product', function (Product $fresh) use ($status) {
            if ($status === Product::ACTIVE && ! self::sellable($fresh)) {
                throw new DomainConflict('PRODUCT_NOT_SELLABLE');
            }

            return ['status' => $status];
        }, reason: $reason);
    }

    /** Sin If-Match: prevalece la última escritura, para no frenar el servicio cuando algo se agota. */
    public static function setAvailability(Branch $branch, User $actor, Product $p, string $availability): Product
    {
        return Catalog::change($branch, $actor, $p, null, 'product.availability_changed', 'product', fn () => ['availability' => $availability]);
    }

    /**
     * Reemplaza las rutas: estaciones alternativas por prioridad, de las que cada unidad usa una (M-2).
     *
     * @param  list<array{stationId: string, priority: int}>  $routes
     */
    public static function setRoutes(Branch $branch, User $actor, Product $p, int $version, array $routes): Product
    {
        $branchId = new ObjectId($branch->getKey());
        $wanted = collect($routes)->map(fn ($r) => ['station' => strtolower($r['stationId']), 'priority' => (int) $r['priority']]);
        $stations = Station::query()->where('branch_id', $branchId)->whereIn('uuid', $wanted->pluck('station')->all())->get()->keyBy('uuid');
        $errors = [];
        foreach ($wanted as $i => $r) {
            $station = $stations->get($r['station']);
            if (! $station) {
                $errors["routes.$i.stationId"] = 'La estación no existe en esta sucursal.';
            } elseif ($station->status !== Station::ACTIVE) {
                $errors["routes.$i.stationId"] = 'La estación está inactiva.';
            }
        }
        if ($wanted->pluck('station')->duplicates()->isNotEmpty()) {
            $errors['routes'] = 'Cada estación puede aparecer una sola vez.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return Catalog::change($branch, $actor, $p, $version, 'product.routes_changed', 'product', function (Product $fresh) use ($wanted) {
            if ($fresh->status === Product::ACTIVE && $wanted->isEmpty()) {
                throw new DomainConflict('PRODUCT_NOT_SELLABLE');
            }

            return [];
        }, after: function (Product $fresh, ObjectId $branchId) use ($wanted, $stations) {
            $productId = new ObjectId($fresh->getKey());
            $query = fn () => ProductPreparationRoute::query()->where('branch_id', $branchId)->where('product_id', $productId);
            $before = $query()->get()->sortBy('priority')->map(fn ($r) => ['station_id' => (string) $r->station_id, 'priority' => (int) $r->priority])->values()->all();
            $query()->delete();
            foreach ($wanted as $r) {
                ProductPreparationRoute::query()->create([
                    'branch_id' => $branchId, 'product_id' => $productId,
                    'station_id' => new ObjectId($stations->get($r['station'])->getKey()), 'priority' => $r['priority'],
                ]);
            }
            $after = $wanted->sortBy('priority')->map(fn ($r) => ['station_id' => (string) $stations->get($r['station'])->getKey(), 'priority' => $r['priority']])->values()->all();

            return ['before' => ['routes' => $before], 'after' => ['routes' => $after]];
        });
    }

    /** Vendible: alguna de sus rutas lleva a una estación activa. */
    private static function sellable(Product $p): bool
    {
        $branchId = new ObjectId((string) $p->branch_id);
        $stationIds = ProductPreparationRoute::query()->where('branch_id', $branchId)->where('product_id', new ObjectId($p->getKey()))->get()
            ->map(fn ($r) => new ObjectId((string) $r->station_id))->all();

        return $stationIds && Station::query()->where('branch_id', $branchId)->whereIn('_id', $stationIds)->where('status', Station::ACTIVE)->exists();
    }

    /** @param array{amount: string, currency: string} $money */
    private static function price(array $money): string
    {
        return bcadd($money['amount'], '0', 2);
    }

    /**
     * @param  list<string>  $uuids
     * @return list<ObjectId>
     */
    private static function modifierIds(Branch $branch, array $uuids): array
    {
        $uuids = array_map('strtolower', $uuids);
        $found = Modifier::query()->forBranch($branch)->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        $errors = [];
        foreach ($uuids as $i => $uuid) {
            if (! $found->has($uuid)) {
                $errors["modifierIds.$i"] = 'El modificador no existe en esta sucursal.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return array_map(fn ($uuid) => new ObjectId($found->get($uuid)->getKey()), $uuids);
    }

    /** @param array<string, string> $fiscal */
    private static function fiscalIn(array $fiscal): array
    {
        return [
            'sat_product_code' => $fiscal['satProductCode'],
            'sat_unit_code' => $fiscal['satUnitCode'],
            'tax_object' => $fiscal['taxObject'],
            'tax_rate' => $fiscal['taxRate'],
        ];
    }

    private static function fiscalOut(mixed $fiscal): ?array
    {
        if (! $fiscal) {
            return null;
        }
        $fiscal = (array) $fiscal;

        return [
            'satProductCode' => $fiscal['sat_product_code'],
            'satUnitCode' => $fiscal['sat_unit_code'],
            'taxObject' => $fiscal['tax_object'],
            'taxRate' => $fiscal['tax_rate'],
        ];
    }
}
