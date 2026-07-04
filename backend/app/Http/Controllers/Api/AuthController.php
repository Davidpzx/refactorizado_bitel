<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\UserAgentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly UserAgentResolver $userAgentResolver)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $usuario = Usuario::where('email', $request->email)->first();

        if (! $usuario || ! Hash::check($request->password, $usuario->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        if (! $usuario->activo) {
            return response()->json(['message' => 'Tu cuenta está desactivada. Contacta al administrador.'], 403);
        }

        // Revocar tokens anteriores del mismo dispositivo/nombre
        $usuario->tokens()->where('name', 'api')->delete();

        $token = $usuario->createToken('api')->plainTextToken;
        $agente = $this->userAgentResolver->resolve($usuario);

        return response()->json([
            'token'   => $token,
            'usuario' => [
                'id'             => $usuario->id,
                'nombre'         => $usuario->nombre,
                'email'          => $usuario->email,
                'rol'            => $usuario->rol,
                'tienda_id'      => $usuario->tienda_id,
                'agente_id'      => $agente?->id,
                'formato_ticket' => $usuario->formato_ticket ?? '80',
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $agente = $this->userAgentResolver->resolve($usuario);

        return response()->json([
            'id'             => $usuario->id,
            'nombre'         => $usuario->nombre,
            'email'          => $usuario->email,
            'rol'            => $usuario->rol,
            'tienda_id'      => $usuario->tienda_id,
            'agente_id'      => $agente?->id,
            'activo'         => $usuario->activo,
            'formato_ticket' => $usuario->formato_ticket ?? '80',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function verifyPin(Request $request): JsonResponse
    {
        $request->validate([
            'dni' => 'required|string',
            'pin' => 'required|string|size:4',
        ]);

        if (
            Schema::hasColumn('usuarios', 'dni')
            && Schema::hasColumn('usuarios', 'pin_seguridad')
        ) {
            $usuario = Usuario::whereRaw('TRIM(dni) = ?', [trim((string) $request->dni)])
                ->where('activo', 1)
                ->first();

            if ($usuario && $this->pinValido((string) $usuario->getAttribute('pin_seguridad'), $request->pin)) {
                if ($usuario->rol === 'admin') {
                    return response()->json([
                        'valid' => true,
                        'rol' => 'admin',
                        'nombre' => $usuario->nombre,
                    ]);
                }

                $agenteUsuario = $this->userAgentResolver->resolve($usuario);
                if ($agenteUsuario && $this->turnoAbierto($agenteUsuario->id)) {
                    return response()->json([
                        'valid' => true,
                        'rol' => $usuario->rol,
                        'agente_id' => $agenteUsuario->id,
                        'nombre' => $agenteUsuario->nombres,
                    ]);
                }
            }
        }

        $agente = \App\Models\Agente::where('dni', $request->dni)
            ->where('estado', 'ACTIVO')
            ->first();

        if (! $agente || ! $agente->verificarPin($request->pin)) {
            return response()->json(['valid' => false, 'error' => 'DNI o PIN incorrecto'], 422);
        }
        if (! $agente->es_gerencia && ! $this->turnoAbierto($agente->id)) {
            return response()->json([
                'valid' => false,
                'error' => 'El agente no tiene un turno abierto.',
                'code' => 'OPEN_SHIFT_REQUIRED',
            ], 403);
        }

        return response()->json([
            'valid'     => true,
            'rol'       => $agente->es_gerencia ? 'gerencia' : 'agente',
            'agente_id' => $agente->id,
            'nombre'    => $agente->nombre ?? $agente->nombres,
        ]);
    }

    private function turnoAbierto(int $agenteId): bool
    {
        return Schema::hasTable('asistencias')
            && DB::table('asistencias')
                ->where('agente_id', $agenteId)
                ->whereDate('fecha', now()->toDateString())
                ->whereNotNull('hora_ingreso')
                ->whereNull('hora_salida')
                ->exists();
    }

    private function pinValido(string $guardado, string $pin): bool
    {
        $guardado = trim($guardado);
        if ($guardado === '') {
            return false;
        }

        return Hash::isHashed($guardado)
            ? Hash::check($pin, $guardado)
            : hash_equals($guardado, $pin);
    }
}
