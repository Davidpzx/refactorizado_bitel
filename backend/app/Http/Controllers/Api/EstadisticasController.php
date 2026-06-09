<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EstadisticasController extends Controller
{
    public function ventas(Request $request): JsonResponse
    {
        $desde  = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta  = $request->get('fecha_hasta', now()->toDateString());
        $tienda = $request->get('tienda');

        $base = DB::table('ventas as v')
            ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
            ->whereBetween('r.fecha', [$desde, $hasta])
            ->where('r.estado', '!=', 'borrador');

        if ($tienda) {
            $base->where('r.tienda_id', $tienda);
        }

        // ── Totales por categoría ─────────────────────────────────────────────
        $totales = (clone $base)
            ->selectRaw("
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO'   THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'PREPAGO'    THEN 1 ELSE 0 END) AS prepago,
                SUM(CASE WHEN v.tipo_venta = 'EQUIPO' AND ve.tipo_pago = 'CUOTAS'   THEN 1 ELSE 0 END) AS eq_cuotas,
                SUM(CASE WHEN v.tipo_venta = 'EQUIPO' AND ve.tipo_pago = 'CONTADO'  THEN 1 ELSE 0 END) AS eq_contado,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO'  THEN 1 ELSE 0 END) AS accesorios,
                SUM(CASE WHEN v.tipo_venta = 'OTROS_FLUJO' THEN 1 ELSE 0 END) AS otros,
                COUNT(*) AS total_ventas,
                COALESCE(SUM(v.monto_total), 0) AS monto_total
            ")
            ->leftJoin('venta_equipos as ve', 've.venta_id', '=', 'v.id')
            ->first();

        // ── Ranking por tienda ────────────────────────────────────────────────
        $por_tienda = (clone $base)
            ->selectRaw("
                r.tienda_id,
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO'   THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'PREPAGO'    THEN 1 ELSE 0 END) AS prepago,
                SUM(CASE WHEN v.tipo_venta = 'EQUIPO'     THEN 1 ELSE 0 END) AS equipos,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO'  THEN 1 ELSE 0 END) AS accesorios,
                COUNT(*) AS total
            ")
            ->groupBy('r.tienda_id')
            ->orderByDesc('total')
            ->get();

        // ── Series de tiempo (por día) ────────────────────────────────────────
        $series = (clone $base)
            ->selectRaw("
                r.fecha AS dia,
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO'  THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'PREPAGO'   THEN 1 ELSE 0 END) AS prepago,
                SUM(CASE WHEN v.tipo_venta = 'EQUIPO'    THEN 1 ELSE 0 END) AS equipos,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO' THEN 1 ELSE 0 END) AS accesorios,
                COUNT(*) AS total
            ")
            ->groupBy('r.fecha')
            ->orderBy('r.fecha')
            ->get();

        // ── Top planes postpago ───────────────────────────────────────────────
        $top_planes = (clone $base)
            ->join('venta_lineas as vl', 'vl.venta_id', '=', 'v.id')
            ->where('v.tipo_venta', 'POSTPAGO')
            ->selectRaw('vl.plan_nombre_snap AS plan, COUNT(*) AS total')
            ->groupBy('vl.plan_nombre_snap')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        // ── Top equipos ───────────────────────────────────────────────────────
        $top_equipos = (clone $base)
            ->join('venta_equipos as ve2', 've2.venta_id', '=', 'v.id')
            ->where('v.tipo_venta', 'EQUIPO')
            ->selectRaw('ve2.producto_nombre_snap AS producto, COUNT(*) AS total')
            ->groupBy('ve2.producto_nombre_snap')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return response()->json([
            'periodo'     => ['desde' => $desde, 'hasta' => $hasta],
            'totales'     => $totales,
            'por_tienda'  => $por_tienda,
            'series'      => $series,
            'top_planes'  => $top_planes,
            'top_equipos' => $top_equipos,
        ]);
    }

    public function productividad(Request $request): JsonResponse
    {
        $desde  = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta  = $request->get('fecha_hasta', now()->toDateString());
        $tienda = $request->get('tienda');

        $ranking = DB::table('ventas as v')
            ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
            ->join('agentes as a', 'a.id', '=', 'v.vendedor_id')
            ->whereBetween('r.fecha', [$desde, $hasta])
            ->where('r.estado', '!=', 'borrador')
            ->when($tienda, fn($q) => $q->where('r.tienda_id', $tienda))
            ->selectRaw("
                v.vendedor_id,
                a.nombres,
                a.tienda_base,
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO'  THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'PREPAGO'   THEN 1 ELSE 0 END) AS prepago,
                SUM(CASE WHEN v.tipo_venta = 'EQUIPO'    THEN 1 ELSE 0 END) AS equipos,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO' THEN 1 ELSE 0 END) AS accesorios,
                COALESCE(SUM(v.comision_generada), 0) AS comision_total,
                COUNT(*) AS total
            ")
            ->groupBy('v.vendedor_id', 'a.nombres', 'a.tienda_base')
            ->orderByDesc('total')
            ->limit(30)
            ->get();

        return response()->json([
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'ranking' => $ranking,
        ]);
    }

    public function rankingAgentes(Request $request): JsonResponse
    {
        return $this->productividad($request);
    }
}
