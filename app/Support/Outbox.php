<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/** Eventos para procesos asíncronos, en la misma transacción que el cambio (Guía, sección 9). */
final class Outbox
{
    /** @param array<string, mixed> $payload */
    public static function publish(string $type, string $sourceType, ObjectId|string $sourceId, ObjectId|string|null $branchId, array $payload = []): void
    {
        $now = new UTCDateTime;
        DB::connection('mongodb')->table('outbox_events')->insert([
            'branch_id' => Audit::oid($branchId),
            'type' => $type,
            'source_type' => $sourceType,
            'source_id' => Audit::oid($sourceId),
            'payload' => $payload,
            'status' => 'PENDING',
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
