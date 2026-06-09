<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HistorialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = $request->integer('per_page', 15);

        $query = Reporte::query()
            ->leftJoin('agentes as a', 'reportes.agente_id', '=', 'a.id')
            ->select([
                'reportes.*',
                DB::raw("COALESCE(a.nombres, 'Agente') AS agente_nombre"),
            ]);

        if ($user->rol === 'tienda') {
            $query->where('reportes.tienda_id', $user->tienda_id);
        } elseif ($request->tienda) {
            $query->where('reportes.tienda_id', $request->tienda);
        }

        $query
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereDate('reportes.fecha', '>=', $f))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereDate('reportes.fecha', '<=', $f))
            ->when($request->agente_id,   fn ($q, $v) => $q->where('reportes.agente_id', $v))
            ->when($request->estado,      fn ($q, $v) => $q->where('reportes.estado', $v));

        $reportes = $query
            ->orderByDesc('reportes.fecha')
            ->orderByDesc('reportes.id')
            ->paginate($perPage);

        return response()->json($reportes);
    }
}
