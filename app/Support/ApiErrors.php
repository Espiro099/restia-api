<?php

namespace App\Support;

use App\Exceptions\DomainConflict;
use App\Exceptions\ServiceBusy;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Convierte toda excepción en el ErrorResponse del contrato: {error: {code, message, details?, requestId}}.
 * Los códigos, estados y mensajes salen de config/errors.php, generado desde el catálogo.
 */
final class ApiErrors
{
    /** Segundos sugeridos al agotar reintentos de una transacción (SERVICE_BUSY). */
    public const BUSY_RETRY_AFTER = 1;

    public static function register(Exceptions $exceptions): void
    {
        // Ningún error filtra trazas ni HTML: la API siempre responde JSON.
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->dontReport([DomainConflict::class]);
        $exceptions->render(fn (Throwable $e, Request $request) => self::render($e, $request));
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof DomainConflict => self::response($e->errorCode, $request, $e->details),
            $e instanceof ServiceBusy => self::response('SERVICE_BUSY', $request, headers: ['Retry-After' => (string) self::BUSY_RETRY_AFTER]),
            $e instanceof ValidationException => self::response('VALIDATION_FAILED', $request, ['fields' => $e->errors()]),
            $e instanceof AuthenticationException => self::response('AUTHENTICATION_REQUIRED', $request),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => self::response('FORBIDDEN', $request),
            // Un ID de otra sucursal y uno inexistente responden igual (aislamiento entre sucursales).
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => self::response('RESOURCE_NOT_FOUND', $request),
            $e instanceof ThrottleRequestsException => self::response('TOO_MANY_ATTEMPTS', $request, headers: array_intersect_key($e->getHeaders(), ['Retry-After' => 1])),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => self::generic($e->getStatusCode(), $request, $e->getHeaders()),
            default => self::response('INTERNAL_ERROR', $request),
        };
    }

    /** @param array<string, mixed>|null $details */
    public static function response(string $code, Request $request, ?array $details = null, array $headers = []): JsonResponse
    {
        $known = config('errors.'.$code);
        if ($known === null) {
            // Un código fuera del catálogo es un error de programación: no se inventa uno nuevo hacia el cliente.
            report(new \LogicException("Código de error fuera del catálogo: $code"));
            $code = 'INTERNAL_ERROR';
            $known = config('errors.INTERNAL_ERROR');
            $details = null;
        }
        $error = ['code' => $code, 'message' => $known['message']];
        if ($details) {
            $error['details'] = $details;
        }
        $error['requestId'] = AssignRequestId::current($request);

        $response = new JsonResponse(['error' => $error], $known['status'], $headers);
        $response->headers->set(AssignRequestId::HEADER, $error['requestId']);

        return $response;
    }

    /**
     * Estados que el contrato no nombra (405, 415...). Mismo formato y sin detalle interno;
     * el código `HTTP_<estado>` no está en el catálogo, porque ninguna operación válida lo produce.
     */
    private static function generic(int $status, Request $request, array $headers): JsonResponse
    {
        $id = AssignRequestId::current($request);
        $body = ['error' => ['code' => 'HTTP_'.$status, 'message' => 'La petición no se puede procesar.', 'requestId' => $id]];
        $headers = array_intersect_key($headers, ['Allow' => 1, 'Retry-After' => 1]) + [AssignRequestId::HEADER => $id];

        return new JsonResponse($body, $status, $headers);
    }
}
