<?php

namespace App\Identity;

/**
 * Verifica el ID token que Google le da al panel (Google Identity Services) o a la app (Credential Manager).
 * Una interfaz para que las pruebas usen una falsa y nunca llamen a Google.
 */
interface GoogleTokens
{
    /** ID de cliente OAuth Web; null si el inicio de sesión con Google está apagado. */
    public function clientId(): ?string;

    /**
     * Las afirmaciones del token si la firma, el emisor, la audiencia y la vigencia son válidos; null si no.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $idToken): ?array;
}
