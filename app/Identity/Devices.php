<?php

namespace App\Identity;

use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use App\Support\Outbox;
use MongoDB\BSON\ObjectId;

/** Bloqueo y retiro (D-36, 5.6). Son finales e idempotentes: repetir no cambia nada ni audita otra vez. */
final class Devices
{
    public static function block(Branch $branch, Device $device, User $actor, string $reason): void
    {
        self::close($branch, $device, $actor, $reason, Device::BLOCKED, 'DEVICE_BLOCKED', 'device.blocked');
    }

    public static function retire(Branch $branch, Device $device, User $actor, string $reason): void
    {
        // Mismo motivo de revocación: el catálogo de D-36 no distingue el retiro.
        self::close($branch, $device, $actor, $reason, Device::RETIRED, 'DEVICE_BLOCKED', 'device.retired');
    }

    private static function close(Branch $branch, Device $device, User $actor, string $reason, string $status, string $revocation, string $event): void
    {
        Mongo::transaction(function () use ($branch, $device, $actor, $reason, $status, $revocation, $event) {
            $now = now();
            $deviceId = new ObjectId($device->getKey());
            $actorId = new ObjectId($actor->getKey());
            $branchId = new ObjectId($branch->getKey());

            // Solo desde ACTIVE: un dispositivo BLOCKED conserva su estado aunque se pida retirarlo.
            $changed = Device::query()->where('_id', $deviceId)->where('status', Device::ACTIVE)
                ->increment('version', 1, ['status' => $status, 'updated_at' => $now]);
            if ($changed === 0) {
                return;
            }

            $revoked = 0;
            $sessions = AuthSession::query()->where('device_id', $deviceId)->where('status', AuthSession::ACTIVE)->get();
            foreach ($sessions as $session) {
                $revoked += (int) Sessions::revoke($session, $revocation, $actorId, $branchId);
            }
            Audit::record($event, 'device', $deviceId, $branchId, $actorId, reason: $reason, changes: [
                'status' => ['from' => Device::ACTIVE, 'to' => $status],
                'sessionsRevoked' => $revoked,
            ]);
            Outbox::publish($event, 'device', $deviceId, $branchId, ['deviceId' => $device->uuid]);
        });
    }
}
