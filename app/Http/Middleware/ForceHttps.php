<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con RESTIA_FORCE_HTTPS, toda petición por HTTP se redirige a HTTPS con 308, que conserva el método
 * y el cuerpo. El proxy TLS ya redirige el puerto 80; esto cubre a quien llegue directo a PHP.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('restia.security.force_https') && ! $request->isSecure()) {
            return redirect()->to('https://'.$request->getHttpHost().$request->getRequestUri(), 308);
        }

        return $next($request);
    }
}
