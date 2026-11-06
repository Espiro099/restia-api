<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Casts\ObjectId;

class TableAssignment extends Document
{
    protected $table = 'table_assignments';
    protected $casts = ['branch_id' => ObjectId::class, 'table_id' => ObjectId::class, 'session_id' => ObjectId::class];
}
