<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'open.shift' => \App\Http\Middleware\RequireOpenShift::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Renderizar todas las excepciones de rutas /api/* como JSON.
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // En producción: nunca exponer mensaje interno de QueryException al cliente.
        // El error se registra en el log (LOG_LEVEL=error en .env) pero la respuesta
        // solo devuelve un mensaje genérico.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, Request $request) {
            if (app()->isProduction() && ($request->is('api/*') || $request->expectsJson())) {
                \Illuminate\Support\Facades\Log::error('QueryException', [
                    'message' => $e->getMessage(),
                    'sql'     => $e->getSql(),
                    'url'     => $request->fullUrl(),
                ]);
                return response()->json(
                    [
                        'error' => 'Error interno del servidor. Contacte al administrador.',
                    ],
                    500
                );
            }
        });
    })->create();
