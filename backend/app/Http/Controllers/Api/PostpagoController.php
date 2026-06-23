<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostpagoController extends Controller
{
    // ── Query base compartida (ventas postpago + joins) ─────────────────────────

    private function buildBase(Request $request)
    {
        $desde    = $request->input('desde');
        $hasta    = $request->input('hasta');
        $tiendaId = $request->input('tienda_id');
        $agenteId = $request->input('agente_id');
        $tipoAlta = $request->input('tipo_alta');

        return DB::table('ventas as v')
            ->join('reportes as r',      'r.id',      '=', 'v.reporte_id')
            ->join('venta_lineas as vl',  'vl.venta_id', '=', 'v.id')
            ->leftJoin('clientes as c',  'c.id',      '=', 'v.cliente_id')
            ->leftJoin('agentes as ag',  'ag.id',     '=', 'v.vendedor_id')
            ->where('v.tipo_venta', 'POSTPAGO')
            ->when($desde,    fn ($q) => $q->where('r.fecha', '>=', $desde))
            ->when($hasta,    fn ($q) => $q->where('r.fecha', '<=', $hasta))
            ->when($tiendaId, fn ($q) => $q->where('r.tienda_id', $tiendaId))
            ->when($agenteId, fn ($q) => $q->where('v.vendedor_id', $agenteId))
            ->when($tipoAlta, fn ($q) => $q->where('vl.tipo_alta', $tipoAlta))
            ->when($request->filled('es_remate'), fn ($q) => $q->where('v.es_remate', (int) $request->input('es_remate')));
    }

    private function seleccionColumnas(): array
    {
        return [
            'v.id',
            'r.fecha',
            'r.tienda_id',
            'c.nombre as cliente_nombre',
            'c.dni_ruc',
            'c.telefono as cliente_telefono',
            'ag.nombres as agente_nombres',
            'vl.plan_nombre_snap',
            'vl.tipo_alta',
            'vl.cantidad',
            'vl.cobrado_unitario',
            'vl.es_migracion',
            'vl.es_upgrade',
            'vl.es_esim',
            'v.comision_generada',
            'v.comision_estado',
            'v.es_remate',
            'v.monto_total',
        ];
    }

    // ── GET /postpago/resumen — KPIs + tendencia + top planes ──────────────────

    public function resumen(Request $request): JsonResponse
    {
        $q = $this->buildBase($request);

        $totales = (clone $q)->selectRaw("
            COALESCE(SUM(vl.cantidad), 0)                                                          AS total_activaciones,
            COALESCE(SUM(CASE WHEN vl.tipo_alta = 'PORTABILIDAD' THEN vl.cantidad ELSE 0 END), 0) AS portabilidades,
            COALESCE(SUM(CASE WHEN vl.tipo_alta = 'ALTA_NUEVA'   THEN vl.cantidad ELSE 0 END), 0) AS altas_nuevas,
            COALESCE(SUM(CASE WHEN vl.tipo_alta = 'RENOVACION'   THEN vl.cantidad ELSE 0 END), 0) AS renovaciones,
            COALESCE(SUM(CASE WHEN vl.tipo_alta = 'UPGRADE'      THEN vl.cantidad ELSE 0 END), 0) AS upgrades,
            COALESCE(SUM(CASE WHEN vl.tipo_alta = 'PAQUETE'      THEN vl.cantidad ELSE 0 END), 0) AS paquetes,
            COALESCE(SUM(CASE WHEN v.es_remate  = 1              THEN 1           ELSE 0 END), 0) AS remates,
            COALESCE(SUM(CASE WHEN v.comision_estado = 'ACTIVA'  THEN v.comision_generada ELSE 0 END), 0) AS comision_activa,
            COALESCE(SUM(CASE WHEN v.comision_estado = 'ANULADA' THEN v.comision_generada ELSE 0 END), 0) AS comision_anulada,
            COALESCE(SUM(v.monto_total), 0)                                                        AS monto_total
        ")->first();

        $porTipo = (clone $q)
            ->selectRaw("vl.tipo_alta, COALESCE(SUM(vl.cantidad), 0) as total")
            ->groupBy('vl.tipo_alta')
            ->orderByDesc('total')
            ->get();

        $topPlanes = (clone $q)
            ->selectRaw("vl.plan_nombre_snap as plan, COALESCE(SUM(vl.cantidad), 0) as total")
            ->groupBy('vl.plan_nombre_snap')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $tendencia = (clone $q)
            ->selectRaw("
                r.fecha,
                COALESCE(SUM(vl.cantidad), 0)                                                          AS activaciones,
                COALESCE(SUM(CASE WHEN vl.tipo_alta = 'PORTABILIDAD' THEN vl.cantidad ELSE 0 END), 0) AS portabilidades,
                COALESCE(SUM(CASE WHEN v.es_remate = 1               THEN 1           ELSE 0 END), 0) AS remates
            ")
            ->groupBy('r.fecha')
            ->orderBy('r.fecha')
            ->get();

        return response()->json([
            'totales'    => $totales,
            'por_tipo'   => $porTipo,
            'top_planes' => $topPlanes,
            'tendencia'  => $tendencia,
        ]);
    }

    // ── GET /postpago/ventas — Lista paginada con filtros ──────────────────────

    public function ventas(Request $request): JsonResponse
    {
        $perPage = min((int) ($request->per_page ?? 50), 200);

        $rows = $this->buildBase($request)
            ->select($this->seleccionColumnas())
            ->orderByDesc('r.fecha')
            ->orderByDesc('v.id')
            ->paginate($perPage);

        return response()->json($rows);
    }

    // ── GET /postpago/exportar — XLSX con los mismos filtros ──────────────────

    public function exportar(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = $this->buildBase($request)
            ->select($this->seleccionColumnas())
            ->orderByDesc('r.fecha')
            ->orderByDesc('v.id')
            ->get();

        $headers = [
            '#Venta', 'Fecha', 'Tienda', 'Cliente', 'DNI/RUC', 'Teléfono', 'Agente',
            'Plan', 'Tipo Alta', 'Cant.', 'Cobrado S/', 'Monto Total S/',
            'Comisión S/', 'Estado Com.', '¿Remate?', 'eSIM', 'Migración', 'Upgrade',
        ];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet()->setTitle('Postpago');
        $sheet->fromArray($headers, null, 'A1');

        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$col}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A1A2E']],
        ]);

        foreach ($rows as $i => $r) {
            $sheet->fromArray([
                '#'.str_pad((string) $r->id, 6, '0', STR_PAD_LEFT),
                $r->fecha,
                $r->tienda_id,
                $r->cliente_nombre ?? '—',
                $r->dni_ruc        ?? '—',
                $r->cliente_telefono ?? '—',
                $r->agente_nombres ?? '—',
                $r->plan_nombre_snap,
                $r->tipo_alta,
                (int) $r->cantidad,
                (float) $r->cobrado_unitario,
                (float) $r->monto_total,
                (float) $r->comision_generada,
                $r->comision_estado,
                $r->es_remate  ? 'SÍ' : 'NO',
                $r->es_esim    ? 'SÍ' : 'NO',
                $r->es_migracion ? 'SÍ' : 'NO',
                $r->es_upgrade   ? 'SÍ' : 'NO',
            ], null, 'A'.($i + 2));
        }

        for ($c = 1; $c <= count($headers); $c++) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c)
            )->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        return response()->streamDownload(
            static function () use ($spreadsheet) {
                (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'postpago_'.date('Y-m-d').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
