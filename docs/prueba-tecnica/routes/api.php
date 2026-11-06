<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::middleware('auth:sanctum')->get('/me', fn (Request $r) => ['id' => (string) $r->user()->getKey()]);
Route::middleware('auth:sanctum')->get('/ping-auth', fn () => ['ok' => true]);

Route::post('/login', function (Request $r) {
    if (! Auth::guard('web')->attempt($r->only('email', 'password'))) {
        throw ValidationException::withMessages(['email' => 'Credenciales incorrectas']);
    }
    $r->session()->regenerate();

    return ['ok' => true];
});
Route::middleware('auth:sanctum')->post('/logout', function (Request $r) {
    Auth::guard('web')->logout();
    $r->session()->invalidate();
    $r->session()->regenerateToken();

    return response()->noContent();
});

// Prueba 9: lo que no se debe hacer, y lo que se debe hacer.
Route::get('/filter-unsafe', fn (Request $r) => ['n' => User::where('status', $r->input('status'))->count()]);
$safe = function (Request $r) {
    $v = $r->validate(['status' => ['sometimes', 'string', 'max:32']]);

    return ['n' => User::where('status', $v['status'] ?? 'ACTIVE')->count()];
};
Route::get('/filter-safe', $safe);
Route::post('/filter-safe', $safe);
