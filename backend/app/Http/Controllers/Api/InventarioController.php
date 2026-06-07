<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventarioTienda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = InventarioTienda::query()
            ->when($request->q,      fn($q, $t) => $q->buscar($t))
            ->when($request->tienda, fn($q, $t) => $q->porTienda($t))
            ->when($request->tipo,   fn($q, $t) => $q->porTipo($t))
            ->when($request->estado, fn($q, $e) => $q->porEstado($e))
            ->orderByDesc('fecha_registro')
            ->paginate($request->integer('per_page', 20));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tienda_id'         => 'required|string|max:50',
            'producto_nombre'   => 'required|string|max:150',
            'tipo'              => 'required|in:EQUIPO,ACCESORIO,CHIP',
            'imei_serial'       => 'nullable|string|max:50|unique:inventario_tiendas,imei_serial',
            'precio_costo'      => 'required|numeric|min:0',
            'precio_minimo'     => 'required|numeric|min:0',
            'precio_normal'     => 'required|numeric|min:0',
            'cantidad'          => 'required|integer|min:1',
            'estado'            => 'required|in:DISPONIBLE,VENDIDO,TRASLADO',
            'comision_especial' => 'nullable|numeric|min:0',
        ], [
            'tienda_id.required'       => 'La tienda es obligatoria.',
            'producto_nombre.required' => 'El nombre del producto es obligatorio.',
            'tipo.required'            => 'El tipo es obligatorio.',
            'tipo.in'                  => 'El tipo debe ser EQUIPO, ACCESORIO o CHIP.',
            'imei_serial.unique'       => 'Este IMEI/serie ya está registrado.',
            'precio_costo.required'    => 'El precio de costo es obligatorio.',
            'precio_normal.required'   => 'El precio normal es obligatorio.',
            'cantidad.required'        => 'La cantidad es obligatoria.',
            'estado.required'          => 'El estado es obligatorio.',
            'estado.in'                => 'El estado debe ser DISPONIBLE, VENDIDO o TRASLADO.',
        ]);

        $validated['fecha_registro'] = now();

        $item = InventarioTienda::create($validated);

        return response()->json($item, 201);
    }

    public function show(InventarioTienda $inventario): JsonResponse
    {
        return response()->json($inventario);
    }

    public function update(Request $request, InventarioTienda $inventario): JsonResponse
    {
        $validated = $request->validate([
            'tienda_id'         => 'sometimes|string|max:50',
            'producto_nombre'   => 'sometimes|string|max:150',
            'tipo'              => 'sometimes|in:EQUIPO,ACCESORIO,CHIP',
            'imei_serial'       => 'sometimes|nullable|string|max:50|unique:inventario_tiendas,imei_serial,' . $inventario->id,
            'precio_costo'      => 'sometimes|numeric|min:0',
            'precio_minimo'     => 'sometimes|numeric|min:0',
            'precio_normal'     => 'sometimes|numeric|min:0',
            'cantidad'          => 'sometimes|integer|min:0',
            'estado'            => 'sometimes|in:DISPONIBLE,VENDIDO,TRASLADO',
            'comision_especial' => 'sometimes|nullable|numeric|min:0',
        ]);

        $inventario->update($validated);

        return response()->json($inventario->fresh());
    }

    public function destroy(InventarioTienda $inventario): JsonResponse
    {
        $inventario->delete();
        return response()->json(null, 204);
    }
}
