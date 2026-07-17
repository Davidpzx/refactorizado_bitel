<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reporte;
use App\Models\Usuario;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistorialController extends Controller
{
    /** Filtros comunes a index(), kpis() y exportar(): fecha, tienda, agente, estado. */
    private function baseQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = Reporte::query();

        if (! $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            $query->where('reportes.tienda_id', $user->tienda_id);
        } elseif ($request->tienda) {
            $query->where('reportes.tienda_id', $request->tienda);
        }

        return $query
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereDate('reportes.fecha', '>=', $f))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereDate('reportes.fecha', '<=', $f))
            ->when($request->agente_id,   fn ($q, $v) => $q->where('reportes.agente_id', $v))
            ->when($request->estado,      fn ($q, $v) => $q->where('reportes.estado', $v));
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = Paginacion::desde($request, 15);
        $user    = $request->user();

        $query = $this->baseQuery($request)
            ->leftJoin('agentes as a', 'reportes.agente_id', '=', 'a.id')
            ->select([
                'reportes.*',
                DB::raw("COALESCE(a.nombres, 'Agente') AS agente_nombre"),
            ]);

        // Ganancia por fila (paridad legacy gerencia/historial_completo.php), solo admin.
        if ($user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            $gananciaSub = DB::table('ventas as v')
                ->leftJoin('venta_equipos as ve', 've.venta_id', '=', 'v.id')
                ->leftJoin('venta_lineas as vl', 'vl.venta_id', '=', 'v.id')
                ->whereColumn('v.reporte_id', 'reportes.id')
                ->where('v.comision_estado', '!=', 'ANULADA')
                ->selectRaw('
                    COALESCE(SUM(COALESCE(ve.ganancia_snap, 0)), 0)
                    + COALESCE(SUM(COALESCE(vl.comision_unitaria * vl.cantidad, 0)), 0)
                ');

            $query->selectSub($gananciaSub, 'ganancia');
        }

        $reportes = $query
            ->orderByDesc('reportes.fecha')
            ->orderByDesc('reportes.id')
            ->paginate($perPage);

        return response()->json($reportes);
    }

    /** KPIs globales del período filtrado (paridad con el panel de totales del historial legacy). */
    public function kpis(Request $request): JsonResponse
    {
        $user = $request->user();

        $totales = $this->baseQuery($request)->toBase()->selectRaw("
            COALESCE(SUM(reportes.total_calculado), 0)    AS total_general,
            COALESCE(SUM(reportes.efectivo_esperado), 0)  AS fisico_esperado,
            COALESCE(SUM(reportes.efectivo_entregado), 0) AS fisico_declarado,
            COALESCE(SUM(reportes.diferencia), 0)         AS diferencia_fisica,
            COALESCE(SUM(reportes.yape), 0)               AS total_yape,
            COALESCE(SUM(reportes.bipay), 0)              AS total_bipay,
            COALESCE(SUM(reportes.transferencia), 0)      AS total_transferencia,
            COUNT(reportes.id)                            AS total_reportes
        ")->first();

        $gananciaTotal = null;
        if ($user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            $ids = $this->baseQuery($request)->pluck('reportes.id');
            $gananciaTotal = DB::table('ventas as v')
                ->leftJoin('venta_equipos as ve', 've.venta_id', '=', 'v.id')
                ->leftJoin('venta_lineas as vl', 'vl.venta_id', '=', 'v.id')
                ->whereIn('v.reporte_id', $ids)
                ->where('v.comision_estado', '!=', 'ANULADA')
                ->selectRaw('
                    COALESCE(SUM(COALESCE(ve.ganancia_snap, 0)), 0)
                    + COALESCE(SUM(COALESCE(vl.comision_unitaria * vl.cantidad, 0)), 0)
                    AS total_ganancia
                ')
                ->value('total_ganancia') ?? 0;
        }

        return response()->json([
            'totales'        => $totales,
            'ganancia_total' => $gananciaTotal,
        ]);
    }

    /**
     * Exportación XLSX real (reemplaza el CSV anterior), agrupada por bloques de categoría
     * — paridad con gerencia/historial_completo.php del sistema legacy (postpago, prepago,
     * equipos/accesorios, servicios, apoyos inter-tienda, salidas), con subtotales por bloque.
     */
    public function exportar(Request $request): StreamedResponse
    {
        $reportes = $this->baseQuery($request)
            ->leftJoin('agentes as a', 'reportes.agente_id', '=', 'a.id')
            ->select([
                'reportes.*',
                DB::raw("COALESCE(a.nombres, 'Agente') AS agente_nombre"),
            ])
            ->with(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas'])
            ->orderBy('reportes.fecha')
            ->orderBy('reportes.id')
            ->get();

        $bloques = [
            'postpago'  => ['label' => 'SECCIÓN 1: LÍNEAS POSTPAGO',          'items' => []],
            'prepago'   => ['label' => 'SECCIÓN 2: CHIPS PREPAGO',            'items' => []],
            'equipos'   => ['label' => 'SECCIÓN 3: EQUIPOS Y ACCESORIOS',     'items' => []],
            'servicios' => ['label' => 'SECCIÓN 4: PAGOS, RECARGAS Y OTROS',  'items' => []],
            'apoyo'     => ['label' => 'SECCIÓN 5: APOYOS INTER-TIENDA',      'items' => []],
            'salidas'   => ['label' => 'SECCIÓN 6: SALIDAS Y GASTOS',         'items' => []],
        ];

        $nombresServicio = [
            'recarga_bipay'  => 'Recarga Bipay',
            'pago_servicio'  => 'Pago de Servicio',
            'pago_krece'     => 'Pago Krece',
            'pago_payjoy'    => 'Pago Payjoy',
            'tickets_tusamy' => 'Tickets Tusamy',
        ];

        foreach ($reportes as $r) {
            $idStr  = '#' . str_pad((string) $r->id, 4, '0', STR_PAD_LEFT);
            $fecha  = $r->fecha instanceof \DateTimeInterface ? $r->fecha->format('d/m/Y') : date('d/m/Y', strtotime((string) $r->fecha));

            foreach ($r->ventas as $venta) {
                if ($venta->comision_estado === 'ANULADA') {
                    continue;
                }

                $esApoyo = $venta->cross_selling || $venta->tipo_venta === 'APOYO';

                if (in_array($venta->tipo_venta, ['POSTPAGO', 'PREPAGO', 'APOYO'], true)) {
                    $bloqueKey = $esApoyo ? 'apoyo' : ($venta->tipo_venta === 'POSTPAGO' ? 'postpago' : 'prepago');
                    $bloques[$bloqueKey]['items'][] = [
                        'cuadre' => $idStr, 'fecha' => $fecha,
                        'tienda' => $r->tienda_id, 'agente' => $r->agente_nombre,
                        'producto' => $venta->linea->plan_nombre_snap ?? ($venta->subtipo ?: 'Plan'),
                        'tipo'     => $venta->linea->tipo_alta ?? $venta->tipo_venta,
                        'cantidad' => $venta->linea->cantidad ?? 1,
                        'monto'    => (float) $venta->monto_total,
                        'comision' => (float) $venta->comision_generada,
                        'destino'  => $venta->tienda_destino ?? '—',
                        'dni'      => $venta->cliente->dni_ruc ?? '—',
                        'imei'     => '—',
                    ];
                } elseif (in_array($venta->tipo_venta, ['EQUIPO', 'ACCESORIO'], true)) {
                    $bloques['equipos']['items'][] = [
                        'cuadre' => $idStr, 'fecha' => $fecha,
                        'tienda' => $r->tienda_id, 'agente' => $r->agente_nombre,
                        'producto' => $venta->equipo->producto_nombre_snap ?? '—',
                        'tipo'     => $venta->equipo->tipo_item ?? $venta->tipo_venta,
                        'cantidad' => 1,
                        'monto'    => (float) $venta->monto_total,
                        'comision' => (float) $venta->comision_generada,
                        'destino'  => '—',
                        'dni'      => $venta->cliente->dni_ruc ?? '—',
                        'imei'     => $venta->equipo->imei_serial_snap ?? '—',
                    ];
                } elseif ($venta->tipo_venta === 'OTROS_FLUJO') {
                    $bloques['servicios']['items'][] = [
                        'cuadre' => $idStr, 'fecha' => $fecha,
                        'tienda' => $r->tienda_id, 'agente' => $r->agente_nombre,
                        'producto' => 'Otros Ingresos: ' . ($venta->subtipo ?: '—'),
                        'tipo'     => 'FLUJO', 'cantidad' => 1,
                        'monto'    => (float) $venta->monto_total, 'comision' => 0,
                        'destino'  => '—', 'dni' => '—', 'imei' => '—',
                    ];
                }
            }

            // Montos agregados de servicios (yape/bipay no van aquí: ya se ven en las tarjetas de dinero digital).
            foreach ($nombresServicio as $campo => $label) {
                $monto = (float) ($r->{$campo} ?? 0);
                if ($monto != 0) {
                    $bloques['servicios']['items'][] = [
                        'cuadre' => $idStr, 'fecha' => $fecha,
                        'tienda' => $r->tienda_id, 'agente' => $r->agente_nombre,
                        'producto' => $label, 'tipo' => strtoupper($campo),
                        'cantidad' => 1, 'monto' => $monto, 'comision' => 0,
                        'destino' => '—', 'dni' => '—', 'imei' => '—',
                    ];
                }
            }

            foreach ($r->salidas as $s) {
                $bloques['salidas']['items'][] = [
                    'cuadre' => $idStr, 'fecha' => $fecha,
                    'tienda' => $r->tienda_id, 'agente' => $r->agente_nombre,
                    'producto' => strtoupper((string) $s->tipo), 'tipo' => 'SALIDA',
                    'cantidad' => 1, 'monto' => -(float) $s->monto, 'comision' => 0,
                    'destino' => '—', 'dni' => '—', 'imei' => $s->observacion ?: '—',
                ];
            }
        }

        $headers = ['Cuadre', 'Fecha', 'Tienda', 'Agente', 'Producto / Plan', 'Tipo', 'IMEI/Serie', 'Cant.', 'Monto S/', 'Comisión S/', 'Destino', 'DNI Cliente', 'Observación'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Historial Global');
        $ultimaCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));

        $fila = 1;
        $sheet->setCellValue("A{$fila}", 'HISTORIAL GLOBAL DE CUADRES — ' . count($reportes) . ' reportes incluidos');
        $sheet->mergeCells("A{$fila}:{$ultimaCol}{$fila}");
        $sheet->getStyle("A{$fila}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFD700']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A0A23']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $fila += 2;

        foreach ($bloques as $bloque) {
            $sheet->setCellValue("A{$fila}", $bloque['label']);
            $sheet->mergeCells("A{$fila}:{$ultimaCol}{$fila}");
            $sheet->getStyle("A{$fila}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A1A2E']],
            ]);
            $fila++;

            $sheet->fromArray($headers, null, "A{$fila}");
            $sheet->getStyle("A{$fila}:{$ultimaCol}{$fila}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'E0E0FF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2C2C54']],
            ]);
            $fila++;

            if (empty($bloque['items'])) {
                $sheet->setCellValue("A{$fila}", 'Sin registros en este bloque para el período seleccionado.');
                $sheet->mergeCells("A{$fila}:{$ultimaCol}{$fila}");
                $fila++;
            } else {
                $subtotalMonto    = 0;
                $subtotalComision = 0;
                foreach ($bloque['items'] as $it) {
                    $subtotalMonto    += $it['monto'];
                    $subtotalComision += $it['comision'];
                    $sheet->fromArray([
                        $it['cuadre'], $it['fecha'], $it['tienda'], $it['agente'],
                        $it['producto'], $it['tipo'], $it['imei'], $it['cantidad'],
                        round($it['monto'], 2), round($it['comision'], 2), $it['destino'], $it['dni'], '',
                    ], null, "A{$fila}");
                    $fila++;
                }
                $sheet->setCellValue("A{$fila}", 'SUBTOTAL (' . count($bloque['items']) . ' registros)');
                $sheet->mergeCells("A{$fila}:H{$fila}");
                $sheet->setCellValue("I{$fila}", round($subtotalMonto, 2));
                $sheet->setCellValue("J{$fila}", round($subtotalComision, 2));
                $sheet->getStyle("A{$fila}:{$ultimaCol}{$fila}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEEEEE']],
                ]);
                $fila++;
            }
            $fila++; // separación entre bloques
        }

        for ($col = 1; $col <= count($headers); $col++) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col)
            )->setAutoSize(true);
        }

        return response()->streamDownload(
            static function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'Historial_Global_' . date('Y-m-d') . '.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
