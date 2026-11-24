<?php

namespace App\Identity;

use App\Exceptions\DomainConflict;
use App\Models\AuthSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mongo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

/**
 * Login Web (D-36, sección 6): cookie de sesión HttpOnly y token CSRF. La sesión de Laravel solo guarda
 * el UUID de una auth_session WEB; cada petición comprueba que siga ACTIVE, en plazo y con actividad.
 */
final class WebAuth
{
    /** Clave en la sesión de Laravel con el UUID de la auth_session. */
    public const SESSION_KEY = 'restia_auth_session';

    /** Solo se registra actividad una vez por minuto, para no escribir en cada petición. */
    private const TOUCH_EVERY_SECONDS = 60;

    /** @return array{0: User, 1: AuthSession} */
    public static function login(string $email, string $password, string $ip): array
    {
        $user = User::query()->where('normalized_email', User::normalizeEmail($email))->first();
        // Sin usuario se compara igual contra un hash, para no revelar por el tiempo si el correo existe.
        $valid = Hash::check($password, $user?->password_hash ?? MobileAuth::dummyHash());
        if (! $valid || ! $user->isActive()) {
            // Ni el correo ni la contraseña van al log (CRED-24).
            Log::info('Login web rechazado', ['ip' => $ip]);
            throw new DomainConflict('AUTHENTICATION_INVALID');
        }

        return [$user, Mongo::transaction(fn () => self::openSession($user, $ip, 'PASSWORD'))];
    }

    /**
     * GOOGLE-1: la identidad la prueba un token de Google en lugar de la contraseña.
     *
     * @return array{0: User, 1: AuthSession}
     */
    public static function loginWithGoogle(GoogleSignIn $google, string $idToken, string $ip): array
    {
        $user = $google->user($idToken, $ip);

        return [$user, Mongo::transaction(fn () => self::openSession($user, $ip, 'GOOGLE'))];
    }

    private static function openSession(User $user, string $ip, string $method): AuthSession
    {
        $now = now();
        $userId = new ObjectId($user->getKey());

        // A-4: hasta 3 sesiones Web por usuario; la más antigua se cierra.
        $limit = (int) config('restia.auth.web_sessions_per_user');
        $others = AuthSession::query()->where('status', AuthSession::ACTIVE)->where('client_type', AuthSession::WEB)
            ->where('user_id', $userId)->orderBy('created_at')->get();
        foreach ($others->take(max(0, $others->count() - ($limit - 1))) as $old) {
            Sessions::revoke($old, 'SESSION_LIMIT', null);
        }

        $absolute = $now->copy()->addSeconds(config('restia.auth.web_max_seconds'));
        $session = AuthSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $userId,
            'client_type' => AuthSession::WEB,
            'device_id' => null,
            'status' => AuthSession::ACTIVE,
            'absolute_expires_at' => $absolute,
            'last_activity_at' => $now,
            'ip_created' => $ip,
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
            'purge_at' => Sessions::purgeAt($absolute),
        ]);
        Audit::record('auth.login', 'auth_session', $session->getKey(), null, $userId, changes: ['clientType' => AuthSession::WEB, 'method' => $method]);

        return $session;
    }

    /** La auth_session WEB a la que apunta la cookie, si sigue usable. Registra la actividad. */
    public static function resolve(?string $uuid): ?AuthSession
    {
        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            return null;
        }
        $session = AuthSession::query()->where('uuid', $uuid)->where('client_type', AuthSession::WEB)->first();
        if (! $session?->isUsable()) {
            return null;
        }
        $now = now();
        if ($session->last_activity_at->lt($now->copy()->subSeconds(self::TOUCH_EVERY_SECONDS))) {
            AuthSession::query()->where('_id', new ObjectId($session->getKey()))->update(['last_activity_at' => $now]);
            $session->last_activity_at = $now;
        }

        return $session;
    }
}
