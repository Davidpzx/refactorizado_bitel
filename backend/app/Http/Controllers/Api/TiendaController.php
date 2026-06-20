<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tienda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class TiendaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!Schema::hasTable('tiendas')) {
            return response()->json([
                'warning' => 'Tabla tiendas no existe.',
                'data'    => [],
                'total'   => 0,
            ]);
        }

        $tiendas = Tienda::query()
            ->when($request->get('q'), fn($q, $s) => $q->where('nombre', 'like', "%{$s}%")
                ->orWhere('codigo', 'like', "%{$s}%"))
            ->orderBy('nombre')
            ->paginate($request->integer('per_page', 50));

        return response()->json($tiendas);
    }

    public function show(Tienda $tienda): JsonResponse
    {
        return response()->json($tienda);
    }

    public function store(Request $request): JsonResponse
    {
        // TEMPORAL: 'direccion' y 'telefono' no existen aún como columnas en la tabla real.
        // Se omiten de la validación (y por lo tanto del insert) hasta correr la migración
        // 2026_06_20_000001_add_direccion_telefono_to_tiendas. Reactivar ahí abajo cuando exista.
        $data = $request->validate([
            'codigo'    => ['required', 'string', 'max:20', 'unique:tiendas,codigo'],
            'nombre'    => ['required', 'string', 'max:100'],
            // 'direccion' => ['nullable', 'string', 'max:200'],
            // 'telefono'  => ['nullable', 'string', 'max:20'],
            'activo'    => ['boolean'],
        ]);

        $data['activo'] = $data['activo'] ?? true;

        $tienda = Tienda::create($data);

        return response()->json($tienda, 201);
    }

    public function update(Request $request, Tienda $tienda): JsonResponse
    {
        // TEMPORAL: ver nota en store() sobre 'direccion'/'telefono'.
        $data = $request->validate([
            'codigo'    => ['sometimes', 'string', 'max:20', Rule::unique('tiendas', 'codigo')->ignore($tienda->id)],
            'nombre'    => ['sometimes', 'string', 'max:100'],
            // 'direccion' => ['nullable', 'string', 'max:200'],
            // 'telefono'  => ['nullable', 'string', 'max:20'],
            'activo'    => ['boolean'],
            'latitud'   => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitud'  => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ]);

        $tienda->update($data);

        return response()->json($tienda);
    }

    public function destroy(Tienda $tienda): JsonResponse
    {
        $tienda->delete();
        return response()->json(null, 204);
    }
}
