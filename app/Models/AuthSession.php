<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;

/** Un inicio de sesión, consultable y revocable (D-36, 4.1). */
class AuthSession extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const REVOKED = 'REVOKED';

    public const EXPIRED = 'EXPIRED';

    public const ANDROID = 'ANDROID';

    public const WEB = 'WEB';

    protected $table = 'auth_sessions';

    protected $hidden = ['refresh_hash', 'previous_refresh_hash', 'ip_created'];

    protected $casts = [
        'user_id' => ObjectId::class,
        'device_id' => ObjectId::class,
        'revoked_by' => ObjectId::class,
        'previous_valid_until' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'absolute_expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'revoked_at' => 'datetime',
        'purge_at' => 'datetime',
    ];

    /** Activa y dentro de sus vencimientos. */
    public function isUsable(): bool
    {
        $now = now();

        return $this->status === self::ACTIVE
            && $this->absolute_expires_at->gt($now)
            && ($this->client_type !== self::ANDROID || $this->refresh_expires_at->gt($now));
    }

    /** Estado hacia la API: una sesión ACTIVE con vencimiento pasado se informa como EXPIRED. */
    public function publicStatus(): string
    {
        return $this->status === self::ACTIVE && ! $this->isUsable() ? self::EXPIRED : $this->status;
    }
}
