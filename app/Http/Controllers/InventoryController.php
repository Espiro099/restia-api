<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainConflict;
use App\Http\Controllers\Concerns\BranchResources;
use App\Http\Middleware\AuthorizeBranch;
use App\Identity\MobileAuth;
use App\Inventory\Counts;
use App\Inventory\Ingredients;
use App\Inventory\Movements;
use App\Inventory\Quantity;
use App\Inventory\Receipts;
use App\Inventory\Recipes;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\InventoryCount;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Catálogo de Inventory: ingredientes, recetas de producto y consulta de movimientos. */
class InventoryController extends Controller
{
    use BranchResources;

    private const QUANTITY = 'regex:'.Quantity::PATTERN;

    // ---------------------------------------------------------------- Ingredientes

    public function ingredients(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $q = $this->query($request, [
            'status' => ['sometimes', 'string', Rule::in([Ingredient::ACTIVE, Ingredient::INACTIVE])],
            'belowMinimum' => ['sometimes', 'string', Rule::in(['true', 'false'])],
            'foodSafetyBlocked' => ['sometimes', 'string', Rule::in(['true', 'false'])],
        ]);
        $branch = AuthorizeBranch::branch($request);
        $query = Ingredient::query()->forBranch($branch);
        if (isset($q['status'])) {
            $query->where('status', $q['status']);
        }
        if (isset($q['foodSafetyBlocked'])) {
            $q['foodSafetyBlocked'] === 'true' ? $query->whereNotNull('food_safety_block') : $query->whereNull('food_safety_block');
        }
        if (isset($q['belowMinimum'])) {
            $query->whereRaw(['$expr' => [$q['belowMinimum'] === 'true' ? '$lt' : '$gte' => ['$stock_actual', '$min_stock']]]);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('code')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(Ingredients::present($branch, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    public function ingredient(Request $request, string $branchId, string $ingredientId): JsonResponse
    {
        [$branch, $i] = $this->find($request, Ingredient::class, $ingredientId);

        return $this->ingredientResponse($branch, $i);
    }

    public function createIngredient(Request $request): JsonResponse
    {
        $in = Api::body($request, [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{2,32}$/'],
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'baseUnit' => ['required', 'string', Rule::in(Ingredient::UNITS)],
            'minStock' => ['sometimes', 'string', self::QUANTITY],
        ]);
        $branch = AuthorizeBranch::branch($request);

        return $this->ingredientResponse($branch, Ingredients::create($branch, $request->user(), $in), 201);
    }

    public function updateIngredient(Request $request, string $branchId, string $ingredientId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->patchBody($request, [
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'minStock' => ['sometimes', 'string', self::QUANTITY],
        ]);
        [$branch, $i] = $this->find($request, Ingredient::class, $ingredientId);

        return $this->ingredientResponse($branch, Ingredients::update($branch, $request->user(), $i, $version, $in));
    }

    public function ingredientStatus(Request $request, string $branchId, string $ingredientId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = $this->statusBody($request, [Ingredient::ACTIVE, Ingredient::INACTIVE]);
        [$branch, $i] = $this->find($request, Ingredient::class, $ingredientId);

        return $this->ingredientResponse($branch, Ingredients::setStatus($branch, $request->user(), $i, $version, $in['status'], $in['reason']));
    }

    public function foodSafety(Request $request, string $branchId, string $ingredientId): JsonResponse
    {
        $in = Api::body($request, ['blocked' => ['required', 'boolean:strict'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        [$branch, $i] = $this->find($request, Ingredient::class, $ingredientId);
        $i = Ingredients::foodSafety($branch, $request->user(), AuthorizeBranch::membership($request), $i, $in['blocked'], $in['reason']);

        return $this->ingredientResponse($branch, $i);
    }

    // ---------------------------------------------------------------- Recetas

    public function recipes(Request $request, string $branchId, string $productId): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        [, $product] = $this->find($request, Product::class, $productId);
        $query = Recipe::query()->where('product_id', new ObjectId($product->getKey()));
        $total = (clone $query)->count();
        $rows = $query->orderBy('recipe_version', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(Recipes::present($product, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    public function recipe(Request $request, string $branchId, string $productId, string $recipeVersion): JsonResponse
    {
        [, $product] = $this->find($request, Product::class, $productId);
        $recipe = ctype_digit($recipeVersion) ? Recipe::query()->where('product_id', new ObjectId($product->getKey()))
            ->where('recipe_version', (int) $recipeVersion)->first() : null;
        if (! $recipe) {
            throw new NotFoundHttpException;
        }

        return Api::data(Recipes::present($product, collect([$recipe]))[0]);
    }

    /** If-Match es la versión del producto, que sube al publicar; el ETag de la respuesta es la nueva. */
    public function publishRecipe(Request $request, string $branchId, string $productId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, [
            'items' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'items.*' => ['array:ingredientId,quantity'],
            'items.*.ingredientId' => ['required', 'string', 'uuid'],
            'items.*.quantity' => ['required', 'string', self::QUANTITY],
        ]);
        [$branch, $product] = $this->find($request, Product::class, $productId);
        $recipe = Recipes::publish($branch, $request->user(), $product, $version, $in['items']);

        return $this->one(Recipes::present($product->refresh(), collect([$recipe]))[0], $product, 201);
    }

    // ---------------------------------------------------------------- Movimientos

    /** Del más reciente al más antiguo. */
    public function movements(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $q = $this->query($request, [
            'ingredientId' => ['sometimes', 'string', 'uuid'],
            'type' => ['sometimes', 'string', Rule::in(InventoryMovement::TYPES)],
            'from' => ['sometimes', 'string', 'date'],
            'to' => ['sometimes', 'string', 'date', 'after_or_equal:from'],
            'selfApproved' => ['sometimes', 'string', Rule::in(['true', 'false'])],
        ]);
        $branch = AuthorizeBranch::branch($request);
        $query = InventoryMovement::query()->forBranch($branch);
        if (isset($q['ingredientId'])) {
            $ingredient = Ingredient::query()->forBranch($branch)->where('uuid', strtolower($q['ingredientId']))->first();
            $query->whereIn('ingredient_id', $ingredient ? [new ObjectId($ingredient->getKey())] : []);
        }
        if (isset($q['type'])) {
            $query->where('type', $q['type']);
        }
        if (isset($q['selfApproved'])) {
            $query->where('self_approved', $q['selfApproved'] === 'true');
        }
        if (isset($q['from'])) {
            $query->where('created_at', '>=', Carbon::parse($q['from']));
        }
        if (isset($q['to'])) {
            $query->where('created_at', '<=', Carbon::parse($q['to']));
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data($this->presentMovements($branch, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    /** El permiso depende del tipo: inventory.adjust para ADJUSTMENT, inventory.record_waste para WASTE. */
    public function createMovement(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'ingredientId' => ['required', 'string', 'uuid'],
            'type' => ['required', 'string', Rule::in(['ADJUSTMENT', 'WASTE'])],
            'quantity' => ['required', 'string', 'regex:/^-?\d{1,9}(\.\d{1,4})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'orderUnitId' => ['sometimes', 'string', 'uuid'],
        ]);
        $permission = $in['type'] === 'ADJUSTMENT' ? 'inventory.adjust' : 'inventory.record_waste';
        if (! AuthorizeBranch::membership($request)->can($permission)) {
            throw new DomainConflict('FORBIDDEN');
        }
        $branch = AuthorizeBranch::branch($request);
        [$status, $data] = Movements::record($branch, $request->user(), $key, $in, fn (InventoryMovement $m) => $this->presentMovements($branch, collect([$m]))[0]);

        return Api::data($data, $status);
    }

    // ---------------------------------------------------------------- Recepciones

    /** De la más reciente a la más antigua, por fecha de recepción. */
    public function receipts(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $q = $this->query($request, [
            'from' => ['sometimes', 'string', 'date'],
            'to' => ['sometimes', 'string', 'date', 'after_or_equal:from'],
        ]);
        $branch = AuthorizeBranch::branch($request);
        $query = InventoryReceipt::query()->forBranch($branch);
        if (isset($q['from'])) {
            $query->where('received_at', '>=', Carbon::parse($q['from']));
        }
        if (isset($q['to'])) {
            $query->where('received_at', '<=', Carbon::parse($q['to']));
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('received_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(Receipts::present($branch, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    public function receipt(Request $request, string $branchId, string $receiptId): JsonResponse
    {
        [$branch, $r] = $this->find($request, InventoryReceipt::class, $receiptId);

        return Api::data(Receipts::present($branch, collect([$r]))[0]);
    }

    public function createReceipt(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'supplierName' => ['required', 'string', 'min:1', 'max:120'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'receivedAt' => ['required', 'string', 'date'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'lines.*' => ['array:ingredientId,quantity,unitCost'],
            'lines.*.ingredientId' => ['required', 'string', 'uuid'],
            'lines.*.quantity' => ['required', 'string', self::QUANTITY, 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unitCost' => ['required', 'array:amount,currency'],
            'lines.*.unitCost.amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'lines.*.unitCost.currency' => ['required', 'string', Rule::in([Floor::CURRENCY])],
        ]);
        $branch = AuthorizeBranch::branch($request);
        [$status, $data] = Receipts::create($branch, $request->user(), $key, $in, fn (InventoryReceipt $r) => Receipts::present($branch, collect([$r]))[0]);

        return Api::data($data, $status);
    }

    // ---------------------------------------------------------------- Conteos

    public function counts(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $q = $this->query($request, ['status' => ['sometimes', 'string', Rule::in(InventoryCount::STATUSES)]]);
        $branch = AuthorizeBranch::branch($request);
        $query = InventoryCount::query()->forBranch($branch);
        if (isset($q['status'])) {
            $query->where('status', $q['status']);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('created_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data(Counts::present($branch, $rows), meta: Api::pagination($page, $perPage, $total));
    }

    public function count(Request $request, string $branchId, string $countId): JsonResponse
    {
        [$branch, $c] = $this->find($request, InventoryCount::class, $countId);

        return $this->countResponse($branch, $c);
    }

    public function createCount(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, ['notes' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $branch = AuthorizeBranch::branch($request);
        [$status, $data] = Counts::create($branch, $request->user(), $key, $in, fn (InventoryCount $c) => Counts::present($branch, collect([$c]))[0]);

        return $this->etagged($data, $status);
    }

    /** Reemplaza todas las líneas; lo esperado lo calcula el servidor. */
    public function countLines(Request $request, string $branchId, string $countId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, [
            'lines' => ['present', 'array', 'list', 'max:1000'],
            'lines.*' => ['array:ingredientId,counted,countedAt'],
            'lines.*.ingredientId' => ['required', 'string', 'uuid'],
            'lines.*.counted' => ['required', 'string', self::QUANTITY],
            'lines.*.countedAt' => ['required', 'string', 'date'],
        ]);
        [$branch, $c] = $this->find($request, InventoryCount::class, $countId);

        return $this->countResponse($branch, Counts::replaceLines($branch, $request->user(), $c, $version, $in['lines']));
    }

    public function applyCount(Request $request, string $branchId, string $countId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $key = Idempotency::key($request);
        [$branch, $c] = $this->find($request, InventoryCount::class, $countId);
        [$status, $data] = Counts::apply($branch, $request->user(), $c, $version, $key, fn (InventoryCount $c) => Counts::present($branch, collect([$c]))[0]);

        return $this->etagged($data, $status);
    }

    public function cancelCount(Request $request, string $branchId, string $countId): JsonResponse
    {
        $version = Floor::ifMatch($request);
        $in = Api::body($request, ['reason' => ['required', 'string', 'min:3', 'max:500']]);
        [$branch, $c] = $this->find($request, InventoryCount::class, $countId);

        return $this->countResponse($branch, Counts::cancel($branch, $request->user(), $c, $version, $in['reason']));
    }

    // ---------------------------------------------------------------- Ayudas

    private function countResponse(Branch $branch, InventoryCount $c): JsonResponse
    {
        return $this->one(Counts::present($branch, collect([$c]))[0], $c);
    }

    /** Respuesta de una operación idempotente: el ETag sale de la versión que trae la respuesta guardada. */
    private function etagged(array $data, int $status): JsonResponse
    {
        $response = Api::data($data, $status);
        $response->headers->set('ETag', Floor::versionEtag((int) $data['version']));

        return $response;
    }

    private function ingredientResponse(Branch $branch, Ingredient $i, int $status = 200): JsonResponse
    {
        return $this->one(Ingredients::present($branch, collect([$i]))[0], $i, $status);
    }

    /** @param Collection<int, InventoryMovement> $rows */
    private function presentMovements(Branch $branch, Collection $rows): array
    {
        $uuids = fn (string $model, string $field) => $model::query()
            ->whereIn('_id', $rows->pluck($field)->filter()->map(fn ($id) => new ObjectId((string) $id))->unique()->values()->all())
            ->get()->mapWithKeys(fn ($d) => [(string) $d->getKey() => $d->uuid]);
        $ingredients = $uuids(Ingredient::class, 'ingredient_id');
        $users = $uuids(User::class, 'created_by');

        return $rows->map(fn (InventoryMovement $m) => [
            'id' => $m->uuid,
            'branchId' => $branch->uuid,
            'ingredientId' => $ingredients->get((string) $m->ingredient_id),
            'type' => $m->type,
            'quantity' => Quantity::out($m->quantity),
            'sourceType' => $m->source_type,
            'sourceId' => $m->source_uuid,
            'recipeVersion' => $m->recipe_version === null ? null : (int) $m->recipe_version,
            'reason' => $m->reason,
            'override' => (bool) $m->override,
            'selfApproved' => (bool) $m->self_approved,
            'createdBy' => $m->created_by ? $users->get((string) $m->created_by) : null,
            'createdAt' => MobileAuth::time($m->created_at),
        ])->values()->all();
    }

    /** Filtros de una lista: los parámetros de consulta llegan como texto. */
    private function query(Request $request, array $rules): array
    {
        $v = Validator::make($request->query(), $rules + ['page' => ['sometimes'], 'perPage' => ['sometimes']]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }

        return $v->validated();
    }
}
