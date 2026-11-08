<?php

namespace App\Floor;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemUnit;
use App\Models\Product;
use App\Models\ProductPreparationRoute;
use App\Models\Station;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\Floor;
use App\Support\Idempotency;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Comandas (O-1, O-2, O-7). Cada ronda es una comanda nueva con unidades PENDING.
 *
 * Pendiente para Checks (fase 5): asignar las unidades a la cuenta OPEN de la sesión (O-7, D-17).
 * Mientras no haya cuentas, `targetCheckId` responde CHECK_INVALID_STATE porque ninguna está OPEN.
 */
final class Orders
{
    /**
     * @param  array<string, mixed>  $in  cuerpo validado de OrderCreateRequest
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function create(Branch $branch, User $actor, string $key, array $in): array
    {
        $branchId = new ObjectId($branch->getKey());
        $actorId = new ObjectId($actor->getKey());
        $hash = Idempotency::hash('createOrder', $in);
        // Huella sin la clave ni la hora de captura: la misma ronda reenviada con otra clave no es un conflicto.
        $content = Idempotency::hash('order', ['sessionId' => strtolower($in['sessionId']), 'items' => $in['items']]);

        try {
            return Idempotency::run($branchId, $key, $actorId, 'createOrder', $hash, fn () => self::write($branch, $branchId, $actorId, $in, $content));
        } catch (\Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('CLIENT_ORDER_CONFLICT') : $e;
        }
    }

    private static function write(Branch $branch, ObjectId $branchId, ObjectId $actorId, array $in, string $content): array
    {
        $session = TableSession::query()->where('branch_id', $branchId)->where('uuid', strtolower($in['sessionId']))->first()
            ?? throw new NotFoundHttpException;
        // Primero el epoch de la sesión: choca con su cierre (sección 5 de Orders).
        TableSession::query()->where('_id', new ObjectId($session->getKey()))->increment('activity_epoch');
        $session->refresh();
        if ($session->status !== TableSession::ACTIVE) {
            throw new DomainConflict('SESSION_NOT_ACTIVE');
        }

        // Un borrador offline reenviado (A-7): mismo clientOrderUuid y mismos datos devuelve la comanda original.
        $existing = Order::query()->where('branch_id', $branchId)->where('client_order_uuid', strtolower($in['clientOrderUuid']))->first();
        if ($existing) {
            if ($existing->request_hash !== $content) {
                throw new DomainConflict('CLIENT_ORDER_CONFLICT');
            }

            return [201, self::presentMany($branch, collect([$existing]))[0], $existing->uuid];
        }
        if (isset($in['targetCheckId'])) {
            throw new DomainConflict('CHECK_INVALID_STATE');
        }

        $lines = self::lines($branchId, $in['items']);
        $now = now();
        $total = '0';
        foreach ($lines as $line) {
            $total = bcadd($total, bcmul($line['each'], (string) $line['quantity'], 2), 2);
        }

        $order = Order::query()->create([
            'branch_id' => $branchId,
            'session_id' => new ObjectId($session->getKey()),
            'client_order_uuid' => strtolower($in['clientOrderUuid']),
            'status' => Order::OPEN,
            'total' => $total,
            'business_date' => Floor::businessDate($branch, $now),
            'captured_at' => isset($in['capturedAt']) ? Carbon::parse($in['capturedAt']) : null,
            'created_by' => $actorId,
            'request_hash' => $content,
        ]);
        $orderId = new ObjectId($order->getKey());
        foreach ($lines as $line) {
            $item = OrderItem::query()->create([
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'product_id' => new ObjectId($line['product']->getKey()),
                'name_snapshot' => $line['product']->name,
                'quantity' => $line['quantity'],
                'unit_price' => bcadd((string) $line['product']->price, '0', 2),
                'modifiers_snapshot' => $line['modifiers'],
                'fiscal_snapshot' => $line['product']->fiscal,
                'notes' => $line['notes'],
            ]);
            for ($n = 1; $n <= $line['quantity']; $n++) {
                OrderItemUnit::query()->create([
                    'branch_id' => $branchId,
                    'order_id' => $orderId,
                    'order_item_id' => new ObjectId($item->getKey()),
                    'unit_number' => $n,
                    'status' => OrderItemUnit::PENDING,
                    'station_id' => $line['station'],
                    'recipe_version' => null,
                    'reason' => null,
                ]);
            }
        }

        Audit::record('order.created', 'order', $orderId, $branchId, $actorId, changes: [
            'session' => $session->uuid,
            'items' => count($lines),
            'units' => array_sum(array_column($lines, 'quantity')),
        ]);
        // La impresión de la comanda lee este evento (Printing, 24.12).
        Outbox::publish('order.created', 'order', $orderId, $branchId, ['orderId' => $order->uuid, 'sessionId' => $session->uuid]);

        return [201, self::presentMany($branch, collect([$order]))[0], $order->uuid];
    }

    /**
     * Valida cada partida contra el catálogo vigente y copia lo que la comanda debe conservar.
     *
     * @return list<array{product: Product, quantity: int, notes: ?string, modifiers: list<array<string, mixed>>, each: string, station: ObjectId}>
     */
    private static function lines(ObjectId $branchId, array $items): array
    {
        $uuids = array_values(array_unique(array_map(fn ($i) => strtolower($i['productId']), $items)));
        $products = Product::query()->where('branch_id', $branchId)->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        $stations = self::stations($branchId, $products);
        $unavailable = array_values(array_filter($uuids, fn ($u) => ! $products->has($u)
            || $products[$u]->status !== Product::ACTIVE
            || $products[$u]->availability !== 'AVAILABLE'
            || ! isset($stations[(string) $products[$u]->getKey()])));
        if ($unavailable) {
            throw new DomainConflict('PRODUCT_UNAVAILABLE', ['productIds' => $unavailable]);
        }

        $modifierIds = $products->flatMap(fn ($p) => array_map('strval', $p->modifier_ids ?? []))->unique()->values();
        $modifiers = Modifier::query()->where('branch_id', $branchId)->where('status', Modifier::ACTIVE)
            ->whereIn('_id', $modifierIds->map(fn ($id) => new ObjectId($id))->all())->get()->keyBy('uuid');

        $lines = [];
        foreach ($items as $i => $item) {
            $product = $products[strtolower($item['productId'])];
            [$snapshot, $delta] = self::modifiers($product, $modifiers, $item['modifierOptions'] ?? [], "items.$i.modifierOptions");
            $lines[] = [
                'product' => $product,
                'quantity' => (int) $item['quantity'],
                'notes' => isset($item['notes']) && trim($item['notes']) !== '' ? $item['notes'] : null,
                'modifiers' => $snapshot,
                'each' => bcadd((string) $product->price, $delta, 2),
                'station' => $stations[(string) $product->getKey()],
            ];
        }

        return $lines;
    }

    /**
     * Primera estación activa por prioridad de cada producto (M-2, opción A).
     *
     * @return array<string, ObjectId>
     */
    private static function stations(ObjectId $branchId, Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }
        $routes = ProductPreparationRoute::query()->where('branch_id', $branchId)
            ->whereIn('product_id', $products->map(fn ($p) => new ObjectId($p->getKey()))->values()->all())->get();
        $active = Station::query()->where('branch_id', $branchId)->where('status', Station::ACTIVE)
            ->whereIn('_id', $routes->map(fn ($r) => new ObjectId((string) $r->station_id))->all())->get()
            ->map(fn ($s) => (string) $s->getKey())->flip();
        $result = [];
        foreach ($routes->sortBy('priority') as $r) {
            $product = (string) $r->product_id;
            if (! isset($result[$product]) && $active->has((string) $r->station_id)) {
                $result[$product] = new ObjectId((string) $r->station_id);
            }
        }

        return $result;
    }

    /**
     * Opciones elegidas: deben pertenecer a un grupo del producto, estar activas y respetar mínimo y
     * máximo de cada grupo. Devuelve la copia para la partida y la suma de precios.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private static function modifiers(Product $product, Collection $modifiers, array $chosen, string $field): array
    {
        $allowed = $modifiers->filter(fn ($m) => in_array((string) $m->getKey(), array_map('strval', $product->modifier_ids ?? []), true));
        $snapshot = [];
        $counts = [];
        $delta = '0';
        $seen = [];
        foreach ($chosen as $choice) {
            $modifier = $allowed->get(strtolower($choice['modifierId']));
            $option = $modifier ? collect($modifier->options ?? [])->first(fn ($o) => $o['uuid'] === strtolower($choice['optionId']) && ($o['status'] ?? 'ACTIVE') === 'ACTIVE') : null;
            $pair = strtolower($choice['modifierId']).'|'.strtolower($choice['optionId']);
            if (! $option || isset($seen[$pair])) {
                throw ValidationException::withMessages([$field => 'Opción inválida, inactiva o repetida para este producto.']);
            }
            $seen[$pair] = true;
            $counts[$modifier->uuid] = ($counts[$modifier->uuid] ?? 0) + 1;
            $price = bcadd((string) ($option['price_delta'] ?? '0'), '0', 2);
            $delta = bcadd($delta, $price, 2);
            $snapshot[] = ['modifier_id' => $modifier->uuid, 'option_id' => $option['uuid'], 'name' => $option['name'], 'price_delta' => $price];
        }
        foreach ($allowed as $modifier) {
            $n = $counts[$modifier->uuid] ?? 0;
            if ($n < (int) $modifier->min_selections || $n > (int) $modifier->max_selections) {
                throw ValidationException::withMessages([$field => "\"{$modifier->name}\" admite de {$modifier->min_selections} a {$modifier->max_selections} opciones."]);
            }
        }

        return [$snapshot, $delta];
    }

    /**
     * Comandas con partidas y unidades, con pocas consultas para cualquier número de comandas.
     *
     * @return list<array<string, mixed>>
     */
    public static function presentMany(Branch $branch, Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }
        $orderIds = $orders->map(fn ($o) => new ObjectId($o->getKey()))->values()->all();
        $items = OrderItem::query()->whereIn('order_id', $orderIds)->get();
        $units = OrderItemUnit::query()->whereIn('order_id', $orderIds)->get();
        $refs = Refs::load(
            products: $items->pluck('product_id'),
            stations: $units->pluck('station_id'),
            users: $orders->pluck('created_by'),
            sessions: $orders->pluck('session_id'),
        );
        $orderUuids = $orders->mapWithKeys(fn ($o) => [(string) $o->getKey() => $o->uuid]);
        $itemUuids = $items->mapWithKeys(fn ($i) => [(string) $i->getKey() => $i->uuid]);
        $unitsByItem = $units->groupBy(fn ($u) => (string) $u->order_item_id);
        $itemsByOrder = $items->groupBy(fn ($i) => (string) $i->order_id);

        return $orders->map(fn (Order $o) => [
            'id' => $o->uuid,
            'branchId' => $branch->uuid,
            'sessionId' => $refs->session($o->session_id),
            'clientOrderUuid' => $o->client_order_uuid,
            'status' => $o->status,
            'items' => ($itemsByOrder->get((string) $o->getKey()) ?? collect())->map(fn (OrderItem $i) => [
                'id' => $i->uuid,
                'productId' => $refs->product($i->product_id),
                'name' => $i->name_snapshot,
                'quantity' => (int) $i->quantity,
                'unitPrice' => Floor::money($i->unit_price),
                'modifiers' => array_map(fn ($m) => [
                    'modifierId' => $m['modifier_id'],
                    'optionId' => $m['option_id'],
                    'name' => $m['name'],
                    'priceDelta' => Floor::money($m['price_delta']),
                ], $i->modifiers_snapshot ?? []),
                'notes' => $i->notes,
                'units' => ($unitsByItem->get((string) $i->getKey()) ?? collect())->sortBy('unit_number')
                    ->map(fn ($u) => self::unit($u, $refs, $orderUuids, $itemUuids))->values()->all(),
                'version' => (int) $i->version,
            ])->values()->all(),
            'total' => Floor::money($o->total),
            'businessDate' => $o->business_date,
            'capturedAt' => TableSessions::time($o->captured_at),
            'createdBy' => $refs->user($o->created_by),
            'createdAt' => TableSessions::time($o->created_at),
            'version' => (int) $o->version,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public static function unit(OrderItemUnit $u, Refs $refs, Collection $orderUuids, Collection $itemUuids): array
    {
        return [
            'id' => $u->uuid,
            'orderId' => $orderUuids[(string) $u->order_id],
            'orderItemId' => $itemUuids[(string) $u->order_item_id],
            'unitNumber' => (int) $u->unit_number,
            'status' => $u->status,
            'stationId' => $refs->station($u->station_id),
            'recipeVersion' => $u->recipe_version === null ? null : (int) $u->recipe_version,
            'reason' => $u->reason,
            'createdAt' => TableSessions::time($u->created_at),
            'preparingAt' => TableSessions::time($u->preparing_at),
            'readyAt' => TableSessions::time($u->ready_at),
            'deliveredAt' => TableSessions::time($u->delivered_at),
            'cancelledAt' => TableSessions::time($u->cancelled_at),
            'voidedAt' => TableSessions::time($u->voided_at),
            'version' => (int) $u->version,
        ];
    }
}
