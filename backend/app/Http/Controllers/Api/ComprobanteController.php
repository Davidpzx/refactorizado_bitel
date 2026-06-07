<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\EnviarComprobanteSunat;
use App\Models\Comprobante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComprobanteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->estado_sunat) {
            $comprobantes = Comprobante::query()
                ->with(['venta.cliente'])
                ->where('estado_sunat', $request->estado_sunat)
                ->latest('creado_en')
                ->paginate($request->integer('per_page', 20));
        } else {
            $comprobantes = Comprobante::query()
                ->with(['venta.cliente'])
                ->when($request->tipo, fn($q, $t) => $q->where('tipo_comprobante', $t))
                ->pendientes()
                ->latest('creado_en')
                ->paginate($request->integer('per_page', 20));
        }

        return response()->json($comprobantes);
    }

    public function show(Comprobante $comprobante): JsonResponse
    {
        $comprobante->load(['venta.cliente', 'venta.equipo', 'venta.linea']);
        return response()->json($comprobante);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'venta_id'         => ['required', 'integer', 'exists:ventas,id'],
            'tipo_comprobante' => ['required', Rule::in(['03', '01'])],
            'serie'            => ['required', 'string', 'size:4'],
        ]);

        // Número correlativo auto-incremental por serie+tipo
        $ultimo = Comprobante::where('tipo_comprobante', $data['tipo_comprobante'])
            ->where('serie', $data['serie'])
            ->max('numero') ?? 0;

        $comprobante = Comprobante::create([
            'venta_id'         => $data['venta_id'],
            'tipo_comprobante' => $data['tipo_comprobante'],
            'serie'            => $data['serie'],
            'numero'           => $ultimo + 1,
            'estado_sunat'     => 'PENDIENTE',
            'intentos'         => 0,
        ]);

        EnviarComprobanteSunat::dispatch($comprobante->id)
            ->onQueue(config('sunat.queue', 'sunat'));

        return response()->json($comprobante, 201);
    }

    public function reenviar(Comprobante $comprobante): JsonResponse
    {
        if ($comprobante->estaAceptado()) {
            return response()->json(['error' => 'El comprobante ya fue aceptado por SUNAT.'], 422);
        }

        $comprobante->update([
            'estado_sunat'    => 'PENDIENTE',
            'intentos'        => 0,
            'proximo_intento' => null,
        ]);

        EnviarComprobanteSunat::dispatch($comprobante->id)
            ->onQueue(config('sunat.queue', 'sunat'));

        return response()->json(['message' => 'Comprobante encolado para reenvío.']);
    }

    public function destroy(Comprobante $comprobante): JsonResponse
    {
        if ($comprobante->estaAceptado()) {
            return response()->json(['error' => 'No se puede eliminar un comprobante aceptado por SUNAT.'], 422);
        }
        $comprobante->delete();
        return response()->json(null, 204);
    }
}
