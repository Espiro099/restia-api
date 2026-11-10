<?php

namespace Tests\Feature\Floor;

use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;

/** Catálogo de Inventory: ingredientes, recetas, consumo en START (I-1, I-2, D-11) y su proyección. */
class InventoryTest extends FloorTestCase
{
    private function ingredient(string $code, string $unit = 'PIECE', string $min = '0'): string
    {
        return $this->as('gerente', 'POST', 'ingredients', ['code' => $code, 'name' => 'Ingrediente '.$code, 'baseUnit' => $unit, 'minStock' => $min])
            ->assertCreated()->json('data.id');
    }

    private function stock(string $ingredient, string $quantity): void
    {
        $this->as('gerente', 'POST', 'inventory-movements', ['ingredientId' => $ingredient, 'type' => 'ADJUSTMENT', 'quantity' => $quantity, 'reason' => 'Stock inicial'], $this->key())
            ->assertCreated();
        $this->project();
    }

    private function project(): void
    {
        $this->artisan('restia:inventory')->assertSuccessful();
    }

    private function publish(string $productCode, array $items, ?int $version = null): array
    {
        $product = Product::query()->where('code', $productCode)->firstOrFail();

        return $this->as('gerente', 'POST', "products/{$product->uuid}/recipes", ['items' => $items], ['If-Match' => '"'.($version ?? $product->version).'"'])
            ->assertCreated()->json('data');
    }

    private function stockOf(string $ingredient): string
    {
        return $this->as('cocina', 'GET', "ingredients/$ingredient")->json('data.stock');
    }

    /** Tacos: 3 tortillas y 80 g de carne; "Queso extra" agrega 30 g de queso. */
    private function tacosWithRecipe(): array
    {
        $tortilla = $this->ingredient('TORTILLA');
        $meat = $this->ingredient('PASTOR', 'G');
        $cheese = $this->ingredient('QUESO', 'G');
        $this->publish('TACOS', [['ingredientId' => $tortilla, 'quantity' => '3'], ['ingredientId' => $meat, 'quantity' => '80.5']]);
        $extras = $this->as('gerente', 'GET', 'modifiers?status=ACTIVE')->json('data');
        $extras = collect($extras)->firstWhere('code', 'EXTRAS');
        $options = array_map(fn ($o) => [
            'id' => $o['id'], 'name' => $o['name'], 'priceDelta' => $o['priceDelta'],
            'ingredientAdjustments' => $o['name'] === 'Queso extra' ? [['ingredientId' => $cheese, 'quantity' => '30']] : [],
        ], $extras['options']);
        $this->as('gerente', 'PATCH', "modifiers/{$extras['id']}", ['options' => $options], ['If-Match' => '"'.$extras['version'].'"'])->assertOk();

        return [$tortilla, $meat, $cheese];
    }

    public function test_ingredientes_alta_cambios_y_permisos(): void
    {
        $this->as('mesero', 'GET', 'ingredients')->assertStatus(403);
        $this->as('cocina', 'POST', 'ingredients', ['code' => 'SAL', 'name' => 'Sal', 'baseUnit' => 'G'])->assertStatus(403);
        $this->as('gerente', 'POST', 'ingredients', ['code' => 'SAL', 'name' => 'Sal', 'baseUnit' => 'TAZA'])->assertStatus(422);
        $salt = $this->as('gerente', 'POST', 'ingredients', ['code' => 'SAL', 'name' => 'Sal', 'baseUnit' => 'G'])->assertCreated()
            ->assertJsonPath('data.minStock', '0')->assertJsonPath('data.stock', '0')->assertJsonPath('data.foodSafetyBlock', null)->json('data');
        $this->assertNotNull($salt['stockAsOf']);
        $this->as('gerente', 'POST', 'ingredients', ['code' => 'SAL', 'name' => 'Otra', 'baseUnit' => 'G'])->assertStatus(409)->assertJsonPath('error.code', 'CATALOG_CODE_TAKEN');
        $this->as('gerente', 'PATCH', "ingredients/{$salt['id']}", ['baseUnit' => 'KG'], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('gerente', 'PATCH', "ingredients/{$salt['id']}", ['minStock' => '500.25'], ['If-Match' => '"1"'])->assertOk()
            ->assertJsonPath('data.minStock', '500.25')->assertHeader('ETag', '"2"');
        $this->as('gerente', 'PATCH', "ingredients/{$salt['id']}", ['minStock' => '1.12345'], ['If-Match' => '"2"'])->assertStatus(422);

        $this->ingredient('AGUA-GARRAFON', 'L');
        $this->as('cocina', 'GET', 'ingredients?belowMinimum=true')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'SAL');
        $this->as('cocina', 'GET', 'ingredients?belowMinimum=false')->assertJsonCount(1, 'data');
        $this->as('cocina', 'GET', 'ingredients?foodSafetyBlocked=sí')->assertStatus(422);
    }

    public function test_recetas_por_version_e_ingrediente_en_uso(): void
    {
        $tortilla = $this->ingredient('TORTILLA');
        $onion = $this->ingredient('CEBOLLA', 'G');
        $tacos = Product::query()->where('code', 'TACOS')->firstOrFail();
        $path = "products/{$tacos->uuid}/recipes";

        $this->as('gerente', 'POST', $path, ['items' => [['ingredientId' => $tortilla, 'quantity' => '3']]])->assertStatus(400);
        $this->as('gerente', 'POST', $path, ['items' => [['ingredientId' => (string) Str::uuid(), 'quantity' => '3']]], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('gerente', 'POST', $path, ['items' => [['ingredientId' => $tortilla, 'quantity' => '1'], ['ingredientId' => $tortilla, 'quantity' => '2']]], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('gerente', 'POST', $path, ['items' => [['ingredientId' => $tortilla, 'quantity' => '0']]], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('cocina', 'POST', $path, ['items' => [['ingredientId' => $tortilla, 'quantity' => '3']]], ['If-Match' => '"1"'])->assertStatus(403);

        $menu = $this->branch->refresh()->menu_version;
        $v1 = $this->publish('TACOS', [['ingredientId' => $tortilla, 'quantity' => '3']], 1);
        $this->assertSame([1, 'ACTIVE'], [$v1['recipeVersion'], $v1['status']]);
        $this->assertSame((int) $menu + 1, (int) $this->branch->refresh()->menu_version);
        $this->as('cocina', 'GET', "products/{$tacos->uuid}")->assertJsonPath('data.currentRecipeVersion', 1)->assertJsonPath('data.version', 2);
        $this->as('gerente', 'POST', $path, ['items' => [['ingredientId' => $tortilla, 'quantity' => '4']]], ['If-Match' => '"1"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $v2 = $this->publish('TACOS', [['ingredientId' => $tortilla, 'quantity' => '4'], ['ingredientId' => $onion, 'quantity' => '15.5']], 2);
        $this->assertSame(2, $v2['recipeVersion']);
        $this->as('cocina', 'GET', $path)->assertOk()->assertJsonPath('data.0.recipeVersion', 2)->assertJsonPath('data.1.status', 'SUPERSEDED');
        $this->as('cocina', 'GET', "$path/1")->assertOk()->assertJsonPath('data.items.0.quantity', '3');
        $this->as('cocina', 'GET', "$path/9")->assertStatus(404);
        $this->as('mesero', 'GET', $path)->assertStatus(403);
        $this->assertSame(2, $this->auditCount('recipe.published'));

        // La cebolla solo está en la versión vigente; el ingrediente de una versión vieja no cuenta.
        $this->as('gerente', 'POST', "ingredients/$onion/status", ['status' => 'INACTIVE', 'reason' => 'Ya no se usa'], ['If-Match' => '"1"'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INGREDIENT_IN_USE')->assertJsonPath('error.details.productIds', [$tacos->uuid]);
        $this->publish('TACOS', [['ingredientId' => $tortilla, 'quantity' => '4']], 3);
        $this->as('gerente', 'POST', "ingredients/$onion/status", ['status' => 'INACTIVE', 'reason' => 'Ya no se usa'], ['If-Match' => '"1"'])->assertOk();
        // Un ingrediente inactivo no entra a una receta nueva.
        $this->as('gerente', 'POST', $path, ['items' => [['ingredientId' => $onion, 'quantity' => '1']]], ['If-Match' => '"4"'])->assertStatus(422);
    }

    public function test_start_consume_la_receta_y_el_stock_se_proyecta_despues(): void
    {
        [$tortilla, $meat, $cheese] = $this->tacosWithRecipe();
        foreach ([$tortilla => '10', $meat => '1000', $cheese => '100'] as $id => $qty) {
            $this->stock($id, $qty);
        }
        $order = $this->as('mesero', 'POST', 'orders', $this->round($this->openSession()), $this->key())->json('data');
        $tacos = $this->unitsOf($order, 1);
        $water = $this->unitsOf($order, 0);

        $units = $this->transition('cocina', 'START', [...$tacos, ...$water])->assertOk()->json('data');
        $this->assertSame([1, null, null], array_column($units, 'recipeVersion'));
        $movements = InventoryMovement::query()->where('type', 'CONSUMPTION')->get();
        $this->assertCount(3, $movements);
        // El stock todavía no cambia: lo aplica el proceso de proyección (I-1).
        $this->assertSame('10', $this->stockOf($tortilla));
        $this->as('cocina', 'GET', "ingredients/$tortilla")->assertJsonPath('data.stockAsOf', fn ($t) => $t <= now()->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->project();
        $this->assertSame(['7', '919.5', '70'], [$this->stockOf($tortilla), $this->stockOf($meat), $this->stockOf($cheese)]);
        $this->project();
        $this->assertSame('7', $this->stockOf($tortilla));

        $list = $this->as('gerente', 'GET', "inventory-movements?ingredientId=$tortilla&type=CONSUMPTION")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['-3', 'order_unit', $tacos[0]['id'], 1, false, false], [$list['quantity'], $list['sourceType'], $list['sourceId'], $list['recipeVersion'], $list['override'], $list['selfApproved']]);
        $this->assertSame($this->staff['cocina']['user']->uuid, $list['createdBy']);
        $this->as('mesero', 'GET', 'inventory-movements')->assertStatus(403);

        // Reconstruir desde los movimientos da lo mismo.
        DB::connection('mongodb')->table('ingredients')->update(['stock_actual' => new Decimal128('0')]);
        $this->artisan('restia:inventory', ['--rebuild' => true])->assertSuccessful();
        $this->assertSame('7', $this->stockOf($tortilla));
    }

    public function test_faltante_se_salta_con_autorizacion_y_el_bloqueo_nunca(): void
    {
        [$tortilla, $meat] = $this->tacosWithRecipe();
        $this->stock($tortilla, '2');
        $this->stock($meat, '1000');
        $order = $this->as('mesero', 'POST', 'orders', $this->round($this->openSession()), $this->key())->json('data');
        $tacos = $this->unitsOf($order, 1);

        $this->transition('cocina', 'START', $tacos)->assertStatus(409)->assertJsonPath('error.code', 'INVENTORY_INSUFFICIENT')
            ->assertJsonPath('error.details.ingredients.0', ['ingredientId' => $tortilla, 'required' => '3', 'available' => '2']);
        $this->assertSame(0, InventoryMovement::query()->where('type', 'CONSUMPTION')->count());

        // El gerente autoriza en el dispositivo de cocina (D-37): el stock queda negativo.
        $reason = 'Llegó el proveedor, falta capturar';
        $approve = fn () => $this->as('cocina', 'POST', 'approvals', [
            'approverEmail' => 'gerente@ejemplo.mx', 'approverPassword' => self::PASSWORD, 'action' => 'INVENTORY_OVERRIDE',
            'resourceIds' => array_column($tacos, 'id'), 'reason' => $reason,
        ])->assertCreated()->json('data.approvalToken');
        $this->transition('cocina', 'START', $tacos, 'Otro motivo', ['X-Approval-Token' => $approve()])->assertStatus(409)->assertJsonPath('error.code', 'APPROVAL_INVALID');
        $this->transition('cocina', 'START', $tacos, $reason, ['X-Approval-Token' => $approve()])->assertOk();
        $this->project();
        $this->assertSame('-1', $this->stockOf($tortilla));
        $m = InventoryMovement::query()->where('type', 'CONSUMPTION')->where('ingredient_id', new ObjectId(Ingredient::query()->where('uuid', $tortilla)->first()->getKey()))->first();
        $this->assertSame([true, false, $reason], [$m->override, $m->self_approved, $m->reason]);
        // Tortilla y queso (que nunca se cargó) bajan del mínimo: una alerta por ingrediente.
        $this->assertSame(2, DB::connection('mongodb')->table('outbox_events')->where('type', 'ingredient.low_stock')->count());

        // Bloqueo alimentario: cocina bloquea, pero no desbloquea; ningún token lo salta.
        $this->as('cocina', 'POST', "ingredients/$meat/food-safety", ['blocked' => true, 'reason' => 'Cadena de frío rota'])->assertOk()
            ->assertJsonPath('data.foodSafetyBlock.reason', 'Cadena de frío rota');
        $this->as('cocina', 'POST', "ingredients/$meat/food-safety", ['blocked' => true, 'reason' => 'Otra vez'])->assertOk();
        $this->assertSame(1, $this->auditCount('ingredient.food_safety_blocked'));
        $this->as('cocina', 'GET', 'ingredients?foodSafetyBlocked=true')->assertJsonCount(1, 'data');
        $order = $this->as('mesero', 'POST', 'orders', $this->round($this->as('mesero', 'GET', 'table-sessions')->json('data.0.id')), $this->key())->json('data');
        $more = $this->unitsOf($order, 1);
        $token = $this->as('cocina', 'POST', 'approvals', [
            'approverEmail' => 'gerente@ejemplo.mx', 'approverPassword' => self::PASSWORD, 'action' => 'INVENTORY_OVERRIDE',
            'resourceIds' => array_column($more, 'id'), 'reason' => $reason,
        ])->json('data.approvalToken');
        $this->transition('cocina', 'START', $more, $reason, ['X-Approval-Token' => $token])->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_BLOCKED_FOOD_SAFETY')->assertJsonPath('error.details.ingredientIds', [$meat]);
        $this->as('cocina', 'POST', "ingredients/$meat/food-safety", ['blocked' => false, 'reason' => 'Revisado'])->assertStatus(403);
        $this->as('gerente', 'POST', "ingredients/$meat/food-safety", ['blocked' => false, 'reason' => 'Revisado por calidad'])->assertOk()->assertJsonPath('data.foodSafetyBlock', null);

        // Un gerente que se aprueba a sí mismo queda marcado para revisión (I-3).
        $own = $this->as('gerente', 'POST', 'approvals', [
            'approverEmail' => 'gerente@ejemplo.mx', 'approverPassword' => self::PASSWORD, 'action' => 'INVENTORY_OVERRIDE',
            'resourceIds' => array_column($more, 'id'), 'reason' => $reason,
        ])->json('data.approvalToken');
        $this->transition('gerente', 'START', $more, $reason, ['X-Approval-Token' => $own])->assertOk();
        $this->as('gerente', 'GET', 'inventory-movements?selfApproved=true')->assertJsonCount(3, 'data');
    }

    public function test_ajustes_y_desperdicio_a_mano(): void
    {
        $tortilla = $this->ingredient('TORTILLA');
        $body = ['ingredientId' => $tortilla, 'type' => 'ADJUSTMENT', 'quantity' => '25', 'reason' => 'Stock inicial'];
        $this->as('gerente', 'POST', 'inventory-movements', $body)->assertStatus(422);
        $this->as('cocina', 'POST', 'inventory-movements', $body, $this->key())->assertStatus(403);
        $this->as('mesero', 'POST', 'inventory-movements', ['type' => 'WASTE'] + $body, $this->key())->assertStatus(403);
        $this->as('gerente', 'POST', 'inventory-movements', ['quantity' => '0'] + $body, $this->key())->assertStatus(422);
        $key = $this->key();
        $first = $this->as('gerente', 'POST', 'inventory-movements', $body, $key)->assertCreated()->assertJsonPath('data.quantity', '25')->json('data');
        $this->as('gerente', 'POST', 'inventory-movements', $body, $key)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->as('gerente', 'POST', 'inventory-movements', ['quantity' => '26'] + $body, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        // Cocina registra desperdicio: positivo, resta, y puede ligarse a una unidad anulada.
        $unit = $this->unitsOf($this->as('mesero', 'POST', 'orders', $this->round($this->openSession()), $this->key())->json('data'), 1)[0]['id'];
        $waste = ['ingredientId' => $tortilla, 'type' => 'WASTE', 'quantity' => '2.5', 'reason' => 'Se quemaron', 'orderUnitId' => $unit];
        $this->as('cocina', 'POST', 'inventory-movements', ['quantity' => '-2'] + $waste, $this->key())->assertStatus(422);
        $this->as('cocina', 'POST', 'inventory-movements', ['orderUnitId' => (string) Str::uuid()] + $waste, $this->key())->assertStatus(422);
        $this->as('cocina', 'POST', 'inventory-movements', $waste, $this->key())->assertCreated()->assertJsonPath('data.quantity', '-2.5')
            ->assertJsonPath('data.sourceId', $unit)->assertJsonPath('data.reason', 'Se quemaron');
        $this->project();
        $this->assertSame('22.5', $this->stockOf($tortilla));
        $this->assertSame(2, $this->auditCount('inventory.movement_created'));
    }

    public function test_recepciones_suman_stock_y_son_inmutables(): void
    {
        $tortilla = $this->ingredient('TORTILLA');
        $meat = $this->ingredient('PASTOR', 'G');
        $body = [
            'supplierName' => 'Tortillería La Esperanza', 'reference' => 'F-1024', 'receivedAt' => now()->toIso8601String(),
            'lines' => [
                ['ingredientId' => $tortilla, 'quantity' => '100', 'unitCost' => ['amount' => '0.85', 'currency' => 'MXN']],
                ['ingredientId' => $meat, 'quantity' => '2500.5', 'unitCost' => ['amount' => '0.1825', 'currency' => 'MXN']],
            ],
        ];
        $this->as('mesero', 'POST', 'inventory-receipts', $body, $this->key())->assertStatus(403);
        $this->as('cocina', 'POST', 'inventory-receipts', $body)->assertStatus(422);
        $zero = $body;
        $zero['lines'][0]['quantity'] = '0';
        $this->as('cocina', 'POST', 'inventory-receipts', $zero, $this->key())->assertStatus(422);
        $missing = $body;
        $missing['lines'][1]['ingredientId'] = (string) Str::uuid();
        $this->as('cocina', 'POST', 'inventory-receipts', $missing, $this->key())->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['lines.1.ingredientId']]]]);

        $key = $this->key();
        $receipt = $this->as('cocina', 'POST', 'inventory-receipts', $body, $key)->assertCreated()
            ->assertJsonPath('data.supplierName', 'Tortillería La Esperanza')->assertJsonPath('data.lines.0.unitCost.amount', '0.85')
            ->assertJsonPath('data.lines.1.unitCost.amount', '0.1825')->assertJsonPath('data.lines.1.quantity', '2500.5')->json('data');
        $this->as('cocina', 'POST', 'inventory-receipts', $body, $key)->assertCreated()->assertJsonPath('data.id', $receipt['id']);
        $this->as('cocina', 'POST', 'inventory-receipts', ['supplierName' => 'Otro'] + $body, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        // Un movimiento RECEIPT por línea, ligado a la recepción; el stock sube al proyectar.
        $this->as('gerente', 'GET', 'inventory-movements?type=RECEIPT')->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.sourceType', 'inventory_receipt')->assertJsonPath('data.0.sourceId', $receipt['id']);
        $this->assertSame('0', $this->stockOf($tortilla));
        $this->project();
        $this->assertSame('100', $this->stockOf($tortilla));
        $this->assertSame('2500.5', $this->stockOf($meat));

        $this->as('cocina', 'GET', "inventory-receipts/{$receipt['id']}")->assertOk()->assertJsonPath('data.reference', 'F-1024');
        $this->as('cocina', 'GET', 'inventory-receipts?from='.urlencode(now()->subDay()->toIso8601String()))->assertOk()->assertJsonCount(1, 'data');
        $this->as('cocina', 'GET', 'inventory-receipts?to='.urlencode(now()->subDay()->toIso8601String()))->assertOk()->assertJsonCount(0, 'data');
        $this->as('mesero', 'GET', 'inventory-receipts')->assertStatus(403);
        $this->assertSame(1, $this->auditCount('inventory.received'));
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'inventory.received')->count());
    }

    public function test_conteo_fisico_ajusta_contra_lo_esperado_a_su_hora(): void
    {
        $start = now()->startOfMinute();
        $this->travelTo($start);
        $tortilla = $this->ingredient('TORTILLA');
        $salt = $this->ingredient('SAL', 'G');
        $this->stock($tortilla, '20');
        $this->stock($salt, '500');

        $this->as('mesero', 'POST', 'inventory-counts', [], $this->key())->assertStatus(403);
        $this->travelTo($start->copy()->addMinutes(10));
        $count = $this->as('cocina', 'POST', 'inventory-counts', ['notes' => 'Cierre de semana'], $this->key())->assertCreated()
            ->assertHeader('ETag', '"1"')->assertJsonPath('data.status', 'OPEN')->assertJsonPath('data.lines', [])->json('data');
        $this->as('gerente', 'POST', 'inventory-counts', [], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'INVENTORY_COUNT_ALREADY_OPEN');

        // Aplicar sin líneas no se puede.
        $this->as('gerente', 'POST', "inventory-counts/{$count['id']}/apply", [], ['If-Match' => '"1"'] + $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'INVENTORY_COUNT_INVALID_STATE');

        $at = now()->toIso8601String();
        $lines = ['lines' => [
            ['ingredientId' => $tortilla, 'counted' => '18', 'countedAt' => $at],
            ['ingredientId' => $salt, 'counted' => '500', 'countedAt' => $at],
        ]];
        $this->as('cocina', 'PUT', "inventory-counts/{$count['id']}/lines", $lines)->assertStatus(400);
        $dup = ['lines' => [$lines['lines'][0], $lines['lines'][0]]];
        $this->as('cocina', 'PUT', "inventory-counts/{$count['id']}/lines", $dup, ['If-Match' => '"1"'])->assertStatus(422);
        $future = ['lines' => [['countedAt' => now()->addHour()->toIso8601String()] + $lines['lines'][0]]];
        $this->as('cocina', 'PUT', "inventory-counts/{$count['id']}/lines", $future, ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('cocina', 'PUT', "inventory-counts/{$count['id']}/lines", $lines, ['If-Match' => '"1"'])->assertOk()->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.lines.0.expected', '20')->assertJsonPath('data.lines.0.difference', '-2')
            ->assertJsonPath('data.lines.1.difference', '0');
        $this->as('cocina', 'PUT', "inventory-counts/{$count['id']}/lines", $lines, ['If-Match' => '"1"'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');

        // Después de contar, cocina sigue trabajando: ese consumo se conserva al aplicar.
        $this->travelTo($start->copy()->addMinutes(15));
        $this->as('cocina', 'POST', 'inventory-movements', ['ingredientId' => $tortilla, 'type' => 'WASTE', 'quantity' => '3', 'reason' => 'Se rompieron'], $this->key())->assertCreated();

        $this->as('cocina', 'POST', "inventory-counts/{$count['id']}/apply", [], ['If-Match' => '"2"'] + $this->key())->assertStatus(403);
        $key = $this->key();
        $applied = $this->as('gerente', 'POST', "inventory-counts/{$count['id']}/apply", [], ['If-Match' => '"2"'] + $key)->assertOk()
            ->assertHeader('ETag', '"3"')->assertJsonPath('data.status', 'APPLIED')->assertJsonPath('data.lines.0.difference', '-2')->json('data');
        $this->assertNotNull($applied['appliedAt']);
        $this->as('gerente', 'POST', "inventory-counts/{$count['id']}/apply", [], ['If-Match' => '"2"'] + $key)->assertOk()->assertJsonPath('data.version', 3);
        $this->as('gerente', 'POST', "inventory-counts/{$count['id']}/apply", [], ['If-Match' => '"3"'] + $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'INVENTORY_COUNT_INVALID_STATE');

        // Solo la línea con diferencia genera COUNT_ADJUSTMENT: 20 − 2 del conteo − 3 de desperdicio.
        $this->as('gerente', 'GET', 'inventory-movements?type=COUNT_ADJUSTMENT')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', '-2')->assertJsonPath('data.0.sourceId', $count['id']);
        $this->project();
        $this->assertSame('15', $this->stockOf($tortilla));
        $this->assertSame('500', $this->stockOf($salt));
        $this->assertSame(1, DB::connection('mongodb')->table('outbox_events')->where('type', 'inventory.count_applied')->count());

        // Ya aplicado, se puede abrir otro; cancelarlo exige motivo y lo cierra.
        $next = $this->as('cocina', 'POST', 'inventory-counts', [], $this->key())->assertCreated()->json('data');
        $this->as('cocina', 'POST', "inventory-counts/{$next['id']}/cancel", ['reason' => 'Error'], ['If-Match' => '"1"'])->assertStatus(403);
        $this->as('gerente', 'POST', "inventory-counts/{$next['id']}/cancel", ['reason' => 'x'], ['If-Match' => '"1"'])->assertStatus(422);
        $this->as('gerente', 'POST', "inventory-counts/{$next['id']}/cancel", ['reason' => 'Se abrió por error'], ['If-Match' => '"1"'])->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
        $this->as('cocina', 'PUT', "inventory-counts/{$next['id']}/lines", $lines, ['If-Match' => '"2"'])->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_COUNT_INVALID_STATE');
        $this->as('cocina', 'GET', 'inventory-counts?status=APPLIED')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $count['id']);
        $this->as('cocina', 'GET', 'inventory-counts')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(1, $this->auditCount('inventory_count.cancelled'));
    }
}
