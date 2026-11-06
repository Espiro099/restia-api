<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Usa el X-Request-ID del cliente si es un UUID válido; si no, genera uno (contrato: header XRequestId). */
class AssignRequestId
{
    public const HEADER = 'X-Request-ID';

    public const ATTRIBUTE = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $sent = (string) $request->headers->get(self::HEADER, '');
        $id = Str::isUuid($sent) ? strtolower($sent) : (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function current(?Request $request = null): string
    {
        $request ??= request();
        $id = $request->attributes->get(self::ATTRIBUTE);
        if (! is_string($id)) {
            // Una excepción antes del middleware: se asigna aquí para que el error también lo lleve.
            $id = (string) Str::uuid();
            $request->attributes->set(self::ATTRIBUTE, $id);
        }

        return $id;
    }
}
