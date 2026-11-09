<?php

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use MongoDB\BSON\ObjectId as BSONObjectId;

/**
 * Referencia a otro documento: se guarda como ObjectId y se lee como texto. A diferencia del cast de
 * mongodb/laravel-mongodb, respeta null: aquel convierte null en un ObjectId nuevo, que apunta a nada.
 */
class ObjectId implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): mixed
    {
        return $value instanceof BSONObjectId ? (string) $value : $value;
    }

    public function set($model, string $key, $value, array $attributes): mixed
    {
        return $value === null || $value instanceof BSONObjectId ? $value : new BSONObjectId($value);
    }
}
