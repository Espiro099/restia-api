<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Confía en el proxy TLS (Apache, Nginx) de RESTIA_TRUSTED_PROXIES: solo entonces X-Forwarded-Proto
 * marca la petición como HTTPS (HSTS, cookie Secure, redirección). Sin la variable no confía en nadie.
 */
class TrustTlsProxy extends TrustProxies
{
    protected function proxies()
    {
        $proxies = config('restia.security.trusted_proxies');
        if (! is_string($proxies) || $proxies === '') {
            return null;
        }

        return $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies));
    }
}
