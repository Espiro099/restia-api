<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Acceso de un usuario a una sucursal con un rol (D-12, U-3). Una baja la deja REVOKED (U-5). */
class Membership extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const REVOKED = 'REVOKED';

    public const ROLES = ['ADMIN', 'MANAGER', 'WAITER', 'KITCHEN'];

    protected $table = 'user_branch_memberships';

    protected $casts = ['user_id' => ObjectId::class, 'branch_id' => ObjectId::class];

    /** @return list<string> */
    public function permissions(): array
    {
        return config('permissions.roles.'.$this->role, []);
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
