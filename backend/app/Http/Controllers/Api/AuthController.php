<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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

        return response()->json([
            'token'   => $token,
            'usuario' => [
                'id'        => $usuario->id,
                'nombre'    => $usuario->nombre,
                'email'     => $usuario->email,
                'rol'       => $usuario->rol,
                'tienda_id' => $usuario->tienda_id,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'id'        => $usuario->id,
            'nombre'    => $usuario->nombre,
            'email'     => $usuario->email,
            'rol'       => $usuario->rol,
            'tienda_id' => $usuario->tienda_id,
            'activo'    => $usuario->activo,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }
}
