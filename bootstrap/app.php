<?php

use App\Http\Controllers\PasswordPageController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateService;
use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\AuthorizeAnyBranch;
use App\Http\Middleware\AuthorizeBranch;
use App\Http\Middleware\PublicQrToken;
use App\Support\ApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Página de contraseña (U-2): sin el grupo web, que agrega sesión y cookies que no necesita.
        then: function () {
            Route::get('contrasena', PasswordPageController::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Primero que todo: cada respuesta, incluso de error, lleva X-Request-ID.
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias([
            'auth.session' => AuthenticateSession::class,
            'branch' => AuthorizeBranch::class,
            'anybranch' => AuthorizeAnyBranch::class,
            'qr' => PublicQrToken::class,
            'auth.service' => AuthenticateService::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiErrors::register($exceptions);
    })->create();
