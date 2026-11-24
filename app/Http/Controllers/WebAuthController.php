<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainConflict;
use App\Identity\GoogleSignIn;
use App\Identity\WebAuth;
use App\Models\User;
use App\Support\Api;
use App\Support\Audit;
use App\Support\Throttle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /auth/login (loginWeb). Antes, el panel pide GET /sanctum/csrf-cookie y manda X-XSRF-TOKEN: el
 * middleware de CSRF ya validó el token cuando se llega aquí.
 */
class WebAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        // Sin sesión es una petición que no viene del panel (otro origen o sin cookie): no hay CSRF que validar.
        if (! $request->hasSession()) {
            throw new DomainConflict('CSRF_TOKEN_MISMATCH');
        }
        $in = Api::body($request, [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:1', 'max:1024'],
        ]);
        $ip = (string) $request->ip();
        $email = User::normalizeEmail($in['email']);
        Throttle::hit('login-ip|'.$ip, config('restia.auth.limits.login_per_ip'));
        Throttle::hit('login|'.$email.'|'.$ip, config('restia.auth.limits.login_per_email_ip'), function () use ($ip) {
            Audit::record('auth.login_locked', 'login', null, changes: ['ip' => $ip]);
        });

        [$user, $session] = WebAuth::login($email, $in['password'], $ip);

        // Se regenera el ID de sesión para evitar la fijación de sesión, y también el token CSRF.
        $request->session()->regenerate();
        $request->session()->put(WebAuth::SESSION_KEY, $session->uuid);

        return Api::data(AccountController::meData($user, $session), noStore: true);
    }

    /** GET /auth/google (getGoogleLoginConfig): si está encendido y con qué ID de cliente (GOOGLE-1). */
    public function googleConfig(GoogleSignIn $google): JsonResponse
    {
        return Api::data(['enabled' => $google->enabled(), 'clientId' => $google->clientId()]);
    }

    /** POST /auth/google (loginWebGoogle): como /auth/login, con el ID token de Google Identity Services. */
    public function google(Request $request, GoogleSignIn $google): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new DomainConflict('CSRF_TOKEN_MISMATCH');
        }
        $in = Api::body($request, ['credential' => ['required', 'string', 'min:16', 'max:4096']]);
        $ip = (string) $request->ip();
        Throttle::hit('login-ip|'.$ip, config('restia.auth.limits.login_per_ip'));

        [$user, $session] = WebAuth::loginWithGoogle($google, $in['credential'], $ip);

        $request->session()->regenerate();
        $request->session()->put(WebAuth::SESSION_KEY, $session->uuid);

        return Api::data(AccountController::meData($user, $session), noStore: true);
    }
}
