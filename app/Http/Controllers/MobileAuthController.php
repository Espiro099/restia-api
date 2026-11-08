<?php

namespace App\Http\Controllers;

use App\Identity\MobileAuth;
use App\Models\User;
use App\Support\Api;
use App\Support\Audit;
use App\Support\RefreshTokens;
use App\Support\Throttle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /auth/mobile/enroll, /auth/mobile/login y /auth/mobile/refresh. Sin Idempotency-Key (D-36, 8). */
class MobileAuthController extends Controller
{
    public function enroll(Request $request): JsonResponse
    {
        Throttle::hit('enroll|'.$request->ip(), config('restia.auth.limits.enroll_per_ip'));
        $in = Api::body($request, [
            'code' => ['required', 'string', 'min:8', 'max:32'],
            'deviceUuid' => ['required', 'string', 'uuid'],
            'displayName' => ['required', 'string', 'min:1', 'max:80'],
        ]);
        $result = MobileAuth::enroll($in['code'], $in['deviceUuid'], $in['displayName']);

        return Api::data([
            'device' => DeviceController::present($result['device']),
            'deviceCredential' => $result['credential'],
        ], 201, noStore: true);
    }

    public function login(Request $request): JsonResponse
    {
        $in = Api::body($request, [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:1', 'max:1024'],
            'deviceUuid' => ['required', 'string', 'uuid'],
            'deviceCredential' => ['required', 'string', 'min:32', 'max:256'],
        ]);
        $ip = (string) $request->ip();
        $email = User::normalizeEmail($in['email']);
        Throttle::hit('login-ip|'.$ip, config('restia.auth.limits.login_per_ip'));
        Throttle::hit('login|'.$email.'|'.$ip, config('restia.auth.limits.login_per_email_ip'), function () use ($ip) {
            // Una sola vez por bloqueo; los intentos fallidos sueltos van solo al log (D-36, 7).
            Audit::record('auth.login_locked', 'login', null, changes: ['ip' => $ip]);
        });

        return Api::data(MobileAuth::login($email, $in['password'], $in['deviceUuid'], $in['deviceCredential'], $ip), 201, noStore: true);
    }

    public function refresh(Request $request): JsonResponse
    {
        $in = Api::body($request, [
            'deviceUuid' => ['required', 'string', 'uuid'],
            'refreshToken' => ['required', 'string', 'min:32', 'max:512'],
        ]);
        // Por sesión solo si la firma es válida; si no, por IP, para no dejar que un tercero agote el cupo ajeno.
        $session = RefreshTokens::verify($in['refreshToken']);
        Throttle::hit($session ? 'refresh|'.$session : 'refresh-ip|'.$request->ip(), config('restia.auth.limits.refresh_per_session'));

        return Api::data(MobileAuth::refresh($in['deviceUuid'], $in['refreshToken']), noStore: true);
    }
}
