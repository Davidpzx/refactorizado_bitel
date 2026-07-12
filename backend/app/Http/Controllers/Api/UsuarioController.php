<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Support\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuarios = Usuario::query()
            ->with('agente:id,nombres,dni')
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
            'nombre'    => ['required', 'string', 'max:100', 'regex:/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s\'-]+$/'],
            'email'     => ['required', 'email', 'max:50', 'unique:usuarios,email'],
            'password'  => ['required', 'string', 'min:6'],
            // Plan 16: acepta los 4 roles canónicos + los 2 alias legacy vivos (admin→administrador, tienda→jefe_tienda).
            'rol'       => ['required', Rule::in(['admin', 'tienda', 'administrador', 'gerente', 'jefe_tienda', 'agente'])],
            'tienda_id' => ['nullable', 'string', 'max:20', 'required_if:rol,tienda', 'required_if:rol,jefe_tienda', Rule::exists('tiendas', 'codigo')],
            'agente_id' => ['nullable', 'required_if:rol,tienda', 'required_if:rol,agente', 'integer', 'exists:agentes,id'],
            'activo'    => ['boolean'],
            'tiene_bcp' => ['boolean'],
            'formato_ticket' => ['nullable', Rule::in(['58', '80'])],
        ], [
            'nombre.regex'    => 'El nombre no debe contener números ni símbolos.',
            'tienda_id.exists' => 'Selecciona una tienda válida de la lista.',
        ]);

        // Plan 16: el gerente opera el negocio pero no crea/promueve administradores
        // (solo el propio administrador puede hacerlo).
        if (Usuario::normalizarRol($data['rol']) === Usuario::ROL_ADMINISTRADOR
            && ! Permisos::puede($request->user(), 'crear_administradores')) {
            return response()->json([
                'error' => 'No tienes permisos para crear usuarios administradores.',
            ], 403);
        }

        $data['password'] = Hash::make($data['password']);
        $data['activo']   = $data['activo'] ?? true;

        $usuario = Usuario::create($data);

        return response()->json($usuario, 201);
    }

    public function update(Request $request, Usuario $usuario): JsonResponse
    {
        $data = $request->validate([
            'nombre'    => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s\'-]+$/'],
            'email'     => ['sometimes', 'email', 'max:50', Rule::unique('usuarios', 'email')->ignore($usuario->id)],
            'password'  => ['nullable', 'string', 'min:6'],
            'rol'       => ['sometimes', Rule::in(['admin', 'tienda', 'administrador', 'gerente', 'jefe_tienda', 'agente'])],
            'tienda_id' => ['nullable', 'string', 'max:20', 'required_if:rol,tienda', 'required_if:rol,jefe_tienda', Rule::exists('tiendas', 'codigo')],
            'agente_id' => ['nullable', 'required_if:rol,agente', 'integer', 'exists:agentes,id'],
            'activo'    => ['boolean'],
            'tiene_bcp' => ['boolean'],
            'formato_ticket' => ['nullable', Rule::in(['58', '80'])],
        ], [
            'nombre.regex'     => 'El nombre no debe contener números ni símbolos.',
            'tienda_id.exists' => 'Selecciona una tienda válida de la lista.',
        ]);

        // Plan 16: el gerente opera el negocio pero no crea/promueve administradores.
        if (array_key_exists('rol', $data)
            && Usuario::normalizarRol($data['rol']) === Usuario::ROL_ADMINISTRADOR
            && ! Permisos::puede($request->user(), 'crear_administradores')) {
            return response()->json([
                'error' => 'No tienes permisos para promover usuarios a administrador.',
            ], 403);
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $seDesactiva = array_key_exists('activo', $data) && !$data['activo'] && $usuario->activo;

        $usuario->update($data);

        // SEC-16: al desactivar un usuario, sus tokens Sanctum viejos seguían vivos
        // hasta que expiraran solos (SEC-07). Si se sospecha compromiso o se da de
        // baja a un empleado, la sesión debe morir en el acto, no en 14 días.
        if ($seDesactiva) {
            $usuario->tokens()->delete();
        }

        return response()->json($usuario);
    }

    /** SEC-16: revocación manual de todas las sesiones de un usuario (compromiso sospechado). */
    public function revocarTokens(Usuario $usuario): JsonResponse
    {
        $usuario->tokens()->delete();

        return response()->json(['ok' => true, 'mensaje' => 'Todas las sesiones del usuario fueron cerradas.']);
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
