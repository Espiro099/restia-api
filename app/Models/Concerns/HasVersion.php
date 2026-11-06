<?php

namespace App\Models\Concerns;

/** Concurrencia optimista: la API la expone como `version`, `ETag` e `If-Match` (D-28). */
trait HasVersion
{
    public static function bootHasVersion(): void
    {
        static::creating(function ($model) {
            $model->version ??= 1;
        });
    }

    /** Devuelve false si otra operación cambió el documento: la API responde 409 VERSION_CONFLICT. */
    public function updateIfVersion(int $expected, array $changes): bool
    {
        $changes['updated_at'] = now();
        $modified = static::query()
            ->where('_id', $this->getKey())
            ->where('version', $expected)
            ->increment('version', 1, $changes);

        if ($modified === 0) {
            return false;
        }
        $this->refresh();

        return true;
    }
}
