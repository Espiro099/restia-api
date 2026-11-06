<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/** Base de todas las colecciones. Ver docs/Guia_Base_de_Datos_MongoDB.md, sección 5. */
abstract class Document extends Model
{
    use HasPublicUuid;

    protected $connection = 'mongodb';

    protected $guarded = ['_id'];

    /** Solo `_id` se convierte solo: cualquier otra referencia se consulta como ObjectId explícito. */
    public function scopeForBranch($query, EloquentModel|ObjectId|string $branch)
    {
        $id = $branch instanceof EloquentModel ? $branch->getKey() : $branch;

        return $query->where('branch_id', $id instanceof ObjectId ? $id : new ObjectId($id));
    }
}
