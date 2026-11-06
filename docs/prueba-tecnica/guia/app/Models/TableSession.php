<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;

class TableSession extends Document
{
    use HasVersion;

    protected $table = 'table_sessions';
    protected $hidden = ['_id', 'activity_epoch'];
    protected $casts = ['branch_id' => ObjectId::class, 'responsible_user_id' => ObjectId::class];
}
