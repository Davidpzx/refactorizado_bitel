<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
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
        // para no romper la suite ni el arranque de desarrollo. Se excluye la consola:
        // en build-time (composer install → package:discover) no existen las env vars.
        if ($this->app->environment('production')
            && !$this->app->runningInConsole()
            && blank(config('services.integrador.api_key'))) {
            throw new \RuntimeException(
                'INTEGRADOR_API_KEY no está configurada. Define la variable de entorno '
                .'antes de arrancar en producción (autentica a los agentes extractores M2M).'
            );
        }

        // SEC-10: los exports (Excel/CSV de tablas completas) no llevaban límite propio.
        // 10/min por usuario autenticado (fallback IP si por algún motivo no hay usuario).
        RateLimiter::for('exports', fn ($request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));

        // SEC-15: verify-pin solo tenía throttle por IP (20/min = 28.8k intentos/día),
        // suficiente para tumbar un PIN de 4-6 dígitos con pocas IPs. Se compone por
        // IP+dni objetivo: 5 intentos/min contra el MISMO dni, sin penalizar a otros
        // usuarios que comparten IP (kiosco/terminal de tienda).
        RateLimiter::for('verify-pin', fn ($request) => Limit::perMinute(5)
            ->by($request->ip().'|'.(string) $request->input('dni', '')));
    }
}
