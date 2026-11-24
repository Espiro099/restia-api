<?php

namespace App\Identity;

use App\Exceptions\DomainConflict;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Inicio de sesión con Google para el personal (Decisiones de identidad, GOOGLE-1 a GOOGLE-4). No crea
 * cuentas: solo entra quien ya está dado de alta y ACTIVE, con el mismo correo verificado por Google.
 * La primera vez se guarda el `sub` de Google en el usuario; desde entonces solo esa cuenta de Google
 * entra, aunque después alguien cree otra con el mismo correo.
 */
final class GoogleSignIn
{
    public function __construct(private readonly GoogleTokens $tokens) {}

    public function enabled(): bool
    {
        return $this->tokens->clientId() !== null;
    }

    public function clientId(): ?string
    {
        return $this->tokens->clientId();
    }

    /** El usuario que prueba el token. Cualquier rechazo responde igual que una contraseña mala. */
    public function user(string $idToken, string $ip): User
    {
        if (! $this->enabled()) {
            throw new NotFoundHttpException;
        }
        $claims = $this->tokens->verify($idToken);
        if (! $claims) {
            throw $this->reject('token', $ip);
        }
        $sub = (string) $claims['sub'];
        $user = User::query()->where('google_sub', $sub)->first()
            ?? User::query()->where('normalized_email', User::normalizeEmail((string) $claims['email']))->first();
        if (! $user || ! $user->isActive()) {
            throw $this->reject('usuario', $ip);
        }
        if ($user->google_sub && $user->google_sub !== $sub) {
            throw $this->reject('otra cuenta de Google', $ip);
        }
        if (! $user->google_sub) {
            User::query()->where('_id', new ObjectId($user->getKey()))->update(['google_sub' => $sub]);
            $user->google_sub = $sub;
            Audit::record('user.google_linked', 'user', $user->getKey(), null, new ObjectId($user->getKey()));
        }

        return $user;
    }

    private function reject(string $reason, string $ip): DomainConflict
    {
        // Ni el correo ni el token van al log (CRED-24).
        Log::info('Login con Google rechazado', ['reason' => $reason, 'ip' => $ip]);

        return new DomainConflict('AUTHENTICATION_INVALID');
    }
}
