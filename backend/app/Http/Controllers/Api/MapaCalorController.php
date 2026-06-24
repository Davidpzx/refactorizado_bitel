<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MapaCalorController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tablaExiste(string $tabla): bool
    {
        return Schema::hasTable($tabla);
    }

    // ── Endpoint 1: Calendario ────────────────────────────────────────────────
    // GET /v1/heatmap/calendario?year=2026&tienda=&metrica=ventas
    // metrica: ventas | bipay | ambas
    // Devuelve un array de { fecha, ventas_count, ventas_monto, bipay_neto }
    // por cada día del año solicitado.

    public function calendario(Request $request): JsonResponse
    {
        $year    = (int) ($request->get('year', now()->year));
        $tienda  = $request->get('tienda'); // tienda_id o tienda_codigo
        $metrica = $request->get('metrica', 'ambas'); // ventas | bipay | ambas

        $desde = "{$year}-01-01";
        $hasta = "{$year}-12-31";

        // ── Ventas desde reportes ─────────────────────────────────────────────
        $ventasPorDia = [];
        if (in_array($metrica, ['ventas', 'ambas'])) {
            $q = DB::table('ventas as v')
                ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
                ->whereBetween('r.fecha', [$desde, $hasta])
                ->where('r.estado', '!=', 'borrador')
                ->selectRaw("r.fecha, COUNT(*) AS cnt, COALESCE(SUM(v.monto_total), 0) AS monto");

            if ($tienda) {
                $q->where('r.tienda_id', $tienda);
            }

            foreach ($q->groupBy('r.fecha')->get() as $row) {
                $ventasPorDia[$row->fecha] = [
                    'cnt'   => (int) $row->cnt,
                    'monto' => (float) $row->monto,
                ];
            }
        }

        // ── Bipay desde bitel_movimientos_diarios ─────────────────────────────
        $bipayPorDia = [];
        if (in_array($metrica, ['bipay', 'ambas']) && $this->tablaExiste('bitel_movimientos_diarios')) {
            $q = DB::table('bitel_movimientos_diarios as bm')
                ->whereBetween('bm.fecha', [$desde, $hasta])
                ->selectRaw("bm.fecha, COALESCE(SUM(bm.total_neto), 0) AS neto, COALESCE(SUM(bm.postpago + bm.prepago + bm.portabilidad), 0) AS lineas");

            if ($tienda) {
                // bitel_movimientos_diarios usa cod_tienda (VARCHAR)
                // Si el caller pasa un entero (tienda_id) no filtramos bipay
                if (!is_numeric($tienda)) {
                    $q->where('bm.cod_tienda', $tienda);
                }
            }

            foreach ($q->groupBy('bm.fecha')->get() as $row) {
                $bipayPorDia[$row->fecha] = [
                    'neto'   => (float) $row->neto,
                    'lineas' => (float) $row->lineas,
                ];
            }
        }

        // ── Fusionar en array por día ─────────────────────────────────────────
        $todas = array_unique(array_merge(array_keys($ventasPorDia), array_keys($bipayPorDia)));
        $dias  = [];
        foreach ($todas as $fecha) {
            $v = $ventasPorDia[$fecha] ?? ['cnt' => 0, 'monto' => 0.0];
            $b = $bipayPorDia[$fecha]  ?? ['neto' => 0.0, 'lineas' => 0.0];
            $dias[] = [
                'fecha'          => $fecha,
                'ventas_count'   => $v['cnt'],
                'ventas_monto'   => $v['monto'],
                'bipay_neto'     => $b['neto'],
                'bipay_lineas'   => $b['lineas'],
                'total_actividad'=> $v['cnt'] + $b['lineas'],
            ];
        }

        usort($dias, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));

        return response()->json([
            'year'   => $year,
            'tienda' => $tienda,
            'dias'   => $dias,
        ]);
    }

    // ── Endpoint 2: Geográfico ────────────────────────────────────────────────
    // GET /v1/heatmap/geografico?fecha_desde=&fecha_hasta=&metrica=ventas
    // Devuelve tiendas con lat/lon + métricas del período

    public function geografico(Request $request): JsonResponse
    {
        $desde  = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta  = $request->get('fecha_hasta', now()->toDateString());
        $metrica = $request->get('metrica', 'ambas');

        // ── Tiendas con coordenadas ───────────────────────────────────────────
        $tiendas = DB::table('tiendas')
            ->whereNotNull('latitud')
            ->whereNotNull('longitud')
            ->where('latitud', '!=', 0)
            ->where('longitud', '!=', 0)
            ->select('codigo', 'nombre', 'latitud', 'longitud')
            ->get()
            ->keyBy('codigo');

        if ($tiendas->isEmpty()) {
            return response()->json([
                'desde'   => $desde,
                'hasta'   => $hasta,
                'tiendas' => [],
                'max_valor' => 0,
            ]);
        }

        // ── Ventas por tienda_id en el período ───────────────────────────────
        $ventasPorTienda = [];
        if (in_array($metrica, ['ventas', 'ambas'])) {
            $rows = DB::table('ventas as v')
                ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
                ->whereBetween('r.fecha', [$desde, $hasta])
                ->where('r.estado', '!=', 'borrador')
                ->selectRaw("r.tienda_id, COUNT(*) AS cnt, COALESCE(SUM(v.monto_total), 0) AS monto")
                ->groupBy('r.tienda_id')
                ->get();

            // Necesitamos mapear tienda_id → codigo. Leemos tiendas con id
            $tiendaIdACodigo = DB::table('tiendas')
                ->pluck('codigo', 'id');

            foreach ($rows as $row) {
                $cod = $tiendaIdACodigo[$row->tienda_id] ?? null;
                if ($cod) {
                    $ventasPorTienda[$cod] = [
                        'cnt'   => (int) $row->cnt,
                        'monto' => (float) $row->monto,
                    ];
                }
            }
        }

        // ── Bipay por cod_tienda en el período ────────────────────────────────
        $bipayPorTienda = [];
        if (in_array($metrica, ['bipay', 'ambas']) && $this->tablaExiste('bitel_movimientos_diarios')) {
            $rows = DB::table('bitel_movimientos_diarios')
                ->whereBetween('fecha', [$desde, $hasta])
                ->selectRaw("cod_tienda, COALESCE(SUM(total_neto), 0) AS neto, COALESCE(SUM(postpago + prepago + portabilidad), 0) AS lineas")
                ->groupBy('cod_tienda')
                ->get();

            foreach ($rows as $row) {
                $bipayPorTienda[$row->cod_tienda] = [
                    'neto'   => (float) $row->neto,
                    'lineas' => (float) $row->lineas,
                ];
            }
        }

        // ── Fusionar por tienda ───────────────────────────────────────────────
        $resultado = [];
        $maxValor  = 0;

        foreach ($tiendas as $cod => $t) {
            $v     = $ventasPorTienda[$cod] ?? ['cnt' => 0, 'monto' => 0.0];
            $b     = $bipayPorTienda[$cod]  ?? ['neto' => 0.0, 'lineas' => 0.0];
            $valor = $v['cnt'] + $b['lineas'];
            if ($valor > $maxValor) $maxValor = $valor;

            $resultado[] = [
                'codigo'        => $cod,
                'nombre'        => $t->nombre,
                'lat'           => (float) $t->latitud,
                'lng'           => (float) $t->longitud,
                'ventas_count'  => $v['cnt'],
                'ventas_monto'  => $v['monto'],
                'bipay_neto'    => $b['neto'],
                'bipay_lineas'  => $b['lineas'],
                'valor'         => $valor,
            ];
        }

        usort($resultado, fn($a, $b) => $b['valor'] <=> $a['valor']);

        return response()->json([
            'desde'      => $desde,
            'hasta'      => $hasta,
            'tiendas'    => $resultado,
            'max_valor'  => $maxValor,
        ]);
    }

    // ── Endpoint 3: Horario ───────────────────────────────────────────────────
    // GET /v1/heatmap/horario?fecha_desde=&fecha_hasta=&tienda=
    // Devuelve grid hora (0-23) × día de semana (0=Dom … 6=Sáb)
    // basado en bitel_operaciones_detalle.tiempo

    public function horario(Request $request): JsonResponse
    {
        $desde  = $request->get('fecha_desde', now()->subDays(30)->toDateString());
        $hasta  = $request->get('fecha_hasta', now()->toDateString());
        $tienda = $request->get('tienda');

        // Fallback: si no existe bitel_operaciones_detalle, usar reportes por fecha
        if (!$this->tablaExiste('bitel_operaciones_detalle')) {
            return $this->horarioFallbackReportes($desde, $hasta, $tienda);
        }

        $q = DB::table('bitel_operaciones_detalle')
            ->whereBetween(DB::raw('DATE(tiempo)'), [$desde, $hasta])
            ->selectRaw("
                HOUR(tiempo)        AS hora,
                DAYOFWEEK(tiempo)   AS dia_semana,
                COUNT(*)            AS cnt,
                COALESCE(ABS(SUM(cantidad)), 0) AS monto_abs
            ")
            ->groupBy(DB::raw('HOUR(tiempo), DAYOFWEEK(tiempo)'));

        if ($tienda) {
            $q->where('cod_tienda', $tienda);
        }

        $rows = $q->get();

        // DAYOFWEEK: 1=Dom, 2=Lun … 7=Sáb → convertimos a 0=Lun…6=Dom
        $grid = [];
        $maxCnt = 0;
        foreach ($rows as $r) {
            $dow = ((int)$r->dia_semana - 2 + 7) % 7; // 0=Lun, 6=Dom
            $grid[] = [
                'hora'      => (int) $r->hora,
                'dia'       => $dow,
                'cnt'       => (int) $r->cnt,
                'monto_abs' => (float) $r->monto_abs,
            ];
            if ((int)$r->cnt > $maxCnt) $maxCnt = (int)$r->cnt;
        }

        return response()->json([
            'desde'   => $desde,
            'hasta'   => $hasta,
            'grid'    => $grid,
            'max_cnt' => $maxCnt,
            'fuente'  => 'bitel_operaciones_detalle',
        ]);
    }

    private function horarioFallbackReportes(string $desde, string $hasta, ?string $tienda): JsonResponse
    {
        // Fallback: distribución por día de semana de reportes (sin granularidad horaria)
        $q = DB::table('reportes')
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', '!=', 'borrador')
            ->selectRaw("
                0                   AS hora,
                DAYOFWEEK(fecha)    AS dia_semana,
                COUNT(*)            AS cnt,
                0                   AS monto_abs
            ")
            ->groupBy(DB::raw('DAYOFWEEK(fecha)'));

        if ($tienda) {
            $q->where('tienda_id', $tienda);
        }

        $rows = $q->get();
        $grid = [];
        $maxCnt = 0;
        foreach ($rows as $r) {
            $dow = ((int)$r->dia_semana - 2 + 7) % 7;
            $grid[] = [
                'hora' => 0,
                'dia'  => $dow,
                'cnt'  => (int) $r->cnt,
                'monto_abs' => 0,
            ];
            if ((int)$r->cnt > $maxCnt) $maxCnt = (int)$r->cnt;
        }

        return response()->json([
            'desde'   => $desde,
            'hasta'   => $hasta,
            'grid'    => $grid,
            'max_cnt' => $maxCnt,
            'fuente'  => 'reportes_fallback',
        ]);
    }
}
