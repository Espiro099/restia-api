<?php

namespace App\Http\Controllers;

use App\Catalog\Modifiers;
use App\Catalog\Products;
use App\Catalog\Stations;
use App\Catalog\TableAdmin;
use App\Floor\Tables;
use App\Http\Controllers\Concerns\BranchResources;
use App\Http\Middleware\AuthorizeBranch;
use App\Models\Branch;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Station;
use App\Support\Api;
use App\Support\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de la sucursal: estaciones, modificadores y productos (Menu), y alta y cambios de mesas.
 * Los usa el panel Web; la app Android solo lee y marca productos agotados.
 */
class CatalogController extends Controller
{
    use BranchResources;

    private const CODE = ['required', 'string', 'regex:/^[A-Z0-9_-]{2,32}$/'];

    private const NAME = ['string', 'min:1', 'max:120'];

    // ---------------------------------------------------------------- Estaciones

    public function stations(Request $request): JsonResponse
    {
        return $this->list($request, Station::class, ['code'], fn (Branch $b, $rows) => $rows->map(fn ($s) => Stations::present($b, $s))->all());
    }

    public function station(Request $request, string $branchId, string $stationId): JsonResponse
    {
        [$branch, $s] = $this->find($request, Station::class, $stationId);

        return $this->one(Stations::present($branch, $s), $s);
    }

    public function createStation(Request $request): JsonResponse
    {
        $in = Api::body($request, ['code' => self::CODE, 'name' => ['required', ...self::NAME]]);
        $branch = AuthorizeBranch::branch($request);
        $s = Stations::create($branch, $request->user(), $in);

        return $this->one(Stations::present($branch, $s), $s, 201);
    }

    public function updateStation(Request $request, string $branchId, string $stationId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['name' => ['required', ...self::NAME]]);
        [$branch, $s] = $this->find($request, Station::class, $stationId);
        $s = Stations::rename($branch, $request->user(), $s, $version, $in['name']);

        return $this->one(Stations::present($branch, $s), $s);
    }

    public function stationStatus(Request $request, string $branchId, string $stationId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->statusBody($request, [Station::ACTIVE, Station::INACTIVE]);
        [$branch, $s] = $this->find($request, Station::class, $stationId);
        $s = Stations::setStatus($branch, $request->user(), $s, $version, $in['status'], $in['reason']);

        return $this->one(Stations::present($branch, $s), $s);
    }

    // ---------------------------------------------------------------- Modificadores

    public function modifiers(Request $request): JsonResponse
    {
        return $this->list($request, Modifier::class, ['code'], fn (Branch $b, $rows) => $rows->map(fn ($m) => Modifiers::present($b, $m))->all());
    }

    public function modifier(Request $request, string $branchId, string $modifierId): JsonResponse
    {
        [$branch, $m] = $this->find($request, Modifier::class, $modifierId);

        return $this->one(Modifiers::present($branch, $m), $m);
    }

    public function createModifier(Request $request): JsonResponse
    {
        $in = Api::body($request, [
            'code' => self::CODE,
            'name' => ['required', ...self::NAME],
            'minSelections' => ['required', 'integer', 'min:0', 'max:100'],
            'maxSelections' => ['required', 'integer', 'min:1', 'max:100'],
        ] + $this->optionRules(true));
        $branch = AuthorizeBranch::branch($request);
        $m = Modifiers::create($branch, $request->user(), $in);

        return $this->one(Modifiers::present($branch, $m), $m, 201);
    }

    public function updateModifier(Request $request, string $branchId, string $modifierId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->patchBody($request, [
            'name' => ['sometimes', ...self::NAME],
            'minSelections' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'maxSelections' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ] + $this->optionRules(false));
        [$branch, $m] = $this->find($request, Modifier::class, $modifierId);
        $m = Modifiers::update($branch, $request->user(), $m, $version, $in);

        return $this->one(Modifiers::present($branch, $m), $m);
    }

    public function modifierStatus(Request $request, string $branchId, string $modifierId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->statusBody($request, [Modifier::ACTIVE, Modifier::INACTIVE]);
        [$branch, $m] = $this->find($request, Modifier::class, $modifierId);
        $m = Modifiers::setStatus($branch, $request->user(), $m, $version, $in['status'], $in['reason']);

        return $this->one(Modifiers::present($branch, $m), $m);
    }

    // ---------------------------------------------------------------- Productos

    public function products(Request $request): JsonResponse
    {
        return $this->list($request, Product::class, ['category', 'display_order', 'name'], fn (Branch $b, $rows) => Products::present($b, $rows));
    }

    public function product(Request $request, string $branchId, string $productId): JsonResponse
    {
        [$branch, $p] = $this->find($request, Product::class, $productId);

        return $this->productResponse($branch, $p);
    }

    public function createProduct(Request $request): JsonResponse
    {
        $in = Api::body($request, ['code' => self::CODE] + $this->productRules(true));
        $branch = AuthorizeBranch::branch($request);

        return $this->productResponse($branch, Products::create($branch, $request->user(), $in), 201);
    }

    public function updateProduct(Request $request, string $branchId, string $productId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->patchBody($request, $this->productRules(false));
        [$branch, $p] = $this->find($request, Product::class, $productId);

        return $this->productResponse($branch, Products::update($branch, $request->user(), $p, $version, $in));
    }

    public function productStatus(Request $request, string $branchId, string $productId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->statusBody($request, [Product::ACTIVE, Product::INACTIVE]);
        [$branch, $p] = $this->find($request, Product::class, $productId);

        return $this->productResponse($branch, Products::setStatus($branch, $request->user(), $p, $version, $in['status'], $in['reason']));
    }

    public function productAvailability(Request $request, string $branchId, string $productId): JsonResponse
    {
        $in = Api::body($request, ['availability' => ['required', 'string', Rule::in(['AVAILABLE', 'SOLD_OUT'])]]);
        [$branch, $p] = $this->find($request, Product::class, $productId);

        return $this->productResponse($branch, Products::setAvailability($branch, $request->user(), $p, $in['availability']));
    }

    public function productRoutes(Request $request, string $branchId, string $productId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, [
            'routes' => ['present', 'array', 'list', 'max:20'],
            'routes.*' => ['array:stationId,priority'],
            'routes.*.stationId' => ['required', 'string', 'uuid'],
            'routes.*.priority' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        [$branch, $p] = $this->find($request, Product::class, $productId);

        return $this->productResponse($branch, Products::setRoutes($branch, $request->user(), $p, $version, $in['routes'] ?? []));
    }

    // ---------------------------------------------------------------- Mesas

    public function createTable(Request $request): JsonResponse
    {
        $in = Api::body($request, ['number' => ['required', 'integer', 'min:1', 'max:9999'], 'capacity' => ['required', 'integer', 'min:1', 'max:200']] + $this->tableRules());
        $branch = AuthorizeBranch::branch($request);

        return $this->tableResponse($branch, TableAdmin::create($branch, $request->user(), $in), 201);
    }

    public function updateTable(Request $request, string $branchId, string $tableId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->patchBody($request, ['number' => ['sometimes', 'integer', 'min:1', 'max:9999'], 'capacity' => ['sometimes', 'integer', 'min:1', 'max:200']] + $this->tableRules());
        [$branch, $t] = $this->find($request, RestaurantTable::class, $tableId);

        return $this->tableResponse($branch, TableAdmin::update($branch, $request->user(), $t, $version, $in));
    }

    public function tableStatus(Request $request, string $branchId, string $tableId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->statusBody($request, [RestaurantTable::ACTIVE, RestaurantTable::INACTIVE, RestaurantTable::MAINTENANCE]);
        [$branch, $t] = $this->find($request, RestaurantTable::class, $tableId);

        return $this->tableResponse($branch, TableAdmin::setStatus($branch, $request->user(), $t, $version, $in['status'], $in['reason']));
    }

    // ---------------------------------------------------------------- Ayudas

    private function productResponse(Branch $branch, Product $p, int $status = 200): JsonResponse
    {
        return $this->one(Products::present($branch, collect([$p]))[0], $p, $status);
    }

    private function tableResponse(Branch $branch, RestaurantTable $t, int $status = 200): JsonResponse
    {
        return $this->one(Tables::present($branch, collect([$t]))[0], $t, $status);
    }

    /** @return array<string, mixed> */
    private function optionRules(bool $required): array
    {
        return [
            'options' => [$required ? 'required' : 'sometimes', 'array', 'list', 'min:1', 'max:100'],
            'options.*' => ['array:id,name,priceDelta,active,ingredientAdjustments'],
            'options.*.id' => ['sometimes', 'string', 'uuid'],
            'options.*.name' => ['required', ...self::NAME],
            'options.*.active' => ['sometimes', 'boolean:strict'],
            'options.*.ingredientAdjustments' => ['sometimes', 'array', 'list', 'max:50'],
            'options.*.ingredientAdjustments.*' => ['array:ingredientId,quantity'],
            'options.*.ingredientAdjustments.*.ingredientId' => ['required', 'string', 'uuid'],
            'options.*.ingredientAdjustments.*.quantity' => ['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
        ] + $this->money('options.*.priceDelta', true, true);
    }

    /** @return array<string, mixed> */
    private function productRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'name' => [$req, ...self::NAME],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category' => [$req, 'string', 'min:1', 'max:60'],
            'displayOrder' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'modifierIds' => ['sometimes', 'array', 'list', 'max:50'],
            'modifierIds.*' => ['string', 'uuid', 'distinct:ignore_case'],
            'fiscal' => [$req, 'array:satProductCode,satUnitCode,taxObject,taxRate'],
            'fiscal.satProductCode' => ['required_with:fiscal', 'string', 'regex:/^[0-9]{8}$/'],
            'fiscal.satUnitCode' => ['required_with:fiscal', 'string', 'regex:/^[A-Z0-9]{2,3}$/'],
            'fiscal.taxObject' => ['required_with:fiscal', 'string', 'regex:/^[0-9]{2}$/'],
            'fiscal.taxRate' => ['required_with:fiscal', 'string', 'regex:/^0(\.\d{1,6})?$/'],
            'imageUrl' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:https'],
        ] + $this->money('price', $create, false);
    }

    /** @return array<string, mixed> */
    private function tableRules(): array
    {
        return [
            'zone' => ['sometimes', 'nullable', 'string', 'min:1', 'max:60'],
            'combinationGroup' => ['sometimes', 'nullable', 'string', 'min:1', 'max:60'],
        ];
    }
}
