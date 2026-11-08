<?php

namespace App\Http\Controllers;

use App\Floor\Approvals;
use App\Floor\Menu;
use App\Floor\Orders;
use App\Floor\Tables;
use App\Floor\TableSessions;
use App\Floor\UnitTransitions;
use App\Http\Middleware\AuthorizeBranch;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\TableSession;
use App\Support\Api;
use App\Support\Floor;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Servicio en piso, primera parte: mesas, sesiones, menú, comandas, transiciones y aprobaciones. */
class FloorController extends Controller
{
    private const OPERATIONAL_STATES = ['LIBRE', 'POSIBLE_OCUPACION', 'OCUPADA_SIN_COMANDA', 'ESPERANDO_COMIDA', 'CONSUMIENDO', 'CUENTA_SOLICITADA', 'POR_LIMPIAR'];

    public function tables(Request $request): Response
    {
        [$page, $perPage] = Api::page($request);
        $filter = $this->query($request, [
            'status' => ['sometimes', 'string', Rule::in([RestaurantTable::ACTIVE, RestaurantTable::INACTIVE, RestaurantTable::MAINTENANCE])],
            'operationalState' => ['sometimes', 'string', Rule::in(self::OPERATIONAL_STATES)],
            'zone' => ['sometimes', 'string', 'max:60'],
        ]);
        $branch = AuthorizeBranch::branch($request);
        $query = RestaurantTable::query()->forBranch($branch);
        foreach (['status', 'zone'] as $field) {
            if (isset($filter[$field])) {
                $query->where($field, $filter[$field]);
            }
        }
        // El estado operativo es derivado: se filtra en memoria sobre las mesas de la sucursal (T-5).
        $rows = collect(Tables::present($branch, $query->orderBy('number')->get()));
        if (isset($filter['operationalState'])) {
            $rows = $rows->where('operationalState', $filter['operationalState'])->values();
        }

        return Floor::withEtag($request, $rows->forPage($page, $perPage)->values()->all(), Api::pagination($page, $perPage, $rows->count()));
    }

    public function table(Request $request, string $branchId, string $tableId): Response
    {
        $branch = AuthorizeBranch::branch($request);
        $table = RestaurantTable::query()->forBranch($branch)->where('uuid', Floor::uuid($tableId))->first() ?? throw new NotFoundHttpException;

        return Floor::withEtag($request, Tables::present($branch, collect([$table]))[0], etag: Floor::versionEtag((int) $table->version));
    }

    public function sessions(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $filter = $this->query($request, [
            'status' => ['sometimes', 'string', Rule::in([TableSession::ACTIVE, TableSession::PENDING_CLEANING, TableSession::CLOSED, TableSession::CANCELLED])],
            'tableId' => ['sometimes', 'string', 'uuid'],
            'businessDate' => ['sometimes', 'string', 'date_format:Y-m-d'],
        ]);
        $branch = AuthorizeBranch::branch($request);
        $query = TableSession::query()->forBranch($branch);
        if (isset($filter['status'])) {
            $query->where('status', $filter['status']);
        }
        if (isset($filter['businessDate'])) {
            $query->where('business_date', $filter['businessDate']);
        }
        if (isset($filter['tableId'])) {
            $table = RestaurantTable::query()->forBranch($branch)->where('uuid', strtolower($filter['tableId']))->first();
            $ids = $table ? TableAssignment::query()->where('table_id', new ObjectId($table->getKey()))->pluck('session_id')
                ->map(fn ($id) => new ObjectId((string) $id))->all() : [];
            $query->whereIn('_id', $ids);
        }
        $total = (clone $query)->count();
        $items = $query->orderBy('opened_at', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data($items->map(fn ($s) => TableSessions::present($branch, $s))->all(), meta: Api::pagination($page, $perPage, $total));
    }

    public function session(Request $request, string $branchId, string $sessionId): JsonResponse
    {
        $branch = AuthorizeBranch::branch($request);
        $session = TableSession::query()->forBranch($branch)->where('uuid', Floor::uuid($sessionId))->first() ?? throw new NotFoundHttpException;
        $response = Api::data(TableSessions::present($branch, $session));
        $response->headers->set('ETag', Floor::versionEtag((int) $session->version));

        return $response;
    }

    public function openSession(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'tableIds' => ['required', 'array', 'min:1', 'max:20'],
            'tableIds.*' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
            'guestCount' => ['required', 'integer', 'min:1', 'max:200'],
            'responsibleUserId' => ['sometimes', 'string', 'uuid'],
            'acknowledgeReservation' => ['sometimes', 'boolean'],
        ]);
        [$status, $data] = TableSessions::open(AuthorizeBranch::branch($request), $request->user(), $key, $in);
        $response = Api::data($data, $status);
        $response->headers->set('ETag', Floor::versionEtag($data['version']));

        return $response;
    }

    public function menu(Request $request): Response
    {
        $branch = AuthorizeBranch::branch($request);

        return Floor::withEtag($request, Menu::build($branch), etag: Floor::versionEtag(Menu::version($branch)));
    }

    public function orders(Request $request): Response
    {
        [$page, $perPage] = Api::page($request);
        $filter = $this->query($request, [
            'sessionId' => ['sometimes', 'string', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in([Order::OPEN, Order::COMPLETED, Order::CANCELLED])],
            'businessDate' => ['sometimes', 'string', 'date_format:Y-m-d'],
        ]);
        $branch = AuthorizeBranch::branch($request);
        $query = Order::query()->forBranch($branch);
        if (isset($filter['sessionId'])) {
            $session = TableSession::query()->forBranch($branch)->where('uuid', strtolower($filter['sessionId']))->first();
            // Una sesión de otra sucursal o inexistente da una lista vacía, sin revelar nada.
            $query->where('session_id', $session ? new ObjectId($session->getKey()) : new ObjectId);
        }
        foreach (['status' => 'status', 'businessDate' => 'business_date'] as $param => $field) {
            if (isset($filter[$param])) {
                $query->where($field, $filter[$param]);
            }
        }
        $total = (clone $query)->count();
        // De la primera ronda a la última.
        $orders = $query->orderBy('created_at')->orderBy('_id')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Floor::withEtag($request, Orders::presentMany($branch, $orders), Api::pagination($page, $perPage, $total));
    }

    public function order(Request $request, string $branchId, string $orderId): JsonResponse
    {
        $branch = AuthorizeBranch::branch($request);
        $order = Order::query()->forBranch($branch)->where('uuid', Floor::uuid($orderId))->first() ?? throw new NotFoundHttpException;

        return Api::data(Orders::presentMany($branch, collect([$order]))[0]);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'sessionId' => ['required', 'string', 'uuid'],
            'clientOrderUuid' => ['required', 'string', 'uuid'],
            'capturedAt' => ['sometimes', 'string', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.productId' => ['required', 'string', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.modifierOptions' => ['sometimes', 'array', 'max:30'],
            'items.*.modifierOptions.*' => ['required', 'array'],
            'items.*.modifierOptions.*.modifierId' => ['required', 'string', 'uuid'],
            'items.*.modifierOptions.*.optionId' => ['required', 'string', 'uuid'],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'targetCheckId' => ['sometimes', 'string', 'uuid'],
        ]);
        // Sobre la entrada cruda: validated() ya descartó los campos anidados que no tienen regla.
        $this->strict($request->json('items'), ['productId', 'quantity', 'modifierOptions', 'notes'], 'items', function ($item, $field) {
            $this->strict($item['modifierOptions'] ?? [], ['modifierId', 'optionId'], $field.'.modifierOptions');
        });
        [$status, $data] = Orders::create(AuthorizeBranch::branch($request), $request->user(), $key, $in);

        return Api::data($data, $status);
    }

    public function transitions(Request $request): JsonResponse
    {
        $key = Idempotency::key($request);
        $in = Api::body($request, [
            'action' => ['required', 'string', Rule::in(array_keys(UnitTransitions::ACTIONS))],
            'units' => ['required', 'array', 'min:1', 'max:50'],
            'units.*' => ['required', 'array'],
            'units.*.id' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
            'units.*.version' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required_if:action,CANCEL,VOID', 'string', 'min:3', 'max:500'],
        ]);
        $this->strict($request->json('units'), ['id', 'version'], 'units');
        $token = $request->headers->get('X-Approval-Token');
        [$status, $data] = UnitTransitions::apply(AuthorizeBranch::branch($request), $request->user(), AuthorizeBranch::membership($request), $key, $in, $token === '' ? null : $token);

        return Api::data($data, $status);
    }

    public function approve(Request $request): JsonResponse
    {
        $in = Api::body($request, [
            'approverEmail' => ['required', 'string', 'email', 'max:254'],
            'approverPassword' => ['required', 'string', 'min:1', 'max:1024'],
            'action' => ['required', 'string', Rule::in(array_keys(Approvals::PERMISSIONS))],
            'resourceIds' => ['required', 'array', 'min:1', 'max:50'],
            'resourceIds.*' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return Api::data(Approvals::grant(AuthorizeBranch::branch($request), $request->user(), $in, (string) $request->ip()), 201, noStore: true);
    }

    /** Parámetros de consulta: solo texto, para que `status[$ne]=x` no llegue a una consulta (prueba 9). */
    private function query(Request $request, array $rules): array
    {
        $v = Validator::make($request->query(), $rules + ['page' => ['sometimes'], 'perPage' => ['sometimes']]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }

        return array_intersect_key($v->validated(), $rules);
    }

    /** `additionalProperties: false` en objetos anidados. */
    private function strict(array $rows, array $allowed, string $field, ?callable $each = null): void
    {
        $errors = [];
        foreach ($rows as $i => $row) {
            foreach (array_diff(array_keys($row), $allowed) as $extra) {
                $errors["$field.$i.$extra"] = 'Campo no permitido.';
            }
            if ($each) {
                $each($row, "$field.$i");
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
