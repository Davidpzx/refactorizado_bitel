<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Services\UserAgentResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequireOpenShift
{
    public function __construct(private readonly UserAgentResolver $userAgentResolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            return $next($request);
        }

        // R3: el rol agente se resuelve SIEMPRE por su propio agente_id — nunca por el
        // fallback de DNI/correo — y recibe un 403 claro (no el 422 genérico del
        // resolver) si su usuario no está vinculado a un agente.
        if ($user?->esRol(Usuario::ROL_AGENTE) && ! $user->agente_id) {
            return new JsonResponse([
                'message' => 'Tu usuario no está vinculado a un agente.',
            ], 403);
        }

        $agente = $this->userAgentResolver->resolveOrFail($user);
        // 'asistencias' es fundacional (create_core_tables.php, creación incondicional):
        // no hace falta Schema::hasTable en este middleware que corre en casi cada request.
        $turnoAbierto = DB::table('asistencias')
            ->where('agente_id', $agente->id)
            ->where('fecha', now()->toDateString())
            ->whereNotNull('hora_ingreso')
            ->whereNull('hora_salida')
            ->exists();

        if (! $turnoAbierto) {
            return new JsonResponse([
                'message' => 'Debes registrar tu entrada y mantener el turno abierto para realizar esta operación.',
                'code' => 'OPEN_SHIFT_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
