<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Membership;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductPreparationRoute;
use App\Models\RestaurantTable;
use App\Models\Station;
use App\Models\User;
use App\Support\Floor;
use App\Support\Mongo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

/**
 * Datos de prueba para recorrer el servicio en piso sin el panel Web, que aún no existe: mesas, estaciones,
 * modificadores y productos y, con --staff, un mesero, un cocinero y un gerente. No corre en producción.
 */
class Demo extends Command
{
    protected $signature = 'restia:demo
        {--branch= : Código de la sucursal; por omisión, la única que exista}
        {--staff : Crea mesero@demo.mx, cocina@demo.mx y gerente@demo.mx con la contraseña de RESTIA_DEMO_PASSWORD}';

    protected $description = 'Carga mesas, menú y empleados de prueba en una sucursal';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('restia:demo no corre en producción.');

            return self::FAILURE;
        }
        $branch = $this->option('branch')
            ? Branch::query()->where('code', $this->option('branch'))->first()
            : (Branch::query()->count() === 1 ? Branch::query()->first() : null);
        if (! $branch) {
            $this->error('Indica la sucursal con --branch; ejecuta antes restia:install.');

            return self::FAILURE;
        }
        $password = (string) env('RESTIA_DEMO_PASSWORD', '');
        if ($this->option('staff') && strlen($password) < 12) {
            $this->error('--staff requiere RESTIA_DEMO_PASSWORD con al menos 12 caracteres.');

            return self::FAILURE;
        }
        $branchId = new ObjectId($branch->getKey());
        if (Product::query()->where('branch_id', $branchId)->exists()) {
            $this->error('La sucursal ya tiene productos; restia:demo solo carga una sucursal vacía.');

            return self::FAILURE;
        }

        Mongo::transaction(function () use ($branchId, $password) {
            foreach ([[1, 4, 'Terraza'], [2, 4, 'Terraza'], [3, 2, 'Salón'], [4, 6, 'Salón'], [5, 2, 'Barra'], [6, 8, 'Salón']] as [$n, $cap, $zone]) {
                RestaurantTable::query()->create(['branch_id' => $branchId, 'number' => $n, 'capacity' => $cap, 'zone' => $zone, 'status' => RestaurantTable::ACTIVE, 'combination_group' => null]);
            }
            $kitchen = Station::query()->create(['branch_id' => $branchId, 'code' => 'COCINA', 'name' => 'Cocina', 'status' => Station::ACTIVE]);
            $bar = Station::query()->create(['branch_id' => $branchId, 'code' => 'BARRA', 'name' => 'Barra', 'status' => Station::ACTIVE]);

            $size = $this->modifier($branchId, 'TAMANO', 'Tamaño', 1, 1, [['Chico', '0'], ['Grande', '15']]);
            $extras = $this->modifier($branchId, 'EXTRAS', 'Extras', 0, 2, [['Queso extra', '12'], ['Aguacate', '18']]);

            $products = [
                ['AGUA', 'Agua mineral', 'Bebidas', 1, '35', [$size], $bar, 'AVAILABLE'],
                ['CAFE', 'Café americano', 'Bebidas', 2, '40', [$size], $bar, 'AVAILABLE'],
                ['LIMONADA', 'Limonada', 'Bebidas', 3, '45', [$size], $bar, 'SOLD_OUT'],
                ['TACOS', 'Tacos al pastor', 'Platos fuertes', 1, '95', [$extras], $kitchen, 'AVAILABLE'],
                ['ENCHILADAS', 'Enchiladas verdes', 'Platos fuertes', 2, '120', [$extras], $kitchen, 'AVAILABLE'],
                ['SOPA', 'Sopa del día', 'Entradas', 1, '70', [], $kitchen, 'AVAILABLE'],
            ];
            foreach ($products as [$code, $name, $category, $order, $price, $modifiers, $station, $availability]) {
                $product = Product::query()->create([
                    'branch_id' => $branchId, 'code' => $code, 'name' => $name, 'description' => null, 'category' => $category,
                    'display_order' => $order, 'price' => bcadd($price, '0', 2), 'status' => Product::ACTIVE, 'availability' => $availability,
                    'modifier_ids' => array_map(fn ($m) => new ObjectId($m->getKey()), $modifiers),
                    'current_recipe_version' => null, 'fiscal' => null, 'image_url' => null,
                ]);
                ProductPreparationRoute::query()->create(['branch_id' => $branchId, 'product_id' => new ObjectId($product->getKey()), 'station_id' => new ObjectId($station->getKey()), 'priority' => 1]);
            }
            Branch::query()->where('_id', $branchId)->increment('menu_version');

            if ($this->option('staff')) {
                foreach (['mesero' => 'WAITER', 'cocina' => 'KITCHEN', 'gerente' => 'MANAGER'] as $name => $role) {
                    $user = User::query()->create([
                        'email' => "$name@demo.mx", 'normalized_email' => "$name@demo.mx", 'name' => ucfirst($name).' de prueba',
                        'password_hash' => Hash::make($password), 'status' => User::ACTIVE,
                    ]);
                    Membership::query()->create(['user_id' => $user->getKey(), 'branch_id' => (string) $branchId, 'role' => $role, 'status' => Membership::ACTIVE]);
                }
            }
        });

        $this->info('Listo: 6 mesas, 2 estaciones, 2 modificadores y 6 productos'.($this->option('staff') ? ', más 3 empleados.' : '.'));

        return self::SUCCESS;
    }

    /** @param list<array{0: string, 1: string}> $options */
    private function modifier(ObjectId $branchId, string $code, string $name, int $min, int $max, array $options): Modifier
    {
        return Modifier::query()->create([
            'branch_id' => $branchId, 'code' => $code, 'name' => $name, 'min_selections' => $min, 'max_selections' => $max, 'status' => Modifier::ACTIVE,
            'options' => array_map(fn ($o) => ['uuid' => (string) Str::uuid(), 'name' => $o[0], 'price_delta' => Floor::decimal($o[1]), 'status' => 'ACTIVE'], $options),
        ]);
    }
}
