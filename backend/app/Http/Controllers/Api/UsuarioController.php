<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuarios = Usuario::query()
            ->when($request->get('q'), fn($q, $s) => $q->where('nombre', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%"))
            ->when($request->get('rol'), fn($q, $rol) => $q->where('rol', $rol))
            ->orderBy('rol')
            ->orderBy('nombre')
            ->paginate($request->integer('per_page', 20));

        return response()->json($usuarios);
    }

    public function show(Usuario $usuario): JsonResponse
    {
        return response()->json($usuario);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'    => ['required', 'string', 'max:100'],
            'email'     => ['required', 'email', 'unique:usuarios,email'],
            'password'  => ['required', 'string', 'min:6'],
            'rol'       => ['required', Rule::in(['admin', 'tienda'])],
            'tienda_id' => ['nullable', 'string', 'max:20'],
            'activo'    => ['boolean'],
            'tiene_bcp' => ['boolean'],
        ]);

        $data['password'] = Hash::make($data['password']);
        $data['activo']   = $data['activo'] ?? true;

        $usuario = Usuario::create($data);

        return response()->json($usuario, 201);
    }

    public function update(Request $request, Usuario $usuario): JsonResponse
    {
        $data = $request->validate([
            'nombre'    => ['sometimes', 'string', 'max:100'],
            'email'     => ['sometimes', 'email', Rule::unique('usuarios', 'email')->ignore($usuario->id)],
            'password'  => ['nullable', 'string', 'min:6'],
            'rol'       => ['sometimes', Rule::in(['admin', 'tienda'])],
            'tienda_id' => ['nullable', 'string', 'max:20'],
            'activo'    => ['boolean'],
            'tiene_bcp' => ['boolean'],
        ]);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $usuario->update($data);

        return response()->json($usuario);
    }

    public function destroy(Usuario $usuario): JsonResponse
    {
        // No permitir eliminar al propio usuario autenticado
        if ($usuario->id === auth()->id()) {
            return response()->json(['error' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        $usuario->tokens()->delete();
        $usuario->delete();

        return response()->json(null, 204);
    }
}
