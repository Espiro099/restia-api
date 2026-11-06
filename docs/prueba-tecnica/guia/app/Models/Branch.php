<?php

namespace App\Models;

use App\Models\Concerns\HasVersion;

class Branch extends Document
{
    use HasVersion;

    protected $table = 'branches';
}
