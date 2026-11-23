<?php

namespace App\Http\Middleware;

use App\Identity\WebAuth;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer de Sanctum o cookie de sesión Web, más lo que Sanctum no revisa (D-36): la sesión sigue ACTIVE y en plazo, el usuario
 * está activo y, en Android, el dispositivo también. Todo se consulta en cada petición, así que
 * revocar tiene efecto inmediato. Los permisos nunca viajan en el token.
 */
class AuthenticateSession
{
    public const SESSION = 'auth_session';

    public const DEVICE = 'auth_device';

    public function handle(Request $request, Closure $next): Response
    {
        // Web: sin Bearer, la cookie de sesión (solo existe si la petición viene del panel, con CSRF).
        if ($request->bearerToken() === null && $request->hasSession() && $request->session()->has(WebAuth::SESSION_KEY)) {
            return $this->web($request, $next);
        }

        $user = Auth::guard('sanctum')->user();
        $token = $user?->currentAccessToken();
        $sessionId = $token?->auth_session_id;
        $session = $sessionId ? AuthSession::query()->find($sessionId instanceof ObjectId ? (string) $sessionId : $sessionId) : null;

        if (! $user instanceof User || ! $user->isActive() || ! $session?->isUsable() || (string) $session->user_id !== (string) $user->getKey()) {
            if (app()->environment('testing')) {
                $raw = (string) $request->bearerToken();
                $tid = explode('|', $raw)[0] ?? '';
                $tok = PersonalAccessToken::query()->find($tid);
                fwrite(STDERR, 'DBG401 '.json_encode([
                    'now' => now()->toIso8601String(), 'user' => $user?->getKey(), 'tokenFound' => (bool) $tok,
                    'tokenSession' => $tok ? (string) $tok->auth_session_id : null,
                    'session' => $session?->only(['status', 'revoked_reason', 'revoke_reason', 'reason', 'absolute_expires_at', 'refresh_expires_at', 'created_at', 'client_type']),
                    'sessionsOfUser' => $tok ? AuthSession::query()->where('user_id', $tok->tokenable_id)->get()->map->only(['status', 'revoked_reason', 'revoke_reason', 'reason', 'created_at'])->all() : null,
                ]).PHP_EOL);
            }
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

    private function web(Request $request, Closure $next): Response
    {
        $session = WebAuth::resolve($request->session()->get(WebAuth::SESSION_KEY));
        $user = $session ? User::query()->find($session->user_id) : null;
        if (! $user instanceof User || ! $user->isActive()) {
            $request->session()->forget(WebAuth::SESSION_KEY);
            throw new AuthenticationException;
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set(self::SESSION, $session);
        $request->attributes->set(self::DEVICE, null);

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
