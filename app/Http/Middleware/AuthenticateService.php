<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainConflict;
use App\Identity\ServiceIdentities;
use App\Models\ServiceIdentity;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service Bearer Token (D-36, sección 11): identidad activa y token vigente, el actual o el anterior
 * durante su gracia tras rotar. Se consulta en cada petición, así que revocar corta de inmediato.
 * Uso: `->middleware('auth.service')` o, para un tipo, `->middleware('auth.service:PRINTING_AGENT')`.
 */
class AuthenticateService
{
    public const IDENTITY = 'service_identity';

    public function handle(Request $request, Closure $next, ?string $type = null): Response
    {
        $token = $request->bearerToken();
        $identity = $token ? ServiceIdentities::authenticate($token) : null;
        if (! $identity) {
            throw new AuthenticationException;
        }
        if ($type !== null && $identity->type !== $type) {
            throw new DomainConflict('FORBIDDEN');
        }
        $request->attributes->set(self::IDENTITY, $identity);

        return $next($request);
    }

    public static function identity(Request $request): ServiceIdentity
    {
        return $request->attributes->get(self::IDENTITY);
    }
}
