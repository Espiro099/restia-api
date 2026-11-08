<?php

namespace App\Catalog;

use App\Exceptions\DomainConflict;
use App\Floor\Tables;
use App\Models\Branch;
use App\Models\OrderItemUnit;
use App\Models\Product;
use App\Models\ProductPreparationRoute;
use App\Models\Station;
use App\Models\User;
use MongoDB\BSON\ObjectId;

/** Estaciones de preparación (M-2). No se borran: se desactivan. */
final class Stations
{
    /** @return array<string, mixed> */
    public static function present(Branch $branch, Station $s): array
    {
        return [
            'id' => $s->uuid,
            'branchId' => $branch->uuid,
            'code' => $s->code,
            'name' => $s->name,
            'status' => $s->status,
            'version' => (int) $s->version,
        ];
    }

    /** @param array{code: string, name: string} $in */
    public static function create(Branch $branch, User $actor, array $in): Station
    {
        return Catalog::create($branch, $actor, 'station', 'CATALOG_CODE_TAKEN', fn (ObjectId $branchId) => Station::query()->create([
            'branch_id' => $branchId, 'code' => $in['code'], 'name' => $in['name'], 'status' => Station::ACTIVE,
        ]));
    }

    public static function rename(Branch $branch, User $actor, Station $s, int $version, string $name): Station
    {
        return Catalog::change($branch, $actor, $s, $version, 'station.updated', 'station', fn () => ['name' => $name]);
    }

    public static function setStatus(Branch $branch, User $actor, Station $s, int $version, string $status, string $reason): Station
    {
        if ($s->status === $status) {
            return $s;
        }

        return Catalog::change($branch, $actor, $s, $version, 'station.status_changed', 'station', function (Station $fresh) use ($status) {
            if ($status === Station::INACTIVE) {
                self::assertIdle($fresh);
            }

            return ['status' => $status];
        }, reason: $reason);
    }

    /** STATION_IN_USE: unidades sin terminar en la estación, o un producto activo que solo puede ir ahí. */
    private static function assertIdle(Station $s): void
    {
        $stationId = new ObjectId($s->getKey());
        $branchId = new ObjectId((string) $s->branch_id);
        $busy = OrderItemUnit::query()->where('branch_id', $branchId)->where('station_id', $stationId)
            ->whereIn('status', Tables::ACTIVE_UNIT_STATES)->exists();
        if ($busy) {
            throw new DomainConflict('STATION_IN_USE', ['reason' => 'UNFINISHED_UNITS']);
        }

        $productIds = ProductPreparationRoute::query()->where('branch_id', $branchId)->where('station_id', $stationId)->get()
            ->map(fn ($r) => new ObjectId((string) $r->product_id))->all();
        $active = Product::query()->where('branch_id', $branchId)->where('status', Product::ACTIVE)->whereIn('_id', $productIds)->get();
        if ($active->isEmpty()) {
            return;
        }
        $others = Station::query()->where('branch_id', $branchId)->where('status', Station::ACTIVE)->where('_id', '!=', $stationId)->get()
            ->map(fn ($st) => (string) $st->getKey())->all();
        $covered = ProductPreparationRoute::query()->where('branch_id', $branchId)
            ->whereIn('product_id', $active->map(fn ($p) => new ObjectId($p->getKey()))->all())
            ->whereIn('station_id', array_map(fn ($id) => new ObjectId($id), $others))->get()
            ->map(fn ($r) => (string) $r->product_id)->unique()->all();
        $stranded = $active->reject(fn ($p) => in_array((string) $p->getKey(), $covered, true));
        if ($stranded->isNotEmpty()) {
            throw new DomainConflict('STATION_IN_USE', ['reason' => 'ONLY_ROUTE', 'productIds' => $stranded->pluck('uuid')->values()->all()]);
        }
    }
}
