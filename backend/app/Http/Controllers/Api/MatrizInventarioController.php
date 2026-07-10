<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MatrizInventarioController extends Controller
{
    // ── GET /inventario/matriz — Matriz de inventario por tiendas ──────────────
    public function index(): JsonResponse
    {
        // 1. Tiendas (CENTRAL primero, luego alfabético)
        $tiendas = DB::table('tiendas')
            ->orderByRaw("CASE WHEN UPPER(TRIM(codigo)) IN ('CENTRAL','S/C','SC') THEN 0 ELSE 1 END ASC")
            ->orderBy('nombre')
            ->select('codigo', 'nombre')
            ->get()
            ->map(fn($t) => ['codigo' => $t->codigo, 'nombre' => $t->nombre]);

        $tiendaCodigos = $tiendas->pluck('codigo')->toArray();

        // 2. Matriz equipos
        $rawEquipos = DB::table('inventario_tiendas')
            ->where('tipo', 'EQUIPO')
            ->where('estado', 'DISPONIBLE')
            ->groupBy('producto_nombre', 'tienda_id')
            ->select('producto_nombre', 'tienda_id', DB::raw('SUM(cantidad) AS stock'))
            ->orderBy('producto_nombre')
            ->get();

        $matrizEquipos = [];
        foreach ($rawEquipos as $row) {
            $matrizEquipos[$row->producto_nombre][$row->tienda_id] = (int)$row->stock;
        }
        ksort($matrizEquipos);

        // 3. Matriz accesorios
        $rawAccesorios = DB::table('inventario_tiendas')
            ->where('tipo', 'ACCESORIO')
            ->where('estado', 'DISPONIBLE')
            ->groupBy('producto_nombre', 'tienda_id')
            ->select('producto_nombre', 'tienda_id', DB::raw('SUM(cantidad) AS stock'))
            ->orderBy('producto_nombre')
            ->get();

        $matrizAccesorios = [];
        foreach ($rawAccesorios as $row) {
            $matrizAccesorios[$row->producto_nombre][$row->tienda_id] = (int)$row->stock;
        }
        ksort($matrizAccesorios);

        // 4. Matriz chips
        // codigo_origen se resuelve en PHP (no en SQL) porque REGEXP y CAST(...AS UNSIGNED)
        // son sintaxis MySQL-only y rompen contra SQLite (tests/QA local).
        $tiendaCodigoPorId = DB::table('tiendas')->pluck('codigo', 'id');

        $rawChips = DB::table('inventario_chips as c')
            ->join('tiendas as t', 'c.tienda_id', '=', 't.id')
            ->where('c.stock_actual', '>', 0)
            ->select('c.stock_actual', 'c.tienda_origen', 't.codigo as tienda_codigo')
            ->get();

        $matrizChips = [];
        foreach ($rawChips as $row) {
            $tiendaOrigen = trim((string)($row->tienda_origen ?? ''));
            if ($tiendaOrigen === '') {
                $origen = $row->tienda_codigo;
            } elseif (ctype_digit($tiendaOrigen)) {
                $origen = $tiendaCodigoPorId[(int)$tiendaOrigen] ?? $tiendaOrigen;
            } else {
                $origen = $tiendaOrigen;
            }

            $origen = trim($origen);
            $tienda = trim($row->tienda_codigo);
            if (!isset($matrizChips[$origen][$tienda])) {
                $matrizChips[$origen][$tienda] = 0;
            }
            $matrizChips[$origen][$tienda] += (int)$row->stock_actual;
        }
        ksort($matrizChips);

        // 5. Transformar a formato de filas para el frontend
        $equiposRows     = $this->buildRows($matrizEquipos, $tiendaCodigos);
        $accesoriosRows  = $this->buildRows($matrizAccesorios, $tiendaCodigos);
        $chipsRows       = $this->buildRows($matrizChips, $tiendaCodigos);

        return response()->json([
            'tiendas'     => $tiendas->values(),
            'equipos'     => $equiposRows,
            'accesorios'  => $accesoriosRows,
            'chips'       => $chipsRows,
        ]);
    }

    private function buildRows(array $matrix, array $tiendaCodigos): array
    {
        $rows = [];
        foreach ($matrix as $nombre => $stockPorTienda) {
            $fila = ['nombre' => $nombre, 'total' => 0];
            foreach ($tiendaCodigos as $codigo) {
                $stock           = $stockPorTienda[$codigo] ?? 0;
                $fila[$codigo]   = $stock;
                $fila['total']  += $stock;
            }
            $rows[] = $fila;
        }
        return $rows;
    }

    // ── GET /inventario/exportar — Exportar inventario a XLSX ──────────────────
    // Antes emitía CSV pero el frontend (InventarioPage.tsx) siempre lo descarga
    // con extensión .xlsx: Excel rechaza el archivo por "formato o extensión no
    // válidos". También ignoraba los filtros estado/tienda/q que el usuario ya
    // aplicó en pantalla — un filtro a "Vendido" exportaba un archivo vacío
    // porque el estado quedaba fijo a DISPONIBLE/TRASLADO sin importar lo pedido.
    public function exportar(Request $request): StreamedResponse
    {
        $user    = $request->user();
        $esAdmin = $user->rol === 'admin';
        $tipo    = strtoupper($request->input('tipo', 'EQUIPO'));

        $items = DB::table('inventario_tiendas as it')
            ->leftJoin('tiendas as t', 'it.tienda_id', '=', 't.codigo')
            ->where('it.tipo', $tipo)
            ->when(! $esAdmin, fn ($q) => $q->where('it.tienda_id', $user->tienda_id))
            ->when($esAdmin && $request->filled('tienda'), fn ($q) => $q->where('it.tienda_id', $request->input('tienda')))
            ->when(
                $request->filled('estado'),
                fn ($q) => $q->where('it.estado', strtoupper((string) $request->input('estado'))),
                fn ($q) => $q->whereIn('it.estado', ['DISPONIBLE', 'TRASLADO'])
            )
            ->when($request->filled('q'), function ($q) use ($request) {
                $texto = '%' . trim((string) $request->input('q')) . '%';
                $q->where(fn ($qq) => $qq->where('it.producto_nombre', 'like', $texto)->orWhere('it.imei_serial', 'like', $texto));
            })
            ->select(
                'it.id',
                'it.producto_nombre',
                'it.imei_serial',
                'it.cantidad',
                'it.precio_costo',
                'it.precio_minimo',
                'it.precio_normal',
                'it.estado',
                'it.tienda_id',
                DB::raw("COALESCE(t.nombre, it.tienda_id) as tienda_nombre"),
                'it.fecha_registro'
            )
            ->orderBy('it.tienda_id')
            ->orderBy('it.producto_nombre')
            ->get();

        $headers = ['ID', 'Producto', 'IMEI/Serial', 'Cantidad', 'P.Costo', 'P.Mínimo', 'P.Normal', 'Estado', 'Tienda', 'Fecha Registro'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Inventario');
        $sheet->fromArray($headers, null, 'A1');
        $ultimaColumna = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$ultimaColumna}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A1A2E']],
        ]);

        foreach ($items as $index => $item) {
            $sheet->fromArray([
                $item->id,
                $item->producto_nombre,
                $item->imei_serial ?? '',
                (int) $item->cantidad,
                (float) $item->precio_costo,
                (float) $item->precio_minimo,
                (float) $item->precio_normal,
                $item->estado,
                $item->tienda_nombre,
                $item->fecha_registro,
            ], null, 'A' . ($index + 2));
        }

        for ($column = 1; $column <= count($headers); $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        return response()->streamDownload(
            static function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'inventario_' . strtolower($tipo) . '_' . date('Y-m-d') . '.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    // ── GET /agentes/exportar — Exportar agentes con comisiones a CSV ─────────
    public function exportarAgentes(Request $request)
    {
        $mes = $request->input('mes', date('Y-m'));

        // `agentes` no tiene columna `tienda_id` (solo `tienda_base`); el join
        // anterior a `a.tienda_id` habría fallado con "Unknown column" en MySQL.
        $agentes = DB::table('agentes as a')
            ->leftJoin('tiendas as t', 'a.tienda_base', '=', 't.codigo')
            ->select(
                'a.id',
                'a.nombres',
                'a.dni',
                'a.tienda_base',
                DB::raw("COALESCE(t.nombre, a.tienda_base) as tienda_nombre"),
                'a.estado',
                'a.es_gerencia',
                'a.fecha_ingreso',
            )
            ->where('a.estado', 'ACTIVO')
            ->orderBy('a.tienda_base')
            ->orderBy('a.nombres')
            ->get();

        // Comisiones del mes. `reportes.estado` solo toma 'borrador'|'enviado'
        // (nunca 'cerrado' — ese filtro dejaba esta columna siempre en 0).
        // whereBetween en vez de DATE_FORMAT: funciona igual en MySQL y SQLite.
        $inicioMes = $mes . '-01';
        $finMes    = date('Y-m-t', strtotime($inicioMes));
        $comisionesRaw = DB::table('reportes as r')
            ->whereBetween('r.fecha', [$inicioMes, $finMes])
            ->where('r.estado', 'enviado')
            ->groupBy('r.agente_id')
            ->select('r.agente_id', DB::raw('SUM(r.total_calculado) as total_comisiones'))
            ->get()
            ->keyBy('agente_id');

        $filename = "agentes_{$mes}.csv";
        $headers  = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($agentes, $comisionesRaw, $mes) {
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, ['ID', 'Nombres', 'DNI', 'Tienda', 'Estado', 'Gerencia', 'Fecha Ingreso', "Comisiones {$mes}"]);

            foreach ($agentes as $a) {
                $comision = $comisionesRaw[$a->id]->total_comisiones ?? 0;
                fputcsv($output, [
                    $a->id,
                    $a->nombres,
                    $a->dni,
                    $a->tienda_nombre,
                    $a->estado,
                    $a->es_gerencia ? 'Sí' : 'No',
                    $a->fecha_ingreso ?? '',
                    number_format((float)$comision, 2),
                ]);
            }
            fclose($output);
        };

        return response()->stream($callback, 200, $headers);
    }
}
