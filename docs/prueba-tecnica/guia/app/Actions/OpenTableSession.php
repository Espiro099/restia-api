<?php

namespace App\Actions;

use App\Exceptions\DomainConflict;
use App\Models\Branch;
use App\Models\RestaurantTable;
use App\Models\TableAssignment;
use App\Models\TableSession;
use App\Support\Mongo;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use Throwable;

final class OpenTableSession
{
    /** @param list<string> $tableUuids La API recibe UUID; dentro de la base se referencia por ObjectId. */
    public function run(string $branchUuid, array $tableUuids, int $guests, string $userId, string $idempotencyKey): array
    {
        $hash = hash('sha256', json_encode([$tableUuids, $guests]));

        try {
            return Mongo::transaction(function () use ($branchUuid, $tableUuids, $guests, $userId, $idempotencyKey, $hash) {
                $db = DB::connection('mongodb');
                $branch = Branch::where('uuid', $branchUuid)->first() ?? throw new DomainConflict('BRANCH_INACTIVE');
                $branchId = new ObjectId($branch->getKey());
                $actorId = new ObjectId($userId);

                // 1. Idempotencia: misma clave y contenido devuelve el resultado original (CON-1).
                $previous = $db->table('idempotency_records')
                    ->where('branch_id', $branchId)->where('key', $idempotencyKey)->first();
                if ($previous) {
                    $same = $previous->request_hash === $hash && (string) $previous->actor_id === $userId;

                    return $same ? json_decode($previous->response_body, true)
                        : throw new DomainConflict('IDEMPOTENCY_KEY_REUSED');
                }

                // 2. Primero se escribe el epoch: serializa con desactivar sucursal o poner una mesa en mantenimiento (T-9).
                Branch::where('_id', $branch->getKey())->increment('operations_epoch');
                if ($branch->status !== 'ACTIVE') {
                    throw new DomainConflict('BRANCH_INACTIVE');
                }

                // 3. Estado actual, ya dentro de la transacción.
                $tables = RestaurantTable::forBranch($branch)->whereIn('uuid', $tableUuids)->get();
                if ($tables->count() !== count($tableUuids) || $tables->contains(fn ($t) => $t->status !== 'ACTIVE')) {
                    throw new DomainConflict('TABLE_NOT_AVAILABLE');
                }

                // 4. Escrituras. El índice parcial deja una sola asignación activa por mesa.
                $session = TableSession::create([
                    'branch_id' => $branch->getKey(), 'status' => 'ACTIVE', 'guest_count' => $guests,
                    'responsible_user_id' => $userId, 'membership_version' => 1, 'activity_epoch' => 0, 'opened_at' => now(),
                ]);
                foreach ($tables as $table) {
                    TableAssignment::create([
                        'branch_id' => $branch->getKey(), 'table_id' => $table->getKey(), 'session_id' => $session->getKey(),
                        'phase' => 'SEATED', 'active' => true,
                    ]);
                }

                // 5. Auditoría, outbox e idempotencia en la misma transacción. Hacia afuera, solo UUID.
                $result = ['id' => $session->uuid, 'status' => 'ACTIVE', 'version' => 1];
                $db->table('audit_log')->insert(['branch_id' => $branchId, 'action' => 'table_session.opened', 'entity_id' => $session->uuid, 'actor_id' => $actorId, 'created_at' => now()]);
                $db->table('outbox_events')->insert(['branch_id' => $branchId, 'type' => 'table_session.opened', 'source_id' => $session->uuid, 'status' => 'PENDING', 'next_attempt_at' => now(), 'created_at' => now()]);
                $db->table('idempotency_records')->insert([
                    'branch_id' => $branchId, 'key' => $idempotencyKey, 'actor_id' => $actorId, 'operation_id' => 'openTableSession',
                    'request_hash' => $hash, 'status_code' => 201, 'response_body' => json_encode($result), 'resource_id' => $session->uuid,
                    'created_at' => now(), 'expires_at' => now()->addHours(72),
                ]);

                return $result;
            });
        } catch (Throwable $e) {
            throw Mongo::isDuplicateKey($e) ? new DomainConflict('TABLE_ALREADY_ASSIGNED') : $e;
        }
    }
}
