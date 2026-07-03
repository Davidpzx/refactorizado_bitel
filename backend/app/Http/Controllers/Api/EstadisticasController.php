<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EstadisticasController extends Controller
{
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
        $tienda = $this->tiendaScope($request);

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
            ->where('v.tipo_venta', $tipoVenta)
            ->when($tienda, fn ($q) => $q->where('r.tienda_id', $tienda));

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

        $base = DB::table('ventas as v')
            ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
            ->whereBetween('r.fecha', [$desde, $hasta])
            ->where('r.estado', '!=', 'borrador')
            ->when($tienda, fn ($query) => $query->where('r.tienda_id', $tienda));

        $totales = (clone $base)
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO' THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'PREPAGO' THEN 1 ELSE 0 END) AS prepago,
                SUM(CASE WHEN v.tipo_venta = 'EQUIPO' THEN 1 ELSE 0 END) AS equipos,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO' THEN 1 ELSE 0 END) AS accesorios,
                COALESCE(SUM(v.monto_total), 0) AS monto_total,
                COALESCE(SUM(v.comision_generada), 0) AS comision_total
            ")
            ->first();

        $tiendas = (clone $base)
            ->selectRaw("
                r.tienda_id,
                COUNT(*) AS total,
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO' THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'PREPAGO' THEN 1 ELSE 0 END) AS prepago,
                SUM(CASE WHEN v.tipo_venta = 'EQUIPO' THEN 1 ELSE 0 END) AS equipos,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO' THEN 1 ELSE 0 END) AS accesorios,
                COALESCE(SUM(v.monto_total), 0) AS monto_total
            ")
            ->groupBy('r.tienda_id')
            ->orderByDesc('total')
            ->get();

        $agentes = (clone $base)
            ->join('agentes as a', 'a.id', '=', 'v.vendedor_id')
            ->selectRaw("
                a.nombres,
                a.tienda_base,
                COUNT(*) AS total,
                SUM(CASE WHEN v.tipo_venta = 'POSTPAGO' THEN 1 ELSE 0 END) AS postpago,
                SUM(CASE WHEN v.tipo_venta = 'PREPAGO' THEN 1 ELSE 0 END) AS prepago,
                SUM(CASE WHEN v.tipo_venta = 'EQUIPO' THEN 1 ELSE 0 END) AS equipos,
                SUM(CASE WHEN v.tipo_venta = 'ACCESORIO' THEN 1 ELSE 0 END) AS accesorios,
                COALESCE(SUM(v.comision_generada), 0) AS comision_total
            ")
            ->groupBy('v.vendedor_id', 'a.nombres', 'a.tienda_base')
            ->orderByDesc('total')
            ->get();

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
            ['Accesorios', (int) ($totales->accesorios ?? 0)],
            ['Monto total', (float) ($totales->monto_total ?? 0)],
            ['Comisión total', (float) ($totales->comision_total ?? 0)],
        ]);
        $resumen->getStyle('A1:B1')->applyFromArray($this->estiloCabecera());
        $resumen->getColumnDimension('A')->setAutoSize(true);
        $resumen->getColumnDimension('B')->setAutoSize(true);

        $porTienda = $spreadsheet->createSheet();
        $porTienda->setTitle('Tiendas');
        $porTienda->fromArray(['Posición', 'Tienda', 'Total', 'Postpago', 'Prepago', 'Equipos', 'Accesorios', 'Monto S/'], null, 'A1');
        $porTienda->getStyle('A1:H1')->applyFromArray($this->estiloCabecera());
        foreach ($tiendas as $index => $row) {
            $porTienda->fromArray([
                $index + 1,
                $row->tienda_id,
                (int) $row->total,
                (int) $row->postpago,
                (int) $row->prepago,
                (int) $row->equipos,
                (int) $row->accesorios,
                (float) $row->monto_total,
            ], null, 'A'.($index + 2));
        }

        $porAgente = $spreadsheet->createSheet();
        $porAgente->setTitle('Agentes');
        $porAgente->fromArray(['Posición', 'Agente', 'Tienda', 'Total', 'Postpago', 'Prepago', 'Equipos', 'Accesorios', 'Comisión S/'], null, 'A1');
        $porAgente->getStyle('A1:I1')->applyFromArray($this->estiloCabecera());
        foreach ($agentes as $index => $row) {
            $porAgente->fromArray([
                $index + 1,
                $row->nombres,
                $row->tienda_base,
                (int) $row->total,
                (int) $row->postpago,
                (int) $row->prepago,
                (int) $row->equipos,
                (int) $row->accesorios,
                (float) $row->comision_total,
            ], null, 'A'.($index + 2));
        }

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

    private function estiloCabecera(): array
    {
        return [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A1A2E']],
        ];
    }
}
