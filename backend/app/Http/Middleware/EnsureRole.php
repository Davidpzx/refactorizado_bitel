<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $rol = (string) $request->user()?->rol;
        $roles = array_map(
            static fn (string $role) => $role === 'tienda' ? ['tienda', 'vendedor'] : [$role],
            $roles
        );
        $permitidos = array_unique(array_merge(...$roles));

        abort_unless(in_array($rol, $permitidos, true), 403, 'No tienes permisos para realizar esta acción.');

        return $next($request);
    }
}
