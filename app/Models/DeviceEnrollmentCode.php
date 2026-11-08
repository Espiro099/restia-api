<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Casts\ObjectId;

/** Código de alta de un solo uso, 15 minutos (D-36, 4.3). Solo se guarda su hash. */
class DeviceEnrollmentCode extends Document
{
    protected $table = 'device_enrollment_codes';

    protected $hidden = ['code_hash'];

    protected $casts = [
        'branch_id' => ObjectId::class,
        'created_by' => ObjectId::class,
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
