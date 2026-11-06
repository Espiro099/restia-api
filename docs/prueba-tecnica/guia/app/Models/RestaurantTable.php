<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;

class RestaurantTable extends Document
{
    use HasVersion;

    protected $table = 'tables';
    protected $hidden = ['_id', 'qr_token', 'previous_qr_token'];
    protected $casts = ['branch_id' => ObjectId::class];
}
