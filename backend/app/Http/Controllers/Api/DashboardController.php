<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                ->where('v.comision_estado', '!=', 'ANULADA')
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

    /**
     * Export XLSX general del dashboard (paridad con panel_gerencia.php "EXPORTACIÓN GENERAL A EXCEL"):
     * una fila por venta/servicio, sin el LIMIT 5 de kpis(). Admin-only (ver spec: el sub-rol
     * jefe_tienda-vía-PIN del legacy no existe como gate en el refactor; T1.2 pendiente).
     */
    /** Ventana máxima cuando se exporta sin fecha_desde/fecha_hasta, para acotar el volumen en memoria. */
    private const EXPORT_DIAS_MAX_SIN_FILTRO = 90;

    public function exportar(Request $request): StreamedResponse
    {
        $reportes = Reporte::query()
            ->where('reportes.estado', '!=', 'borrador')
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereDate('reportes.fecha', '>=', $f))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereDate('reportes.fecha', '<=', $f))
            ->when(
                ! $request->fecha_desde && ! $request->fecha_hasta,
                fn ($q) => $q->whereDate('reportes.fecha', '>=', now()->subDays(self::EXPORT_DIAS_MAX_SIN_FILTRO)->toDateString())
            )
            ->when($request->tienda, fn ($q, $t) => $q->where('reportes.tienda_id', $t))
            ->leftJoin('agentes as a', 'reportes.agente_id', '=', 'a.id')
            ->select(['reportes.*', DB::raw("COALESCE(a.nombres, 'Agente') AS agente_nombre")])
            ->with(['ventas.equipo', 'ventas.linea', 'ventas.cliente'])
            ->orderBy('reportes.fecha')
            ->orderBy('reportes.id')
            ->get();

        $nombresServicio = [
            'recarga_bipay'  => 'Recarga Bipay',
            'pago_servicio'  => 'Pago de Servicio',
            'pago_krece'     => 'Pago Krece',
            'pago_payjoy'    => 'Pago Payjoy',
            'tickets_tusamy' => 'Tickets Tusamy',
        ];

        $headers = ['ID Cuadre', 'Fecha', 'Tienda', 'Agente', 'Categoría', 'Producto / Plan', 'Atributos / Detalles', 'Monto Ingresado S/', 'Destino Efectivo Físico'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ventas Desglosado');
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFCC00']],
        ]);

        $fila = 2;
        foreach ($reportes as $r) {
            $idStr  = '#' . str_pad((string) $r->id, 4, '0', STR_PAD_LEFT);
            $fecha  = $r->fecha instanceof \DateTimeInterface ? $r->fecha->format('d/m/Y') : date('d/m/Y', strtotime((string) $r->fecha));
            $destino = $r->destino_efectivo ?? 'TIENDA';

            $filasReporte = [];

            foreach ($r->ventas as $venta) {
                if ($venta->comision_estado === 'ANULADA') {
                    continue;
                }

                if (in_array($venta->tipo_venta, ['POSTPAGO', 'PREPAGO', 'APOYO'], true)) {
                    $l = $venta->linea;
                    $categoria = $venta->tipo_venta . ($venta->cross_selling ? ' (APOYO)' : '');
                    $filasReporte[] = [
                        $categoria,
                        $l?->plan_nombre_snap ?? 'Plan',
                        'Tipo Alta: ' . ($l?->tipo_alta ?? '—') . ' | Cant: ' . ($l?->cantidad ?? 1)
                            . ($venta->cliente ? ' | DNI: ' . $venta->cliente->dni_ruc : ''),
                        (float) $venta->monto_total,
                    ];
                } elseif (in_array($venta->tipo_venta, ['EQUIPO', 'ACCESORIO'], true)) {
                    $e = $venta->equipo;
                    $filasReporte[] = [
                        $venta->tipo_venta,
                        $e?->producto_nombre_snap ?? 'Producto',
                        $e?->imei_serial_snap ? 'IMEI/Serie: ' . $e->imei_serial_snap : 'Sin IMEI',
                        (float) $venta->monto_total,
                    ];
                } elseif ($venta->tipo_venta === 'OTROS_FLUJO') {
                    $filasReporte[] = ['OTROS FLUJO', 'Otros Ingresos: ' . ($venta->subtipo ?: '—'), '-', (float) $venta->monto_total];
                }
            }

            foreach ($nombresServicio as $campo => $label) {
                $monto = (float) ($r->{$campo} ?? 0);
                if ($monto != 0) {
                    $filasReporte[] = [$label, $label, '-', $monto];
                }
            }

            if (empty($filasReporte)) {
                $filasReporte[] = ['SIN VENTAS', 'Ninguno', '-', 0.0];
            }

            foreach ($filasReporte as [$categoria, $producto, $atributos, $monto]) {
                $sheet->fromArray([
                    $idStr, $fecha, $r->tienda_id, $r->agente_nombre,
                    $categoria, $producto, $atributos, round($monto, 2), $destino,
                ], null, "A{$fila}");
                $fila++;
            }
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        $desde = $request->fecha_desde ?: 'todas';
        $hasta = $request->fecha_hasta ?: 'todas';

        return response()->streamDownload(
            static function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            "Reporte_Ventas_Desglosado_{$desde}_a_{$hasta}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
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
