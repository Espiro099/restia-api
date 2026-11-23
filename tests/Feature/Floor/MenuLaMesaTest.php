<?php

namespace Tests\Feature\Floor;

use App\Console\Commands\MenuLaMesa;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductPreparationRoute;
use App\Models\Station;
use MongoDB\BSON\ObjectId;
use Tests\Feature\Identity\IdentityTestCase;

class MenuLaMesaTest extends IdentityTestCase
{
    public function test_agrega_25_platillos_a_cocina_y_25_bebidas_a_barra(): void
    {
        $branch = $this->branch('CENTRO');
        $before = (int) $branch->menu_version;

        $this->artisan('restia:menu-la-mesa', ['--branch' => 'CENTRO'])
            ->expectsOutputToContain('se agregaron 50 productos')
            ->assertSuccessful();

        $branchId = new ObjectId($branch->getKey());
        $this->assertSame(50, Product::query()->where('branch_id', $branchId)->count());
        $kitchen = Station::query()->where('branch_id', $branchId)->where('code', 'COCINA')->firstOrFail();
        $bar = Station::query()->where('branch_id', $branchId)->where('code', 'BARRA')->firstOrFail();
        foreach ([[MenuLaMesa::DISHES, $kitchen], [MenuLaMesa::DRINKS, $bar]] as [$items, $station]) {
            $this->assertCount(25, $items);
            foreach ($items as [$code, , , $price]) {
                $product = Product::query()->where('branch_id', $branchId)->where('code', $code)->firstOrFail();
                $this->assertSame(Product::ACTIVE, $product->status);
                $this->assertSame(bcadd($price, '0', 2), (string) $product->price);
                $this->assertNotNull($product->fiscal);
                $route = ProductPreparationRoute::query()->where('product_id', new ObjectId($product->getKey()))->firstOrFail();
                $this->assertSame((string) $station->getKey(), (string) $route->station_id, $code);
            }
        }
        $this->assertSame(4, Modifier::query()->where('branch_id', $branchId)->count());
        $this->assertSame($before + 1, (int) $branch->fresh()->menu_version);
    }

    public function test_correrlo_otra_vez_no_duplica_ni_cambia_lo_editado(): void
    {
        $branch = $this->branch('CENTRO');
        $this->artisan('restia:menu-la-mesa', ['--branch' => 'CENTRO'])->assertSuccessful();
        $branchId = new ObjectId($branch->getKey());
        // El gerente cambió un precio (se respeta) y falta un producto (el comando lo vuelve a poner).
        Product::query()->where('branch_id', $branchId)->where('code', 'LM-FLAN')->update(['price' => '80.00']);
        $pozole = Product::query()->where('branch_id', $branchId)->where('code', 'LM-POZOLE')->firstOrFail();
        ProductPreparationRoute::query()->where('product_id', new ObjectId($pozole->getKey()))->delete();
        $pozole->delete();
        $version = (int) $branch->fresh()->menu_version;

        $this->artisan('restia:menu-la-mesa', ['--branch' => 'CENTRO'])
            ->expectsOutputToContain('se agregaron 1 productos')
            ->assertSuccessful();
        $this->artisan('restia:menu-la-mesa', ['--branch' => 'CENTRO'])
            ->expectsOutputToContain('no se agregó nada')
            ->assertSuccessful();

        $this->assertSame(50, Product::query()->where('branch_id', $branchId)->count());
        $this->assertSame('80.00', (string) Product::query()->where('branch_id', $branchId)->where('code', 'LM-FLAN')->value('price'));
        $this->assertSame(2, Station::query()->where('branch_id', $branchId)->count());
        $this->assertSame(4, Modifier::query()->where('branch_id', $branchId)->count());
        $this->assertSame($version + 1, (int) $branch->fresh()->menu_version);
    }

    public function test_sin_sucursal_clara_no_hace_nada(): void
    {
        $this->branch('CENTRO');
        $this->branch('OTRA');

        $this->artisan('restia:menu-la-mesa')->assertFailed();
        $this->assertSame(0, Product::query()->count());
    }
}
