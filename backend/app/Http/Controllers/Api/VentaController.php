<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venta;
use App\Models\Cliente;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VentaController extends Controller
{
    private function aplicarScopeTienda(Builder $query, Request $request): Builder
    {
        $user = $request->user();

        if (! $user->esAdministrador()) {
            $query->whereHas('reporte', fn ($reporte) => $reporte
                ->where('tienda_id', $user->tienda_id ?: '__SIN_TIENDA__'));
        }

        return $query;
    }

    private function autorizarAcceso(Request $request, Venta $venta): void
    {
        abort_unless(
            $this->aplicarScopeTienda(Venta::query()->whereKey($venta->getKey()), $request)->exists(),
            404
        );
    }

    public function index(Request $request): JsonResponse
    {
        $ventas = $this->aplicarScopeTienda(Venta::query(), $request)
            ->with(['cliente', 'items'])
            ->when($request->reporte_id, fn($q, $id) => $q->porReporte($id))
            ->when($request->tipo_venta, fn($q, $tipo) => $q->porTipo($tipo))
            ->when($request->vendedor_id, fn($q, $id) => $q->where('vendedor_id', $id))
            ->latest('creado_en')
            ->paginate(Paginacion::desde($request, 20));

        return response()->json($ventas);
    }

    public function show(Request $request, Venta $venta): JsonResponse
    {
        $this->autorizarAcceso($request, $venta);

        $venta->load(['cliente', 'items', 'comprobantes']);
        return response()->json($venta);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reporte_id'        => ['required', 'integer'],
            'vendedor_id'       => ['required', 'integer'],
            'cliente_dni'       => ['nullable', 'string', 'regex:/^\d{8}(\d{3})?$/'],
            'tipo_venta'        => ['required', Rule::in(['EQUIPO', 'ACCESORIO', 'POSTPAGO', 'PREPAGO', 'OTROS_FLUJO'])],
            'subtipo'           => ['nullable', 'string', 'max:50'],
            'cross_selling'     => ['boolean'],
            'tienda_destino'    => ['nullable', 'string', 'max:10'],
            'monto_total'       => ['required', 'numeric', 'min:0'],
            'efectivo_inicial'  => ['nullable', 'numeric', 'min:0'],
            'comision_generada' => ['nullable', 'numeric', 'min:0'],
            'comision_estado'   => ['sometimes', Rule::in(['ACTIVA', 'PENDIENTE', 'ANULADA'])],
            'items'             => ['sometimes', 'array'],
            'items.*.tipo_item'      => ['required', 'string'],
            'items.*.descripcion'    => ['required', 'string'],
            'items.*.cantidad'       => ['required', 'integer', 'min:1'],
            'items.*.precio_unitario'=> ['required', 'numeric', 'min:0'],
        ], [
            'vendedor_id.required' => 'Debe seleccionar un agente.',
            'vendedor_id.integer' => 'El agente seleccionado no es valido.',
        ]);

        return DB::transaction(function () use ($data, $request) {
            // Resolver o crear cliente
            $clienteId = null;
            if (!empty($data['cliente_dni'])) {
                $cliente = Cliente::firstOrCreate(
                    ['dni_ruc' => $data['cliente_dni']],
                    ['nombre' => 'Por identificar', 'tipo_documento' => strlen($data['cliente_dni']) === 8 ? 'DNI' : 'RUC']
                );
                $clienteId = $cliente->id;
            }

            $venta = Venta::create([
                'reporte_id'        => $data['reporte_id'],
                'vendedor_id'       => $data['vendedor_id'],
                'cliente_id'        => $clienteId,
                'tipo_venta'        => $data['tipo_venta'],
                'subtipo'           => $data['subtipo'] ?? null,
                'cross_selling'     => $data['cross_selling'] ?? false,
                'tienda_destino'    => $data['tienda_destino'] ?? null,
                'monto_total'       => $data['monto_total'],
                'efectivo_inicial'  => $data['efectivo_inicial'] ?? $data['monto_total'],
                'comision_generada' => $data['comision_generada'] ?? 0,
                'comision_estado'   => $data['comision_estado'] ?? 'ACTIVA',
            ]);

            if (!empty($data['items'])) {
                $venta->items()->createMany($data['items']);
            }

            return response()->json($venta->load(['cliente', 'items']), 201);
        });
    }

    public function update(Request $request, Venta $venta): JsonResponse
    {
        $this->autorizarAcceso($request, $venta);

        $data = $request->validate([
            'comision_estado' => ['sometimes', Rule::in(['ACTIVA', 'PENDIENTE', 'ANULADA'])],
            'monto_total'     => ['sometimes', 'numeric', 'min:0'],
            'tienda_destino'  => ['nullable', 'string', 'max:10'],
        ]);

        $venta->update($data);
        return response()->json($venta->fresh(['cliente', 'items']));
    }

    public function destroy(Request $request, Venta $venta): JsonResponse
    {
        $this->autorizarAcceso($request, $venta);

        if ($venta->comprobantes()->where('estado_sunat', 'ACEPTADO')->exists()) {
            return response()->json(['error' => 'No se puede eliminar: tiene comprobantes aceptados por SUNAT.'], 422);
        }
        $venta->delete();
        return response()->json(null, 204);
    }
}
