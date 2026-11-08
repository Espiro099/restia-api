<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Probado en la prueba técnica: el modelo de Sanctum sobre MongoDB (Resultados_Prueba_Tecnica.md, prueba 1).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
