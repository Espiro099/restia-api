<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/** Límites por minuto de D-36, sección 8. Al excederlos, 429 TOO_MANY_ATTEMPTS con Retry-After. */
final class Throttle
{
    /**
     * Cuenta un intento. $onLimit se ejecuta una sola vez, en el intento que alcanza el límite.
     */
    public static function hit(string $key, int $max, ?Closure $onLimit = null): void
    {
        $key = 'restia:'.hash('sha256', $key);
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = max(1, RateLimiter::availableIn($key));
            throw new ThrottleRequestsException(headers: ['Retry-After' => (string) $seconds]);
        }
        if (RateLimiter::hit($key, 60) === $max && $onLimit) {
            $onLimit();
        }
    }
}
