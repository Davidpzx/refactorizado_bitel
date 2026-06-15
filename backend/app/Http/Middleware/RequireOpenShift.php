<?php

namespace App\Http\Middleware;

use App\Services\UserAgentResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequireOpenShift
{
    public function __construct(private readonly UserAgentResolver $userAgentResolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->rol === 'admin') {
            return $next($request);
        }

        $agente = $this->userAgentResolver->resolveOrFail($user);
        $turnoAbierto = Schema::hasTable('asistencias')
            && DB::table('asistencias')
                ->where('agente_id', $agente->id)
                ->whereDate('fecha', now()->toDateString())
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
