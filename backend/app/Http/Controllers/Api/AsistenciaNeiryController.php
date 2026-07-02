<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plantilla Neiry: matriz mensual de marcaciones (E/SR/RR/S por día) agrupada
 * por tienda, con tipo de turno y horas totales por agente. Puerto de
 * gerencia/exportar_asistencias_neiry.php del legacy sis_bipay.
 */
class AsistenciaNeiryController extends Controller
{
    public function exportar(Request $request): StreamedResponse
    {
        $mes = trim((string) $request->query('mes', ''));
        if (! preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $mes = now()->format('Y-m');
        }

        $fechaIni = $mes . '-01';
        $fechaFin = date('Y-m-t', strtotime($fechaIni));
        $dias     = (int) date('t', strtotime($fechaIni));

        $rows = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$fechaIni, $fechaFin])
            ->orderBy('ag.tienda_base')->orderBy('ag.nombres')->orderBy('a.fecha')
            ->get(['a.*', 'ag.nombres', 'ag.tienda_base']);

        // Agrupar por agente → día
        $porAgente = [];
        foreach ($rows as $r) {
            $aid = $r->agente_id;
            $dia = (int) date('j', strtotime($r->fecha));
            $porAgente[$aid] ??= ['nombre' => $r->nombres, 'tienda' => $r->tienda_base ?? '—', 'dias' => []];
            $porAgente[$aid]['dias'][$dia] = $r;
        }

        // Incluir agentes activos sin marcaciones (ausentes totales)
        foreach (DB::table('agentes')->where('estado', 'ACTIVO')->orderBy('tienda_base')->orderBy('nombres')->get(['id', 'nombres', 'tienda_base']) as $ag) {
            $porAgente[$ag->id] ??= ['nombre' => $ag->nombres, 'tienda' => $ag->tienda_base ?? '—', 'dias' => []];
        }

        // Excepciones de jornada (MEDIO_TIEMPO) para Tipo Turno
        $excMap = [];
        if (! empty($porAgente) && Schema::hasTable('excepciones_jornada')) {
            $exc = DB::table('excepciones_jornada')
                ->whereIn('agente_id', array_keys($porAgente))
                ->whereBetween('fecha', [$fechaIni, $fechaFin])
                ->get(['agente_id', 'fecha', 'tipo']);
            foreach ($exc as $e) {
                $excMap[$e->agente_id][substr((string) $e->fecha, 0, 10)] = $e->tipo;
            }
        }

        // Tipo de turno y horas totales por agente
        foreach ($porAgente as $aid => &$datos) {
            $totalMin = 0;
            $diasMt = 0;
            $diasCon = 0;
            foreach ($datos['dias'] as $dia => $asis) {
                $fec = $mes . '-' . str_pad((string) $dia, 2, '0', STR_PAD_LEFT);
                if (($excMap[$aid][$fec] ?? null) === 'MEDIO_TIEMPO') {
                    $diasMt++;
                }
                $diasCon++;

                if ($asis->hora_ingreso && $asis->hora_salida) {
                    $bruto = (strtotime($asis->hora_salida) - strtotime($asis->hora_ingreso)) / 60;
                    if ($asis->inicio_refrigerio && $asis->fin_refrigerio) {
                        $bruto -= (strtotime($asis->fin_refrigerio) - strtotime($asis->inicio_refrigerio)) / 60;
                    }
                    $totalMin += max(0, $bruto);
                }
            }
            $datos['horas_totales'] = $totalMin > 0
                ? sprintf('%dh %02dm', intdiv((int) $totalMin, 60), (int) $totalMin % 60) : '—';
            $datos['tipo_turno'] = ($diasCon > 0 && $diasMt >= ceil($diasCon / 2)) ? 'MEDIO TIEMPO' : 'COMPLETO';
        }
        unset($datos);

        // Ordenar por tienda + nombre y agrupar por tienda
        uasort($porAgente, fn ($a, $b) => strcmp($a['tienda'] . $a['nombre'], $b['tienda'] . $b['nombre']));
        $porTienda = [];
        foreach ($porAgente as $aid => $d) {
            $porTienda[$d['tienda']][$aid] = $d;
        }

        // ── Construir Excel ───────────────────────────────────────────────────
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Neiry ' . $mes);

        $totalCols = 3 + $dias + 1;
        $lastCol   = Coordinate::stringFromColumnIndex($totalCols);

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', sprintf('PLANTILLA NEIRY — %s (%d días · %d agentes)',
            strtoupper(date('F Y', strtotime($fechaIni))), $dias, count($porAgente)));
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FBBF24']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $encabezados = ['TIENDA', 'AGENTE', 'TIPO TURNO'];
        for ($d = 1; $d <= $dias; $d++) {
            $ts = strtotime($mes . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT));
            $encabezados[] = $d . ' ' . strtoupper(substr(date('D', $ts), 0, 2));
        }
        $encabezados[] = 'HORAS TOTALES';
        $sheet->fromArray($encabezados, null, 'A2');
        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '94A3B8']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
        ]);

        $fila = 3;
        foreach ($porTienda as $tiendaCod => $agentesT) {
            $sheet->mergeCells("A{$fila}:{$lastCol}{$fila}");
            $sheet->setCellValue("A{$fila}", (string) $tiendaCod);
            $sheet->getStyle("A{$fila}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'A5B4FC']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            ]);
            $fila++;

            foreach ($agentesT as $datos) {
                // Formato de nombre del legacy: "3er_nombre 1er_nombre" si existe
                $pn = explode(' ', trim($datos['nombre']));
                $nombreFmt = isset($pn[2]) ? $pn[2] . ' ' . $pn[0] : $datos['nombre'];

                $sheet->setCellValue("A{$fila}", $datos['tienda']);
                $sheet->setCellValue("B{$fila}", $nombreFmt);
                $sheet->setCellValue("C{$fila}", $datos['tipo_turno']);

                for ($d = 1; $d <= $dias; $d++) {
                    $col = Coordinate::stringFromColumnIndex(3 + $d);
                    $asis = $datos['dias'][$d] ?? null;
                    if ($asis) {
                        $celda = [];
                        foreach (['E' => 'hora_ingreso', 'SR' => 'inicio_refrigerio', 'RR' => 'fin_refrigerio', 'S' => 'hora_salida'] as $l => $campo) {
                            $celda[] = $l . ':' . ($asis->$campo ? date('H:i', strtotime($asis->$campo)) : '—');
                        }
                        $sheet->setCellValue("{$col}{$fila}", implode("\n", $celda));
                        $sheet->getStyle("{$col}{$fila}")->getAlignment()->setWrapText(true);
                        $sheet->getStyle("{$col}{$fila}")->applyFromArray([
                            'fill' => ['fillType' => Fill::FILL_SOLID,
                                'startColor' => ['rgb' => ((int) ($asis->minutos_tardanza ?? 0)) > 0 ? 'FEF2F2' : 'F0FDF4']],
                        ]);
                    } else {
                        $sheet->setCellValue("{$col}{$fila}", '✕');
                    }
                }

                $sheet->setCellValue($lastCol . $fila, $datos['horas_totales']);
                $sheet->getStyle($lastCol . $fila)->getFont()->setBold(true);
                $fila++;
            }
        }

        foreach (['A' => 12, 'B' => 24, 'C' => 14, $lastCol => 14] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        for ($d = 1; $d <= $dias; $d++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex(3 + $d))->setWidth(10);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, "Neiry_{$mes}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
