<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgenteRequest;
use App\Http\Requests\UpdateAgenteRequest;
use App\Models\Agente;
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
}
