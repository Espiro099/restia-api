<?php

namespace App\Floor;

use App\Models\Branch;
use App\Models\Check;
use App\Models\Order;
use App\Models\OrderItemUnit;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\TableSession;
use App\PublicQr\PublicQr;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;

/**
 * Mesas con su estado operativo derivado (T-5, sección 3), calculado en cada lectura.
 *
 * Una mesa sin asignación con una señal de presencia vigente del QR es POSIBLE_OCUPACION (T-6): solo
 * un indicio, porque cualquiera con una foto del QR puede enviarla.
 */
final class Tables
{
    public const ACTIVE_UNIT_STATES = [OrderItemUnit::PENDING, OrderItemUnit::PREPARING, OrderItemUnit::READY];

    /** @return list<array<string, mixed>> */
    public static function present(Branch $branch, Collection $tables): array
    {
        if ($tables->isEmpty()) {
            return [];
        }
        $branchId = new ObjectId($branch->getKey());
        $assignments = TableAssignment::query()->where('branch_id', $branchId)->where('active', true)
            ->whereIn('table_id', $tables->map(fn ($t) => new ObjectId($t->getKey()))->all())
            ->get()->keyBy(fn ($a) => (string) $a->table_id);

        $sessionIds = $assignments->pluck('session_id')->map(fn ($id) => (string) $id)->unique()->values();
        $sessions = TableSession::query()->whereIn('_id', $sessionIds->map(fn ($id) => new ObjectId($id))->all())->get()->keyBy(fn ($s) => (string) $s->getKey());
        $activity = self::activity($branchId, $sessionIds->all());
        $signaled = PublicQr::signaled($branchId);
        // Regla 5: alguna cuenta en REQUESTED o PARTIALLY_PAID.
        $requested = Check::query()->where('branch_id', $branchId)->whereIn('session_id', $sessionIds->map(fn ($id) => new ObjectId($id))->all())
            ->whereIn('status', [Check::REQUESTED, Check::PARTIALLY_PAID])->get(['session_id'])->map(fn ($c) => (string) $c->session_id)->unique()->all();

        return $tables->map(function (RestaurantTable $t) use ($branch, $assignments, $sessions, $activity, $requested, $signaled) {
            $a = $assignments->get((string) $t->getKey());
            $sessionId = $a ? (string) $a->session_id : null;

            return [
                'id' => $t->uuid,
                'branchId' => $branch->uuid,
                'number' => (int) $t->number,
                'capacity' => (int) $t->capacity,
                'zone' => $t->zone,
                'status' => $t->status,
                'operationalState' => self::state($t, $a, $activity[$sessionId] ?? [], in_array($sessionId, $requested, true), isset($signaled[(string) $t->getKey()])),
                'currentSessionId' => $sessionId ? $sessions->get($sessionId)?->uuid : null,
                'version' => (int) $t->version,
                'combinationGroup' => $t->combination_group,
            ];
        })->values()->all();
    }

    /** Primera regla que coincide, en el orden de T-5. */
    private static function state(RestaurantTable $t, ?TableAssignment $a, array $units, bool $checkRequested, bool $signaled = false): ?string
    {
        return match (true) {
            $t->status !== RestaurantTable::ACTIVE => null,
            $a?->phase === 'CLEANING' => 'POR_LIMPIAR',
            $a === null => $signaled ? 'POSIBLE_OCUPACION' : 'LIBRE',
            $checkRequested => 'CUENTA_SOLICITADA',
            (bool) array_intersect($units, self::ACTIVE_UNIT_STATES) => 'ESPERANDO_COMIDA',
            in_array(OrderItemUnit::DELIVERED, $units, true) => 'CONSUMIENDO',
            default => 'OCUPADA_SIN_COMANDA',
        };
    }

    /**
     * Estados de unidad presentes en cada sesión.
     *
     * @param  list<string>  $sessionIds
     * @return array<string, list<string>>
     */
    private static function activity(ObjectId $branchId, array $sessionIds): array
    {
        if (! $sessionIds) {
            return [];
        }
        $orders = Order::query()->where('branch_id', $branchId)
            ->whereIn('session_id', array_map(fn ($id) => new ObjectId($id), $sessionIds))
            ->get(['_id', 'session_id'])->mapWithKeys(fn ($o) => [(string) $o->getKey() => (string) $o->session_id]);
        if ($orders->isEmpty()) {
            return [];
        }
        $result = [];
        $units = OrderItemUnit::query()->where('branch_id', $branchId)
            ->whereIn('order_id', $orders->keys()->map(fn ($id) => new ObjectId($id))->all())
            ->get(['order_id', 'status']);
        foreach ($units as $u) {
            $result[$orders[(string) $u->order_id]][$u->status] = true;
        }

        return array_map('array_keys', $result);
    }
}
