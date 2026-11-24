<?php

namespace App\Providers;

use App\Fiscal\Pac\Pac;
use App\Fiscal\Pac\SimulatedPac;
use App\Fiscal\Pac\SwSapienPac;
use App\Mail\ResendTransport;
use App\Models\PersonalAccessToken;
use App\PublicQr\AssistantModel;
use App\PublicQr\ClaudeAssistantModel;
use App\PublicSite\AssistantModel as SiteAssistantModel;
use App\PublicSite\ClaudeAssistantModel as SiteClaudeAssistantModel;
use Illuminate\Support\Facades\Mail;
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
        // F-1: el conector del PAC se elige por configuración: el simulado o SW Sapien.
        $this->app->singleton(Pac::class, fn () => match (config('restia.fiscal.pac')) {
            'simulated' => new SimulatedPac,
            'sw' => new SwSapienPac(config('services.sw')),
            default => throw new RuntimeException('RESTIA_PAC no corresponde a ningún conector.'),
        });
        // QR-14 y SITE-3: los asistentes del menú y del sitio con Claude; sin ANTHROPIC_API_KEY no se ofrecen.
        $this->app->singleton(AssistantModel::class, fn () => new ClaudeAssistantModel(config('restia.assistant.key'), config('restia.assistant.model')));
        $this->app->singleton(SiteAssistantModel::class, fn () => new SiteClaudeAssistantModel(config('restia.assistant.key'), config('restia.assistant.model')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Probado en la prueba técnica: el modelo de Sanctum sobre MongoDB (Resultados_Prueba_Tecnica.md, prueba 1).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Reemplaza el transporte "resend" de Laravel, que pide la librería resend/resend-php, por uno sobre Http.
        Mail::extend('resend', fn () => new ResendTransport(config('services.resend.key')));
    }
}
