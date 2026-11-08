<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Refresh token `<sessionUuid>.<aleatorio>.<HMAC>` (D-36, 5.3). La firma reconoce cualquier token
 * emitido para la sesión sin guardar su historial, e impide revocar una sesión ajena con solo su UUID.
 */
final class RefreshTokens
{
    public static function issue(string $sessionUuid): string
    {
        $body = $sessionUuid.'.'.bin2hex(random_bytes(32));

        return $body.'.'.self::sign($body);
    }

    /** Devuelve el UUID de la sesión si la firma es válida; null en cualquier otro caso. */
    public static function verify(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! Str::isUuid($parts[0])) {
            return null;
        }
        $body = $parts[0].'.'.$parts[1];

        return hash_equals(self::sign($body), $parts[2]) ? strtolower($parts[0]) : null;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::key());
    }

    private static function key(): string
    {
        $key = config('restia.auth.refresh_key');
        if (is_string($key) && $key !== '') {
            return $key;
        }

        // Derivada, para no reutilizar APP_KEY tal cual en otro propósito.
        return hash_hmac('sha256', 'restia-refresh-token', (string) config('app.key'));
    }
}
