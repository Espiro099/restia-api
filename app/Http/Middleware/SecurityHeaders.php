<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en toda respuesta (Revision_Seguridad, sección 3). La API solo entrega JSON,
 * así que la CSP no permite cargar nada; una respuesta que trae su propia CSP (la página de contraseña,
 * con nonce) la conserva.
 */
class SecurityHeaders
{
    public const API_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::API_CSP);
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->remove('X-Powered-By');

        // HSTS solo por HTTPS: en HTTP el navegador la ignora (RFC 6797, 8.1).
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age='.(int) config('restia.security.hsts_seconds').'; includeSubDomains');
        }

        return $response;
    }
}
