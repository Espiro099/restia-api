<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;

class Payment extends Document
{
    use HasVersion;

    protected $table = 'payments';
    protected $casts = ['branch_id' => ObjectId::class, 'amount' => 'decimal:2', 'tip' => 'decimal:2', 'confirmed_at' => 'datetime'];
}
