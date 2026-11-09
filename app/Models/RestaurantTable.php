<?php

namespace App\Models;

use App\Models\Casts\ObjectId;
use App\Models\Concerns\HasVersion;

/** Mesa de una sucursal (T-1 a T-9). `status` es administrativo; el estado operativo se deriva al leer. */
class RestaurantTable extends Document
{
    use HasVersion;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    public const MAINTENANCE = 'MAINTENANCE';

    protected $table = 'tables';

    protected $hidden = ['qr_token', 'previous_qr_token', 'previous_qr_expires_at'];

    protected $casts = [
        'branch_id' => ObjectId::class,
        'number' => 'integer',
        'capacity' => 'integer',
    ];
}
