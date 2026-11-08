<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Models\Device;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer de Sanctum, más lo que Sanctum no revisa (D-36): la sesión sigue ACTIVE y en plazo, el usuario
 * está activo y, en Android, el dispositivo también. Todo se consulta en cada petición, así que
 * revocar tiene efecto inmediato. Los permisos nunca viajan en el token.
 */
class AuthenticateSession
{
    public const SESSION = 'auth_session';

    public const DEVICE = 'auth_device';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();
        $token = $user?->currentAccessToken();
        $sessionId = $token?->auth_session_id;
        $session = $sessionId ? AuthSession::query()->find($sessionId instanceof ObjectId ? (string) $sessionId : $sessionId) : null;

        if (! $user instanceof User || ! $user->isActive() || ! $session?->isUsable() || (string) $session->user_id !== (string) $user->getKey()) {
            throw new AuthenticationException;
        }

        $device = null;
        if ($session->client_type === AuthSession::ANDROID) {
            $device = Device::query()->find($session->device_id);
            if (! $device?->isActive()) {
                throw new AuthenticationException;
            }
        }

        Auth::setUser($user);
        $request->attributes->set(self::SESSION, $session);
        $request->attributes->set(self::DEVICE, $device);

        return $next($request);
    }

    public static function session(Request $request): AuthSession
    {
        return $request->attributes->get(self::SESSION);
    }

    public static function device(Request $request): ?Device
    {
        return $request->attributes->get(self::DEVICE);
    }
}
