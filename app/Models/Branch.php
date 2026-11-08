<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;

/** Sucursal (D-08). En esta fase solo se lee; su gestión llega con el módulo Branches. */
class Branch extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $table = 'branches';

    protected $hidden = ['operations_epoch', 'menu_version'];

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
