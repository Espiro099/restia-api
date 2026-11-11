<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainConflict;
use App\PublicQr\PublicQr;
use App\Support\Throttle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API pública del QR: el token llega en `X-QR-Token` (QR-5) y basta como credencial. Un token inválido
 * siempre responde igual, sin decir si existió (QR-9). La respuesta no se indexa, no manda Referer y
 * no se guarda en caché compartida (QR-11).
 */
class PublicQrToken
{
    public const TABLE = 'qr_table';

    public const BRANCH = 'qr_branch';

    public function handle(Request $request, Closure $next): Response
    {
        $limits = config('restia.public.limits');
        Throttle::hit('qr-read|'.$request->ip(), $limits['reads_per_ip']);
        $resolved = PublicQr::resolve((string) $request->header('X-QR-Token', ''));
        if (! $resolved) {
            Throttle::hit('qr-invalid|'.$request->ip(), $limits['invalid_per_ip']);
            throw new DomainConflict('QR_TOKEN_INVALID');
        }
        $request->attributes->set(self::TABLE, $resolved[0]);
        $request->attributes->set(self::BRANCH, $resolved[1]);

        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }
}
