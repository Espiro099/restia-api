<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Symfony agrega `private` a todo Cache-Control sin `public` ni `private`. Con `no-store` sobra, porque
 * nadie puede guardar la respuesta, y el contrato exige exactamente `no-store` (header NoStore).
 */
final class NoStoreHeaders extends ResponseHeaderBag
{
    protected function computeCacheControlValue(): string
    {
        // $cacheControl es lo recién asignado; hasCacheControlDirective() leería el valor calculado anterior.
        return isset($this->cacheControl['no-store']) ? $this->getCacheControlHeader() : parent::computeCacheControlValue();
    }
}
