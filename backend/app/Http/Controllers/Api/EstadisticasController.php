<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\RankingVentaScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EstadisticasController extends Controller
{
    /**
     * Tienda a la que se atribuye la venta: la de destino si es apoyo inter-tienda
     * (cross_selling con tienda_destino), si no la del reporte. Paridad legacy
     * estadisticas_ventas.php (§49-68, 414-490).
     */
    private const EXPR_TIENDA_EFECTIVA =
        "CASE WHEN v.cross_selling = 1 AND v.tienda_destino IS NOT NULL AND v.tienda_destino <> ''"
        . " THEN v.tienda_destino ELSE r.tienda_id END";

    /** Tienda solo ve su propia tienda; admin puede filtrar por cualquiera (o ninguna = todas). */
    private function tiendaScope(Request $request): ?string
    {
        $user = $request->user();

        if ($user->rol !== 'admin') {
            // Fallar CERRADO: un usuario tienda sin tienda_id asignada no debe ver nada.
            // Los consumidores hacen `if ($tienda)` / `->when($tienda, ...)`, y un valor
            // falsy saltaria el filtro completo exponiendo todas las tiendas. Mismo
            // criterio que HistorialController, que con null filtra IS NULL y devuelve vacio.
            return $user->tienda_id ?: '__SIN_TIENDA__';
        }

        return $request->get('tienda');
    }

    public function ventas(Request $request): JsonResponse
    {
        $desde  = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta  = $request->get('fecha_hasta', now()->toDateString());
        $tienda = $this->tiendaScope($request);

        $base = DB::table('ventas as v')
            ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
            ->whereBetween('r.fecha', [$desde, $hasta])
            ->where('r.estado', '!=', 'borrador')
            ->where('v.comision_estado', '!=', 'ANULADA');

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
        // Apoyo inter-tienda: si la venta es cross_selling con tienda_destino, cuenta
        // para esa tienda destino, no para la del reporte (paridad legacy).
        $tiendaEfectiva = self::EXPR_TIENDA_EFECTIVA;
        $por_tienda = (clone $base)
            ->selectRaw("
                {$tiendaEfectiva} AS tienda_id,
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO'   THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'PREPAGO'    THEN 1 ELSE 0 END) AS prepago,
                SUM(CASE WHEN v.tipo_venta = 'EQUIPO'     THEN 1 ELSE 0 END) AS equipos,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO'  THEN 1 ELSE 0 END) AS accesorios,
                COUNT(*) AS total
            ")
            ->groupByRaw($tiendaEfectiva)
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
        $tienda = $this->tiendaScope($request);

        $ranking = DB::table('ventas as v')
            ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
            ->join('agentes as a', 'a.id', '=', 'v.vendedor_id')
            ->whereBetween('r.fecha', [$desde, $hasta])
            ->where('r.estado', '!=', 'borrador')
            ->where('v.comision_estado', '!=', 'ANULADA');
        // Excluir remates/UPGRADE/PAQUETE: misma regla que Planilla/Postpago.
        RankingVentaScope::aplicarSoloPostpago($ranking, 'v');
        $ranking = $ranking
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

    // ── GET /estadisticas/ranking — Ranking con categoría + subcategoría ────────
    // Paridad legacy api/obtener_ranking_agentes.php (drill-down equipos/postpago/chips).
    public function rankingAgentes(Request $request): JsonResponse
    {
        $desde       = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta       = $request->get('fecha_hasta', now()->toDateString());
        $tienda      = $this->tiendaScope($request);
        $categoria   = $request->get('categoria', 'todo');     // todo | equipos | postpago | chips
        $subcategoria = trim((string) $request->get('subcategoria', ''));

        // Sin filtro de categoría → ranking general (comportamiento previo).
        if ($categoria === 'todo' || $categoria === '') {
            return $this->productividad($request);
        }

        $tipoVenta = match ($categoria) {
            'equipos'  => 'EQUIPO',
            'postpago' => 'POSTPAGO',
            'chips'    => 'PREPAGO',
            default    => null,
        };
        if ($tipoVenta === null) {
            return response()->json(['error' => 'Categoría inválida.'], 422);
        }

        $query = DB::table('ventas as v')
            ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
            ->join('agentes as a', 'a.id', '=', 'v.vendedor_id')
            ->whereBetween('r.fecha', [$desde, $hasta])
            ->where('r.estado', '!=', 'borrador')
            ->where('v.comision_estado', '!=', 'ANULADA')
            ->where('v.tipo_venta', $tipoVenta)
            ->when($tienda, fn ($q) => $q->where('r.tienda_id', $tienda));
        // Excluir remates/UPGRADE/PAQUETE: misma regla que Planilla/Postpago.
        RankingVentaScope::aplicarSoloPostpago($query, 'v');

        // Subcategoría: modelo de equipo o plan de línea.
        if ($subcategoria !== '') {
            if ($categoria === 'equipos') {
                $query->join('venta_equipos as ve', 've.venta_id', '=', 'v.id')
                      ->where('ve.producto_nombre_snap', $subcategoria);
            } else {
                $query->join('venta_lineas as vl', 'vl.venta_id', '=', 'v.id')
                      ->where('vl.plan_nombre_snap', $subcategoria);
            }
        }

        $ranking = $query
            ->selectRaw('
                v.vendedor_id,
                a.nombres,
                a.tienda_base,
                COUNT(*) AS total,
                COALESCE(SUM(v.comision_generada), 0) AS comision_total
            ')
            ->groupBy('v.vendedor_id', 'a.nombres', 'a.tienda_base')
            ->orderByDesc('total')
            ->limit(30)
            ->get();

        return response()->json([
            'periodo'      => ['desde' => $desde, 'hasta' => $hasta],
            'categoria'    => $categoria,
            'subcategoria' => $subcategoria,
            'ranking'      => $ranking,
        ]);
    }

    // ── GET /estadisticas/ranking/subfiltros — Opciones de subcategoría del período ──
    // Paridad legacy api/obtener_subfiltros_ranking.php (poblar el <select> dinámico).
    public function subfiltrosRanking(Request $request): JsonResponse
    {
        $desde     = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta     = $request->get('fecha_hasta', now()->toDateString());
        $tienda    = $this->tiendaScope($request);
        $categoria = $request->get('categoria', 'todo');

        $opciones = [];

        if (in_array($categoria, ['equipos', 'postpago', 'chips'], true)) {
            $tipoVenta = $categoria === 'equipos' ? 'EQUIPO' : ($categoria === 'postpago' ? 'POSTPAGO' : 'PREPAGO');

            $base = DB::table('ventas as v')
                ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
                ->whereBetween('r.fecha', [$desde, $hasta])
                ->where('r.estado', '!=', 'borrador')
                ->where('v.tipo_venta', $tipoVenta)
                ->when($tienda, fn ($q) => $q->where('r.tienda_id', $tienda));

            if ($categoria === 'equipos') {
                $opciones = $base->join('venta_equipos as ve', 've.venta_id', '=', 'v.id')
                    ->whereNotNull('ve.producto_nombre_snap')
                    ->distinct()->orderBy('ve.producto_nombre_snap')
                    ->pluck('ve.producto_nombre_snap');
            } else {
                $opciones = $base->join('venta_lineas as vl', 'vl.venta_id', '=', 'v.id')
                    ->whereNotNull('vl.plan_nombre_snap')
                    ->distinct()->orderBy('vl.plan_nombre_snap')
                    ->pluck('vl.plan_nombre_snap');
            }
        }

        return response()->json([
            'categoria'    => $categoria,
            'subcategorias' => $opciones,
        ]);
    }

    public function exportar(Request $request): StreamedResponse
    {
        $desde = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta = $request->get('fecha_hasta', now()->toDateString());
        $tienda = $this->tiendaScope($request);
        $tef = self::EXPR_TIENDA_EFECTIVA;

        // Base reutilizable (clausura para no compartir estado entre queries).
        $base = fn () => DB::table('ventas as v')
            ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
            ->whereBetween('r.fecha', [$desde, $hasta])
            ->where('r.estado', '!=', 'borrador')
            ->where('v.comision_estado', '!=', 'ANULADA')
            ->when($tienda, fn ($q) => $q->where('r.tienda_id', $tienda));

        // ── KPIs de resumen ────────────────────────────────────────────────────
        $totales = $base()
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO'  THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'PREPAGO'   THEN 1 ELSE 0 END) AS prepago,
                SUM(CASE WHEN v.tipo_venta = 'EQUIPO'    THEN 1 ELSE 0 END) AS equipos,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO' THEN 1 ELSE 0 END) AS accesorios,
                COALESCE(SUM(v.monto_total), 0) AS monto_total,
                COALESCE(SUM(v.comision_generada), 0) AS comision_total
            ")
            ->first();

        $eqSplit = $base()
            ->join('venta_equipos as ve', 've.venta_id', '=', 'v.id')
            ->where('v.tipo_venta', 'EQUIPO')
            ->selectRaw("
                SUM(CASE WHEN ve.tipo_pago = 'CUOTAS'  THEN 1 ELSE 0 END) AS eq_cuotas,
                SUM(CASE WHEN ve.tipo_pago = 'CONTADO' THEN 1 ELSE 0 END) AS eq_contado
            ")
            ->first();

        // ── Ranking por tienda (con reasignación cross_selling → tienda_destino) ─
        $tPPA = $base()
            ->selectRaw("
                {$tef} AS tienda,
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO'  THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'PREPAGO'   THEN 1 ELSE 0 END) AS prepago,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO' THEN 1 ELSE 0 END) AS accesorios
            ")
            ->groupByRaw($tef)->get()->keyBy('tienda');

        $tEq = $base()
            ->join('venta_equipos as ve', 've.venta_id', '=', 'v.id')
            ->where('v.tipo_venta', 'EQUIPO')
            ->selectRaw("
                {$tef} AS tienda,
                SUM(CASE WHEN ve.tipo_pago = 'CUOTAS'  THEN 1 ELSE 0 END) AS eq_cuotas,
                SUM(CASE WHEN ve.tipo_pago = 'CONTADO' THEN 1 ELSE 0 END) AS eq_contado
            ")
            ->groupByRaw($tef)->get()->keyBy('tienda');

        $tiendas = $tPPA->keys()->merge($tEq->keys())->unique()
            ->map(function ($k) use ($tPPA, $tEq) {
                $p = $tPPA->get($k);
                $e = $tEq->get($k);
                $row = [
                    'tienda'     => $k,
                    'postpago'   => (int) ($p->postpago ?? 0),
                    'prepago'    => (int) ($p->prepago ?? 0),
                    'eq_cuotas'  => (int) ($e->eq_cuotas ?? 0),
                    'eq_contado' => (int) ($e->eq_contado ?? 0),
                    'accesorios' => (int) ($p->accesorios ?? 0),
                ];
                $row['total'] = $row['postpago'] + $row['prepago'] + $row['eq_cuotas'] + $row['eq_contado'] + $row['accesorios'];
                return $row;
            })
            ->sortByDesc('total')->values();

        // ── Ranking por agente (excluye remates/UPGRADE/PAQUETE) ────────────────
        $agNonEqQ = $base()->join('agentes as a', 'a.id', '=', 'v.vendedor_id');
        RankingVentaScope::aplicarSoloPostpago($agNonEqQ, 'v');
        $agNonEq = $agNonEqQ->selectRaw("
                v.vendedor_id, a.nombres, a.tienda_base,
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO'  THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO' THEN 1 ELSE 0 END) AS accesorios
            ")
            ->groupBy('v.vendedor_id', 'a.nombres', 'a.tienda_base')->get()->keyBy('vendedor_id');

        $agEqQ = $base()->join('venta_equipos as ve', 've.venta_id', '=', 'v.id')->where('v.tipo_venta', 'EQUIPO');
        RankingVentaScope::aplicarSoloPostpago($agEqQ, 'v');
        $agEq = $agEqQ->selectRaw("
                v.vendedor_id,
                SUM(CASE WHEN ve.tipo_pago = 'CUOTAS'  THEN 1 ELSE 0 END) AS eq_cuotas,
                SUM(CASE WHEN ve.tipo_pago = 'CONTADO' THEN 1 ELSE 0 END) AS eq_contado
            ")
            ->groupBy('v.vendedor_id')->get()->keyBy('vendedor_id');

        $agChipsQ = $base()->join('venta_lineas as vl', 'vl.venta_id', '=', 'v.id')->where('v.tipo_venta', 'PREPAGO');
        RankingVentaScope::aplicarSoloPostpago($agChipsQ, 'v');
        $agChips = $agChipsQ->selectRaw('v.vendedor_id, vl.plan_nombre_snap AS plan, SUM(vl.cantidad) AS qty')
            ->groupBy('v.vendedor_id', 'vl.plan_nombre_snap')->get();

        // Columnas dinámicas por plan de chip.
        $chipCols = $agChips->pluck('plan')->filter()->unique()->sort()->values();
        $chipPorAgente = [];
        foreach ($agChips as $c) {
            $chipPorAgente[(int) $c->vendedor_id][$c->plan] = (int) $c->qty;
        }

        // Ensamblar agentes: unir todos los que aparezcan en cualquier bloque.
        $agenteIds = $agNonEq->keys()
            ->merge($agEq->keys())
            ->merge(collect(array_keys($chipPorAgente)))
            ->map(fn ($id) => (int) $id)->unique();
        $nombres = $agenteIds->isEmpty()
            ? collect()
            : DB::table('agentes')->whereIn('id', $agenteIds->all())->get(['id', 'nombres', 'tienda_base'])->keyBy('id');

        $agentes = $agenteIds->map(function ($id) use ($agNonEq, $agEq, $chipPorAgente, $nombres, $chipCols) {
            $ne = $agNonEq->get($id);
            $eq = $agEq->get($id);
            $info = $nombres->get($id);
            $chips = $chipPorAgente[$id] ?? [];
            $row = [
                'nombres'    => $info->nombres ?? ('ID #' . $id),
                'tienda'     => $info->tienda_base ?? '—',
                'postpago'   => (int) ($ne->postpago ?? 0),
                'eq_cuotas'  => (int) ($eq->eq_cuotas ?? 0),
                'eq_contado' => (int) ($eq->eq_contado ?? 0),
                'accesorios' => (int) ($ne->accesorios ?? 0),
                'chips'      => $chips,
            ];
            $row['total'] = $row['postpago'] + $row['eq_cuotas'] + $row['eq_contado'] + $row['accesorios']
                + array_sum($chips);
            return $row;
        })->sortByDesc('total')->values();

        // ── Tops ────────────────────────────────────────────────────────────────
        $topEquipos = $base()
            ->join('venta_equipos as ve', 've.venta_id', '=', 'v.id')
            ->where('v.tipo_venta', 'EQUIPO')
            ->selectRaw('ve.producto_nombre_snap AS producto, COUNT(*) AS total')
            ->groupBy('ve.producto_nombre_snap')->orderByDesc('total')->limit(15)->get();

        $topAccesorios = $base()
            ->join('venta_equipos as ve', 've.venta_id', '=', 'v.id')
            ->where('v.tipo_venta', 'ACCESORIO')
            ->selectRaw('ve.producto_nombre_snap AS producto, COUNT(*) AS total')
            ->groupBy('ve.producto_nombre_snap')->orderByDesc('total')->limit(15)->get();

        $topPlanes = $base()
            ->join('venta_lineas as vl', 'vl.venta_id', '=', 'v.id')
            ->where('v.tipo_venta', 'POSTPAGO')
            ->where(fn ($q) => $q->whereNull('v.subtipo')->orWhere('v.subtipo', '!=', 'PAQUETE'))
            ->whereRaw("UPPER(COALESCE(vl.tipo_alta, '')) NOT LIKE '%UPGRADE%'")
            ->selectRaw('vl.plan_nombre_snap AS plan, COUNT(*) AS total')
            ->groupBy('vl.plan_nombre_snap')->orderByDesc('total')->limit(15)->get();

        // ── Construcción del workbook ───────────────────────────────────────────
        $spreadsheet = new Spreadsheet();

        $resumen = $spreadsheet->getActiveSheet();
        $resumen->setTitle('Resumen');
        $resumen->fromArray([
            ['Estadísticas de ventas', "{$desde} al {$hasta}"],
            ['Filtro tienda', $tienda ?: 'Todas'],
            ['Total ventas', (int) ($totales->total ?? 0)],
            ['Postpago', (int) ($totales->postpago ?? 0)],
            ['Prepago', (int) ($totales->prepago ?? 0)],
            ['Equipos', (int) ($totales->equipos ?? 0)],
            ['  · Eq. cuotas', (int) ($eqSplit->eq_cuotas ?? 0)],
            ['  · Eq. contado', (int) ($eqSplit->eq_contado ?? 0)],
            ['Accesorios', (int) ($totales->accesorios ?? 0)],
            ['Monto total', (float) ($totales->monto_total ?? 0)],
            ['Comisión total', (float) ($totales->comision_total ?? 0)],
        ]);
        $resumen->getStyle('A1:B1')->applyFromArray($this->estiloCabecera());
        $resumen->getColumnDimension('A')->setAutoSize(true);
        $resumen->getColumnDimension('B')->setAutoSize(true);

        // Hoja Tiendas.
        $porTienda = $spreadsheet->createSheet();
        $porTienda->setTitle('Tiendas');
        $porTienda->fromArray(['Posición', 'Tienda', 'Postpago', 'Prepago', 'Eq. Cuotas', 'Eq. Contado', 'Accesorios', 'Total'], null, 'A1');
        $porTienda->getStyle('A1:H1')->applyFromArray($this->estiloCabecera());
        $fila = 2;
        $gt = ['postpago' => 0, 'prepago' => 0, 'eq_cuotas' => 0, 'eq_contado' => 0, 'accesorios' => 0, 'total' => 0];
        foreach ($tiendas as $index => $row) {
            $porTienda->fromArray([
                $this->medalla($index),
                $row['tienda'],
                $row['postpago'], $row['prepago'], $row['eq_cuotas'], $row['eq_contado'], $row['accesorios'], $row['total'],
            ], null, 'A'.$fila);
            foreach ($gt as $k => $_) {
                $gt[$k] += $row[$k];
            }
            $fila++;
        }
        $porTienda->fromArray(['', 'TOTAL GLOBAL', $gt['postpago'], $gt['prepago'], $gt['eq_cuotas'], $gt['eq_contado'], $gt['accesorios'], $gt['total']], null, 'A'.$fila);
        $porTienda->getStyle('A'.$fila.':H'.$fila)->getFont()->setBold(true);

        // Hoja Agentes (columnas dinámicas por plan de chip).
        $porAgente = $spreadsheet->createSheet();
        $porAgente->setTitle('Agentes');
        $encAg = array_merge(
            ['Posición', 'Agente', 'Tienda', 'Postpago', 'Eq. Cuotas', 'Eq. Contado', 'Accesorios'],
            $chipCols->all(),
            ['Total']
        );
        $porAgente->fromArray($encAg, null, 'A1');
        $ultCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($encAg));
        $porAgente->getStyle('A1:'.$ultCol.'1')->applyFromArray($this->estiloCabecera());
        $fila = 2;
        $gtA = ['postpago' => 0, 'eq_cuotas' => 0, 'eq_contado' => 0, 'accesorios' => 0, 'total' => 0];
        $gtChips = array_fill_keys($chipCols->all(), 0);
        foreach ($agentes as $index => $row) {
            $celdas = [$this->medalla($index), $row['nombres'], $row['tienda'], $row['postpago'], $row['eq_cuotas'], $row['eq_contado'], $row['accesorios']];
            foreach ($chipCols as $plan) {
                $q = (int) ($row['chips'][$plan] ?? 0);
                $celdas[] = $q;
                $gtChips[$plan] += $q;
            }
            $celdas[] = $row['total'];
            $porAgente->fromArray($celdas, null, 'A'.$fila);
            foreach ($gtA as $k => $_) {
                $gtA[$k] += $row[$k];
            }
            $fila++;
        }
        $totalRow = ['', 'TOTAL GLOBAL', '', $gtA['postpago'], $gtA['eq_cuotas'], $gtA['eq_contado'], $gtA['accesorios']];
        foreach ($chipCols as $plan) {
            $totalRow[] = $gtChips[$plan];
        }
        $totalRow[] = $gtA['total'];
        $porAgente->fromArray($totalRow, null, 'A'.$fila);
        $porAgente->getStyle('A'.$fila.':'.$ultCol.$fila)->getFont()->setBold(true);

        // Hojas de tops.
        $this->hojaTop($spreadsheet, 'Top Equipos', 'Equipo', $topEquipos, 'producto');
        $this->hojaTop($spreadsheet, 'Top Accesorios', 'Accesorio', $topAccesorios, 'producto');
        $this->hojaTop($spreadsheet, 'Top Planes', 'Plan', $topPlanes, 'plan');

        foreach ([$porTienda, $porAgente] as $sheet) {
            foreach ($sheet->getColumnIterator() as $column) {
                $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            }
            $sheet->freezePane('A2');
        }

        return response()->streamDownload(
            static function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            "estadisticas_{$desde}_{$hasta}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    /** Medalla por posición 0-based; a partir del 4° devuelve "N°". */
    private function medalla(int $index): string
    {
        return match ($index) {
            0 => '🥇 1°',
            1 => '🥈 2°',
            2 => '🥉 3°',
            default => ($index + 1) . '°',
        };
    }

    /** Escribe una hoja de "Top N" (#, etiqueta, ventas). */
    private function hojaTop(Spreadsheet $spreadsheet, string $titulo, string $etiqueta, $filas, string $campo): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($titulo);
        $sheet->fromArray(['#', $etiqueta, 'Ventas'], null, 'A1');
        $sheet->getStyle('A1:C1')->applyFromArray($this->estiloCabecera());
        $fila = 2;
        foreach ($filas as $index => $row) {
            $sheet->fromArray([
                $this->medalla($index),
                $row->{$campo} ?? '—',
                (int) $row->total,
            ], null, 'A'.$fila);
            $fila++;
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
        $sheet->freezePane('A2');
    }

    private function estiloCabecera(): array
    {
        return [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A1A2E']],
        ];
    }
}
