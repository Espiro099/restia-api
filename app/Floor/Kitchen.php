<?php

namespace App\Floor;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemUnit;
use App\Models\RestaurantTable;
use App\Models\Station;
use App\Models\TableAssignment;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\Floor;
use App\Support\Mongo;
use App\Support\Outbox;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

/**
 * Cola de cocina, barra y entrega (GET /order-units), notas de una partida (O-2) y reasignación de
 * estación (O-11). La cola se consulta cada pocos segundos con ETag (RT-1).
 */
final class Kitchen
{
    /**
     * Unidades de la más antigua a la más reciente. Sin `status`, solo las que siguen en curso
     * (PENDING, PREPARING y READY): lo que cocina y piso tienen que atender.
     *
     * @param  array{stationId?: string, status?: string, sessionId?: string}  $filter
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public static function queue(Branch $branch, array $filter, int $page, int $perPage): array
    {
        $branchId = new ObjectId($branch->getKey());
        $query = OrderItemUnit::query()->where('branch_id', $branchId);
        if (isset($filter['status'])) {
            $query->where('status', $filter['status']);
        } else {
            $query->whereIn('status', Tables::ACTIVE_UNIT_STATES);
        }
        if (isset($filter['stationId'])) {
            $station = Station::query()->where('branch_id', $branchId)->where('uuid', strtolower($filter['stationId']))->first();
            $query->whereIn('station_id', $station ? [new ObjectId($station->getKey())] : []);
        }
        if (isset($filter['sessionId'])) {
            $session = TableSession::query()->where('branch_id', $branchId)->where('uuid', strtolower($filter['sessionId']))->first();
            $orderIds = $session ? Order::query()->where('branch_id', $branchId)->where('session_id', new ObjectId($session->getKey()))->get(['_id'])
                ->map(fn ($o) => new ObjectId($o->getKey()))->all() : [];
            $query->whereIn('order_id', $orderIds);
        }
        $total = (clone $query)->count();
        $units = $query->orderBy('created_at')->orderBy('_id')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return [self::present($branchId, $units), $total];
    }

    public static function editNotes(Branch $branch, User $actor, OrderItem $item, int $version, ?string $notes): OrderItem
    {
        $branchId = new ObjectId($branch->getKey());
        $notes = $notes === null || trim($notes) === '' ? null : $notes;

        Mongo::transaction(function () use ($branchId, $actor, $item, $version, $notes) {
            $fresh = OrderItem::query()->find($item->getKey());
            if ((int) $fresh->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            // Una vez que cocina empezó, cambiar la nota ya no sirve: se cancela y se vuelve a pedir (O-2).
            $started = OrderItemUnit::query()->where('branch_id', $branchId)->where('order_item_id', new ObjectId($fresh->getKey()))
                ->where('status', '!=', OrderItemUnit::PENDING)->get(['uuid']);
            if ($started->isNotEmpty()) {
                throw new DomainConflict('ORDER_UNIT_INVALID_STATE', ['unitIds' => $started->pluck('uuid')->values()->all()]);
            }
            $before = $fresh->notes;
            if (! $fresh->updateIfVersion($version, ['notes' => $notes])) {
                throw new DomainConflict('VERSION_CONFLICT');
            }
            Audit::record('order_item.updated', 'order_item', $fresh->getKey(), $branchId, new ObjectId($actor->getKey()), changes: [
                'before' => ['notes' => $before], 'after' => ['notes' => $notes],
            ]);
            Outbox::publish('order_item.updated', 'order_item', $fresh->getKey(), $branchId, ['orderItemId' => $fresh->uuid]);
        });

        return $item->refresh();
    }

    public static function reassign(Branch $branch, User $actor, OrderItemUnit $unit, int $version, string $stationUuid, string $reason): OrderItemUnit
    {
        $branchId = new ObjectId($branch->getKey());
        $station = Station::query()->where('branch_id', $branchId)->where('uuid', strtolower($stationUuid))->first();
        if (! $station || $station->status !== Station::ACTIVE) {
            throw ValidationException::withMessages(['stationId' => $station ? 'La estación está inactiva.' : 'La estación no existe en esta sucursal.']);
        }
        $to = new ObjectId($station->getKey());

        Mongo::transaction(function () use ($branchId, $actor, $unit, $version, $to, $reason) {
            $fresh = OrderItemUnit::query()->find($unit->getKey());
            if ((int) $fresh->version !== $version) {
                throw new DomainConflict('VERSION_CONFLICT', ['unitIds' => [$fresh->uuid]]);
            }
            if ($fresh->status !== OrderItemUnit::PENDING) {
                throw new DomainConflict('ORDER_UNIT_INVALID_STATE', ['unitIds' => [$fresh->uuid]]);
            }
            $from = (string) $fresh->station_id;
            if ($from === (string) $to) {
                return;
            }
            // La estación destino se vuelve a leer aquí: si alguien la desactivó, no recibe la unidad.
            if (! Station::query()->where('_id', $to)->where('status', Station::ACTIVE)->exists()) {
                throw ValidationException::withMessages(['stationId' => 'La estación está inactiva.']);
            }
            if (! $fresh->updateIfVersion($version, ['station_id' => $to])) {
                throw new DomainConflict('VERSION_CONFLICT', ['unitIds' => [$fresh->uuid]]);
            }
            Audit::record('order_unit.reassigned', 'order_unit', $fresh->getKey(), $branchId, new ObjectId($actor->getKey()), reason: $reason, changes: [
                'before' => ['station_id' => $from], 'after' => ['station_id' => (string) $to],
            ]);
            Outbox::publish('order_unit.reassigned', 'order_unit', $fresh->getKey(), $branchId, ['unitId' => $fresh->uuid]);
        });

        return $unit->refresh();
    }

    /** @return array<string, mixed> */
    public static function unit(Branch $branch, OrderItemUnit $u): array
    {
        return self::present(new ObjectId($branch->getKey()), collect([$u]))[0]['unit'];
    }

    /**
     * Cada unidad con lo que cocina necesita para prepararla: nombre, modificadores, notas y mesas.
     *
     * @param  Collection<int, OrderItemUnit>  $units
     * @return list<array<string, mixed>>
     */
    private static function present(ObjectId $branchId, Collection $units): array
    {
        if ($units->isEmpty()) {
            return [];
        }
        $oid = fn (Collection $ids) => $ids->map(fn ($id) => (string) $id)->unique()->map(fn ($id) => new ObjectId($id))->values()->all();
        $orders = Order::query()->whereIn('_id', $oid($units->pluck('order_id')))->get()->keyBy(fn ($o) => (string) $o->getKey());
        $items = OrderItem::query()->whereIn('_id', $oid($units->pluck('order_item_id')))->get()->keyBy(fn ($i) => (string) $i->getKey());
        $refs = Refs::load(collect(), $units->pluck('station_id'), collect(), collect());
        $tables = self::tableNumbers($branchId, $orders->pluck('session_id'));

        $orderUuids = $orders->map(fn ($o) => $o->uuid);
        $itemUuids = $items->map(fn ($i) => $i->uuid);

        return $units->map(function (OrderItemUnit $u) use ($orders, $items, $refs, $tables, $orderUuids, $itemUuids) {
            $item = $items[(string) $u->order_item_id];

            return [
                'unit' => Orders::unit($u, $refs, $orderUuids, $itemUuids),
                'productName' => $item->name_snapshot,
                'modifiers' => array_map(fn ($m) => [
                    'modifierId' => $m['modifier_id'],
                    'optionId' => $m['option_id'],
                    'name' => $m['name'],
                    'priceDelta' => Floor::money($m['price_delta']),
                ], $item->modifiers_snapshot ?? []),
                'notes' => $item->notes,
                'tableNumbers' => $tables[(string) $orders[(string) $u->order_id]->session_id] ?? [],
            ];
        })->values()->all();
    }

    /**
     * Mesas de cada sesión: las que tiene ahora o, si ya terminó, las que tuvo.
     *
     * @return array<string, list<int>>
     */
    public static function tableNumbers(ObjectId $branchId, Collection $sessionIds): array
    {
        $ids = $sessionIds->map(fn ($id) => (string) $id)->unique()->map(fn ($id) => new ObjectId($id))->values()->all();
        $assignments = TableAssignment::query()->where('branch_id', $branchId)->whereIn('session_id', $ids)->get();
        $numbers = RestaurantTable::query()->whereIn('_id', $assignments->map(fn ($a) => new ObjectId((string) $a->table_id))->all())->get()
            ->mapWithKeys(fn ($t) => [(string) $t->getKey() => (int) $t->number]);

        return $assignments->groupBy(fn ($a) => (string) $a->session_id)->map(function (Collection $rows) use ($numbers) {
            $current = $rows->where('active', true);

            return ($current->isNotEmpty() ? $current : $rows)->map(fn ($a) => $numbers[(string) $a->table_id] ?? null)
                ->filter()->unique()->sort()->values()->all();
        })->all();
    }
}
