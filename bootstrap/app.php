<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateService;
use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\AuthorizeAnyBranch;
use App\Http\Middleware\AuthorizeBranch;
use App\Support\ApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Primero que todo: cada respuesta, incluso de error, lleva X-Request-ID.
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias([
            'auth.session' => AuthenticateSession::class,
            'branch' => AuthorizeBranch::class,
            'anybranch' => AuthorizeAnyBranch::class,
            'auth.service' => AuthenticateService::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiErrors::register($exceptions);
    })->create();
