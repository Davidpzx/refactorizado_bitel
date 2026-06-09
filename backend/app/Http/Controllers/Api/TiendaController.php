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
        $data = $request->validate([
            'codigo'    => ['required', 'string', 'max:20', 'unique:tiendas,codigo'],
            'nombre'    => ['required', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono'  => ['nullable', 'string', 'max:20'],
            'activo'    => ['boolean'],
        ]);

        $data['activo'] = $data['activo'] ?? true;

        $tienda = Tienda::create($data);

        return response()->json($tienda, 201);
    }

    public function update(Request $request, Tienda $tienda): JsonResponse
    {
        $data = $request->validate([
            'codigo'    => ['sometimes', 'string', 'max:20', Rule::unique('tiendas', 'codigo')->ignore($tienda->id)],
            'nombre'    => ['sometimes', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono'  => ['nullable', 'string', 'max:20'],
            'activo'    => ['boolean'],
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
