<?php

namespace App\Identity;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verificación local del ID token de Google (GOOGLE-2): firma RS256 contra las llaves públicas de Google,
 * emisor, audiencia (el ID de cliente Web; la app también lo usa como serverClientId) y vigencia. Las
 * llaves se guardan en caché el tiempo que indica Google y se vuelven a pedir si llega una que no se conoce.
 */
final class GoogleIdTokenVerifier implements GoogleTokens
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CACHE_KEY = 'google-oauth-certs';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(private readonly ?string $clientId) {}

    public function clientId(): ?string
    {
        return (string) $this->clientId !== '' ? $this->clientId : null;
    }

    public function verify(string $idToken): ?array
    {
        if (! $this->clientId()) {
            return null;
        }
        foreach ([false, true] as $fresh) {
            $keys = $this->keys($fresh);
            if (! $keys) {
                return null;
            }
            try {
                JWT::$leeway = 60;
                $claims = (array) JWT::decode($idToken, JWK::parseKeySet($keys, 'RS256'));
            } catch (\UnexpectedValueException $e) {
                // Una llave nueva de Google que aún no está en caché: se piden otra vez, una sola vez.
                if (! $fresh && str_contains($e->getMessage(), '"kid"')) {
                    continue;
                }
                Log::info('Token de Google rechazado', ['reason' => class_basename($e)]);

                return null;
            } catch (Throwable $e) {
                Log::info('Token de Google rechazado', ['reason' => class_basename($e)]);

                return null;
            }

            return $this->accepts($claims) ? $claims : null;
        }

        return null;
    }

    /** @param array<string, mixed> $claims */
    private function accepts(array $claims): bool
    {
        $aud = $claims['aud'] ?? null;

        return in_array($claims['iss'] ?? null, self::ISSUERS, true)
            && (is_array($aud) ? in_array($this->clientId, $aud, true) : $aud === $this->clientId)
            && is_string($claims['sub'] ?? null) && $claims['sub'] !== ''
            && is_string($claims['email'] ?? null)
            && ($claims['email_verified'] ?? false) === true;
    }

    /** @return array<string, mixed>|null */
    private function keys(bool $fresh): ?array
    {
        if (! $fresh && is_array($cached = Cache::get(self::CACHE_KEY))) {
            return $cached;
        }
        try {
            $response = Http::timeout(5)->acceptJson()->get(self::CERTS_URL);
        } catch (Throwable) {
            Log::warning('No se pudieron obtener las llaves de Google');

            return null;
        }
        $keys = $response->json();
        if (! $response->successful() || ! is_array($keys['keys'] ?? null)) {
            Log::warning('Respuesta inválida de las llaves de Google', ['status' => $response->status()]);

            return null;
        }
        preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $m);
        Cache::put(self::CACHE_KEY, $keys, min(86400, max(300, (int) ($m[1] ?? 3600))));

        return $keys;
    }
}
