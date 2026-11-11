<?php

namespace App\Catalog;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\User;
use App\PublicQr\PublicQr;
use MongoDB\BSON\ObjectId;

/** Alta y cambios administrativos de mesas. El estado operativo no se escribe: se deriva al leer (T-5). */
final class TableAdmin
{
    private const FIELDS = ['number' => 'number', 'capacity' => 'capacity', 'zone' => 'zone', 'combinationGroup' => 'combination_group'];

    /** @param array<string, mixed> $in */
    public static function create(Branch $branch, User $actor, array $in): RestaurantTable
    {
        return Catalog::create($branch, $actor, 'table', 'TABLE_NUMBER_TAKEN', fn (ObjectId $branchId) => RestaurantTable::query()->create([
            'branch_id' => $branchId, 'number' => (int) $in['number'], 'capacity' => (int) $in['capacity'],
            'zone' => $in['zone'] ?? null, 'combination_group' => $in['combinationGroup'] ?? null, 'status' => RestaurantTable::ACTIVE,
            // QR-3: el token se genera con la mesa y se imprime una vez.
            'qr_token' => PublicQr::newToken(),
        ]), menu: false);
    }

    /** @param array<string, mixed> $in */
    public static function update(Branch $branch, User $actor, RestaurantTable $t, int $version, array $in): RestaurantTable
    {
        $changes = [];
        foreach (self::FIELDS as $field => $column) {
            if (array_key_exists($field, $in)) {
                $changes[$column] = $in[$field];
            }
        }

        return Catalog::change($branch, $actor, $t, $version, 'table.updated', 'table', fn () => $changes, menu: false, taken: 'TABLE_NUMBER_TAKEN');
    }

    /**
     * Para salir de ACTIVE la mesa no puede tener una asignación activa (sesión o limpieza pendiente).
     * Sube `operations_epoch` igual que abrir una sesión, para que las dos operaciones choquen y no
     * pueda abrirse una mesa mientras pasa a mantenimiento.
     */
    public static function setStatus(Branch $branch, User $actor, RestaurantTable $t, int $version, string $status, string $reason): RestaurantTable
    {
        if ($t->status === $status) {
            return $t;
        }

        return Catalog::change($branch, $actor, $t, $version, 'table.status_changed', 'table', function (RestaurantTable $fresh) use ($status) {
            $branchId = new ObjectId((string) $fresh->branch_id);
            Branch::query()->where('_id', $branchId)->increment('operations_epoch');
            $assigned = TableAssignment::query()->where('branch_id', $branchId)->where('table_id', new ObjectId($fresh->getKey()))
                ->where('active', true)->exists();
            if ($status !== RestaurantTable::ACTIVE && $assigned) {
                throw new DomainConflict('TABLE_ALREADY_ASSIGNED');
            }

            return ['status' => $status];
        }, reason: $reason, menu: false);
    }
}
