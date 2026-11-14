<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Página mínima para definir o recuperar la contraseña (U-2), mientras no exista el panel Web. El token
 * llega en el fragmento de la URL y nunca pasa por el servidor; la página solo llama a /auth/password.
 */
class PasswordPageController extends Controller
{
    public function __invoke(): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $html = str_replace('{{NONCE}}', $nonce, file_get_contents(resource_path('pages/contrasena.html')));

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Sin recursos externos: solo el script y el estilo de esta respuesta, y llamadas a la misma API.
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; "
                ."img-src 'self'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cache-Control' => 'no-store',
        ]);
    }
}
