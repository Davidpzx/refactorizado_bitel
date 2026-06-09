<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgenteRequest;
use App\Http\Requests\UpdateAgenteRequest;
use App\Models\Agente;
use App\Models\Reporte;
use App\Services\AgenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgenteController extends Controller
{
    public function __construct(private readonly AgenteService $service) {}

    public function index(Request $request): JsonResponse
    {
        $agentes = Agente::query()
            ->when($request->q, fn($q, $t) => $q->buscar($t))
            ->when($request->tienda, fn($q, $t) => $q->porTienda($t))
            ->when($request->estado, fn($q, $e) => $q->where('estado', $e))
            ->orderBy('nombres')
            ->paginate($request->integer('per_page', 20));

        return response()->json($agentes);
    }

    public function show(Agente $agente): JsonResponse
    {
        return response()->json($agente);
    }

    public function store(StoreAgenteRequest $request): JsonResponse
    {
        $agente = $this->service->crear($request->validated());
        return response()->json($agente, 201);
    }

    public function update(UpdateAgenteRequest $request, Agente $agente): JsonResponse
    {
        $agente = $this->service->actualizar($agente, $request->validated());
        return response()->json($agente);
    }

    public function destroy(Agente $agente): JsonResponse
    {
        if ($agente->reportes()->exists()) {
            return response()->json(['error' => 'No se puede eliminar: el agente tiene reportes asociados.'], 422);
        }
        $agente->delete();
        return response()->json(null, 204);
    }

    public function ventas(Agente $agente, Request $request): JsonResponse
    {
        $reportes = Reporte::query()
            ->where('agente_id', $agente->id)
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereDate('fecha', '>=', $f))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereDate('fecha', '<=', $f))
            ->withCount('ventas')
            ->select([
                'id', 'fecha', 'tienda_id', 'total_calculado',
                'efectivo_entregado', 'diferencia', 'estado', 'ventas_count',
            ])
            ->orderByDesc('fecha')
            ->paginate($request->integer('per_page', 20));

        $stats = Reporte::query()
            ->where('agente_id', $agente->id)
            ->where('estado', '!=', 'borrador')
            ->selectRaw('
                COUNT(*) as total_reportes,
                COALESCE(SUM(total_calculado), 0) as total_vendido,
                COALESCE(SUM(diferencia), 0) as diferencia_acumulada
            ')
            ->first();

        return response()->json(['agente' => $agente, 'stats' => $stats, 'reportes' => $reportes]);
    }

    public function comisiones(Agente $agente, Request $request): JsonResponse
    {
        $comisiones = \App\Models\Venta::query()
            ->where('vendedor_id', $agente->id)
            ->where('comision_estado', 'ACTIVA')
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereHas('reporte', fn ($r) => $r->whereDate('fecha', '>=', $f)))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereHas('reporte', fn ($r) => $r->whereDate('fecha', '<=', $f)))
            ->selectRaw('COALESCE(SUM(comision_generada), 0) as total_comision, COUNT(*) as total_ventas')
            ->first();

        return response()->json(['agente' => $agente, 'comisiones' => $comisiones]);
    }
}
