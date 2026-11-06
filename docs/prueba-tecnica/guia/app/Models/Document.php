<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use MongoDB\BSON\ObjectId as BsonObjectId;
use MongoDB\Laravel\Eloquent\Model;

abstract class Document extends Model
{
    use HasPublicUuid;

    protected $connection = 'mongodb';
    protected $guarded = ['_id'];

    /** Solo `_id` se convierte solo: cualquier otra referencia se consulta como ObjectId explícito. */
    public function scopeForBranch($query, Branch|string $branch)
    {
        return $query->where('branch_id', new BsonObjectId($branch instanceof Branch ? $branch->getKey() : $branch));
    }
}
