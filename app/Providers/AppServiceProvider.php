<?php

namespace App\Providers;

use App\Fiscal\Pac\Pac;
use App\Fiscal\Pac\SimulatedPac;
use App\Models\PersonalAccessToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // F-1: el conector del PAC se elige por configuración. Solo existe el simulado.
        $this->app->singleton(Pac::class, fn () => match (config('restia.fiscal.pac')) {
            'simulated' => new SimulatedPac,
            default => throw new RuntimeException('RESTIA_PAC no corresponde a ningún conector.'),
        });
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
