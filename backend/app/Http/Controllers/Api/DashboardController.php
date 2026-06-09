<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function kpis(Request $request): JsonResponse
    {
        $user = $request->user();

        $base = DB::table('reportes as r')
            ->where('r.estado', '!=', 'borrador')
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereDate('r.fecha', '>=', $f))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereDate('r.fecha', '<=', $f));

        if ($user->rol === 'tienda') {
            $base->where('r.tienda_id', $user->tienda_id);
        } elseif ($request->tienda) {
            $base->where('r.tienda_id', $request->tienda);
        }

        $totales = (clone $base)->selectRaw("
            COALESCE(SUM(r.total_calculado), 0)     AS total_general,
            COALESCE(SUM(r.efectivo_esperado), 0)   AS fisico_esperado,
            COALESCE(SUM(r.efectivo_entregado), 0)  AS fisico_declarado,
            COALESCE(SUM(r.diferencia), 0)          AS diferencia_fisica,
            COALESCE(SUM(r.yape), 0)                AS total_yape,
            COALESCE(SUM(r.bipay), 0)               AS total_bipay,
            COALESCE(SUM(r.transferencia), 0)       AS total_transferencia,
            COUNT(r.id)                              AS total_reportes
        ")->first();

        $ganancia_total = null;
        if ($user->rol === 'admin') {
            $ids = (clone $base)->pluck('r.id');
            $ganancia_total = DB::table('ventas as v')
                ->leftJoin('venta_equipos as ve', 've.venta_id', '=', 'v.id')
                ->leftJoin('venta_lineas as vl', 'vl.venta_id', '=', 'v.id')
                ->whereIn('v.reporte_id', $ids)
                ->selectRaw("
                    COALESCE(SUM(COALESCE(ve.ganancia_snap, 0)), 0)
                    + COALESCE(SUM(COALESCE(vl.comision_unitaria * vl.cantidad, 0)), 0)
                    AS total_ganancia
                ")
                ->value('total_ganancia') ?? 0;
        }

        $reportes = (clone $base)
            ->leftJoin('agentes as a', 'r.agente_id', '=', 'a.id')
            ->select([
                'r.id', 'r.fecha', 'r.tienda_id', 'r.agente_id',
                'r.total_calculado', 'r.efectivo_esperado', 'r.efectivo_entregado',
                'r.diferencia', 'r.estado', 'r.estado_edicion', 'r.destino_efectivo',
                DB::raw("COALESCE(a.nombres, 'Agente') AS agente_nombre"),
            ])
            ->orderByDesc('r.fecha')
            ->orderByDesc('r.id')
            ->limit(5)
            ->get();

        return response()->json([
            'totales'        => $totales,
            'ganancia_total' => $ganancia_total,
            'reportes'       => $reportes,
        ]);
    }

    public function anomalias(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = DB::table('reportes')
            ->where('estado', '!=', 'borrador')
            ->where(function ($q) {
                $q->where('diferencia', '!=', 0)
                  ->orWhere('estado', 'editado');
            })
            ->where('fecha', '>=', now()->subDays(30)->toDateString());

        if ($user->rol === 'tienda') {
            $query->where('tienda_id', $user->tienda_id);
        }

        $count = $query->count();

        $lista = (clone $query)
            ->select([
                'id', 'fecha', 'tienda_id', 'agente_id',
                'diferencia', 'estado', 'efectivo_esperado', 'efectivo_entregado',
            ])
            ->orderByDesc('fecha')
            ->limit(50)
            ->get();

        return response()->json(['count' => $count, 'anomalias' => $lista]);
    }
}
