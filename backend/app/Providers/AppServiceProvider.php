<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        // Falla ruidosamente en el arranque (no en cada request) si el secreto M2M del
        // integrador no está configurado en producción. En local/testing se permite vacío
        // para no romper la suite ni el arranque de desarrollo.
        if ($this->app->environment('production')
            && blank(config('services.integrador.api_key'))) {
            throw new \RuntimeException(
                'INTEGRADOR_API_KEY no está configurada. Define la variable de entorno '
                .'antes de arrancar en producción (autentica a los agentes extractores M2M).'
            );
        }
    }
}
