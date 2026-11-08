<?php

namespace App\Support;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * audit_log es de solo inserción (Guía, sección 9). Se escribe con DB::table() para que viaje en la
 * transacción en curso. Nunca recibe contraseñas, tokens, hashes ni credenciales (D-36, sección 7).
 */
final class Audit
{
    /** @param array<string, mixed> $changes */
    public static function record(
        string $action,
        string $entityType,
        ObjectId|string|null $entityId,
        ObjectId|string|null $branchId = null,
        ObjectId|string|null $actorId = null,
        string $actorType = 'USER',
        ?string $reason = null,
        array $changes = [],
    ): void {
        DB::connection('mongodb')->table('audit_log')->insert([
            'branch_id' => self::oid($branchId),
            'actor_type' => $actorId === null ? 'SYSTEM' : $actorType,
            'actor_id' => self::oid($actorId),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => self::oid($entityId),
            'reason' => $reason,
            'changes' => $changes ?: null,
            'request_id' => AssignRequestId::current(),
            'created_at' => new UTCDateTime,
        ]);
    }

    public static function oid(ObjectId|string|null $id): ?ObjectId
    {
        return $id === null || $id instanceof ObjectId ? $id : new ObjectId($id);
    }
}
