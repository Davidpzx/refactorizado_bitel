<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate de rol para rutas (`->middleware('role:admin,tienda')`).
 *
 * Modelo de 4 roles (plan 16), retrocompatible:
 *  - Tanto el rol del usuario como los roles requeridos se normalizan a su forma
 *    canónica vía {@see Usuario::normalizarRol()}, así que los alias legacy funcionan
 *    en ambos lados: 'admin' ≡ 'administrador', 'tienda'/'vendedor' ≡ 'jefe_tienda'.
 *    Los ~40 tests y factories que crean usuarios con rol 'admin'/'tienda'/'vendedor'
 *    siguen pasando SIN tocarlos, y una ruta declarada como 'role:admin,tienda' admite
 *    indistintamente los valores viejos y los nuevos.
 *  - Jerarquía simple: el administrador supera cualquier check de rol (pasa siempre),
 *    aunque no esté listado explícitamente en la ruta.
 *
 * El scoping fino (su tienda / solo sus filas) NO se resuelve aquí — es de los tickets
 * R2-R4; este middleware sólo decide pertenencia al conjunto de roles permitidos.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $rolUsuario = Usuario::normalizarRol((string) $request->user()?->rol);

        // Jerarquía: el administrador pasa cualquier verificación de rol.
        if ($rolUsuario === Usuario::ROL_ADMINISTRADOR) {
            return $next($request);
        }

        $permitidos = array_map([Usuario::class, 'normalizarRol'], $roles);

        abort_unless(
            in_array($rolUsuario, $permitidos, true),
            403,
            'No tienes permisos para realizar esta acción.'
        );

        return $next($request);
    }
}
