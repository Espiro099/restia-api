<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;

/**
 * Dispositivo Android registrado en una sucursal (D-36, 4.2). BLOCKED y RETIRED son finales:
 * si se recupera, se registra de nuevo con otro deviceUuid.
 */
class Device extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const BLOCKED = 'BLOCKED';

    public const RETIRED = 'RETIRED';

    protected $table = 'devices';

    protected $hidden = ['credential_hash', 'device_uuid', 'enrolled_by'];

    protected $casts = [
        'branch_id' => ObjectId::class,
        'enrolled_by' => ObjectId::class,
        'enrolled_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
