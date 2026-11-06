<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** El `_id` nunca sale de la API: hacia afuera solo existe `uuid` (D-22). */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
