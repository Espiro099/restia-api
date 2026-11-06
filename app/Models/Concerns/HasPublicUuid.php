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

    public function initializeHasPublicUuid(): void
    {
        $this->hidden = array_values(array_unique([...$this->hidden, '_id', 'id']));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
