<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agente;
use App\Models\PlanillaAjuste;
use App\Support\RankingVentaScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanillaController extends Controller
{
    /**
     * GET /api/v1/planilla/{mes}
     * Devuelve la planilla CD08 calculada para todos los agentes activos.
     * mes = 'YYYY-MM'
     *
     * Fórmulas CD08:
     *   G = (F / 30) * días_trabajados
     *   L = G + H + I + J + K   (total_remuneracion)
     *   Q = M + N + O + P       (total_descuentos)
     *   R = L - Q               (total_pagar)
     */
    public function calcular(Request $request, string $mes): JsonResponse
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            return response()->json(['error' => 'Formato de mes inválido. Use YYYY-MM.'], 422);
        }

        return response()->json($this->construirPlanilla($mes));
    }

    /** Arma las filas + totales de la planilla del mes (reutilizado por JSON y export Excel). */
    private function construirPlanilla(string $mes): array
    {
        $fechaInicio = $mes . '-01';
        $fechaFin    = date('Y-m-t', strtotime($fechaInicio));

        // ── 1. Agentes activos (o con permiso largo) ─────────────────────────────
        $agentes = Agente::query()
            ->where(fn($q) => $q->where('estado', 'ACTIVO')->orWhere('permiso_largo', true))
            ->orderBy('tienda_base')
            ->orderBy('nombres')
            ->get(['id', 'dni', 'nombres', 'tienda_base', 'sueldo_base',
                   'es_gerencia', 'estado', 'permiso_largo', 'fecha_retorno']);

        $agentesIds = $agentes->pluck('id')->all();

        if (empty($agentesIds)) {
            return ['mes' => $mes, 'agentes' => [], 'totales' => $this->totalesVacios()];
        }

        // ── 2. Ajustes manuales del mes ─────────────────────────────────────────
        $ajustes = PlanillaAjuste::whereIn('agente_id', $agentesIds)
            ->where('mes', $mes)
            ->get()
            ->keyBy('agente_id');

        // ── 3. Comisiones AUTO desde tabla ventas (SUM en BD — sin N+1) ─────────
        $comisionesEquipo = $this->calcularComisionesEquipo($agentesIds, $fechaInicio, $fechaFin);
        $comisionesPlanes = $this->calcularComisionesPlanes($agentesIds, $fechaInicio, $fechaFin);
        $comisionesOnline = $this->calcularComisionesOnline($agentesIds, $fechaInicio, $fechaFin);

        // ── 4. Asistencias: tardanzas y faltas (carga en lote) ──────────────────
        [$tardanzasAuto, $faltasAuto] = $this->calcularAsistencias($agentesIds, $fechaInicio, $fechaFin, $agentes);

        // ── 5. Adelantos del mes (carga en lote) ────────────────────────────────
        $adelantos = $this->cargarAdelantos($agentesIds, $fechaInicio, $fechaFin);

        // ── 6. Aplicar fórmulas CD08 por agente ─────────────────────────────────
        $filas   = [];
        $totales = $this->totalesVacios();

        foreach ($agentes as $ag) {
            $ajuste   = $ajustes->get($ag->id);
            $override = $ajuste?->override_comisiones ?? false;

            $diasTrabajados    = floatval($ajuste?->dias_trabajados ?? 30.0);
            $comisionJefe      = floatval($ajuste?->comision_jefe ?? 0);
            $comisionEquipo    = $override ? floatval($ajuste->comision_equipo) : ($comisionesEquipo[$ag->id] ?? 0.0);
            $comisionPlanes    = $override ? floatval($ajuste->comision_planes) : ($comisionesPlanes[$ag->id] ?? 0.0);
            $comisionOnline    = $override ? floatval($ajuste->comision_online)  : ($comisionesOnline[$ag->id] ?? 0.0);
            $retUniforme       = floatval($ajuste?->retencion_uniforme ?? 0);
            $tieneAjuste       = $ajuste !== null;

            // Tardanzas y faltas: auto desde asistencias si no hay ajuste guardado
            $tardanzas         = $tieneAjuste ? floatval($ajuste->tardanzas)      : ($tardanzasAuto[$ag->id] ?? 0.0);
            $faltasPermisos    = $tieneAjuste ? floatval($ajuste->faltas_permisos): ($faltasAuto[$ag->id]    ?? 0.0);

            // Faltante efectivo base + adelantos del mes
            $faltanteBase      = floatval($ajuste?->faltante_efectivo ?? 0);
            $adelanto          = $adelantos[$ag->id] ?? 0.0;
            $faltanteEfectivo  = $faltanteBase + $adelanto;

            $sueldoBase = floatval($ag->sueldo_base);

            // G = (F / 30) * días_trabajados
            $sueldoDiasLab = ($sueldoBase / 30.0) * $diasTrabajados;

            // L = G + H + I + J + K
            $totalRemuneracion = $sueldoDiasLab + $comisionJefe + $comisionEquipo + $comisionPlanes + $comisionOnline;

            // Q = M + N + O + P
            $totalDescuentos = $retUniforme + $faltasPermisos + $tardanzas + $faltanteEfectivo;

            // R = L - Q
            $totalPagar = $totalRemuneracion - $totalDescuentos;

            $fila = [
                'agente_id'          => $ag->id,
                'dni'                => $ag->dni,
                'nombres'            => $ag->nombres,
                'tienda_base'        => $ag->tienda_base,
                'cargo'              => $ag->es_gerencia ? 'JEFE DE TIENDA' : 'AGENTE DE VENTAS',
                'estado'             => $ag->permiso_largo ? 'PERMISO_LARGO' : $ag->estado,
                'fecha_retorno'      => $ag->fecha_retorno,
                'sueldo_base'        => round($sueldoBase, 2),
                'dias_trabajados'    => round($diasTrabajados, 1),
                'sueldo_dias_lab'    => round($sueldoDiasLab, 2),
                'comision_jefe'      => round($comisionJefe, 2),
                'comision_equipo'    => round($comisionEquipo, 2),
                'comision_planes'    => round($comisionPlanes, 2),
                'comision_online'    => round($comisionOnline, 2),
                'total_remuneracion' => round($totalRemuneracion, 2),
                'retencion_uniforme' => round($retUniforme, 2),
                'faltas_permisos'    => round($faltasPermisos, 2),
                'tardanzas'          => round($tardanzas, 2),
                'faltante_efectivo'  => round($faltanteEfectivo, 2),
                'adelanto_incluido'  => round($adelanto, 2),
                'total_descuentos'   => round($totalDescuentos, 2),
                'total_pagar'        => round($totalPagar, 2),
                'override_comisiones'=> $override,
                'tiene_ajuste'       => $tieneAjuste,
                'auto_equipo'        => round($comisionesEquipo[$ag->id] ?? 0.0, 2),
                'auto_planes'        => round($comisionesPlanes[$ag->id] ?? 0.0, 2),
                'auto_online'        => round($comisionesOnline[$ag->id] ?? 0.0, 2),
                'notas'              => $ajuste?->notas,
            ];

            $filas[] = $fila;

            $totales['sueldo_base']        += $sueldoBase;
            $totales['sueldo_dias_lab']    += $sueldoDiasLab;
            $totales['total_remuneracion'] += $totalRemuneracion;
            $totales['total_descuentos']   += $totalDescuentos;
            $totales['total_pagar']        += $totalPagar;
            $totales['com_planes']         += $comisionPlanes;
            $totales['com_equipo']         += $comisionEquipo;
            $totales['com_online']         += $comisionOnline;
            $totales['adelantos']          += $adelanto;
        }

        // Redondear totales
        foreach ($totales as $k => $v) {
            $totales[$k] = round($v, 2);
        }

        return [
            'mes'     => $mes,
            'agentes' => $filas,
            'totales' => $totales,
        ];
    }

    /**
     * GET /api/v1/planilla/{mes}/exportar
     * Exporta la planilla del mes a .xlsx (PhpSpreadsheet) para importación bancaria/contable.
     */
    public function exportarExcel(Request $request, string $mes): StreamedResponse
    {
        abort_unless((bool) preg_match('/^\d{4}-\d{2}$/', $mes), 422, 'Formato de mes inválido. Use YYYY-MM.');

        $planilla = $this->construirPlanilla($mes);
        $filas    = $planilla['agentes'];
        $totales  = $planilla['totales'];

        $cabeceras = [
            'N°', 'DNI', 'NOMBRES', 'TIENDA', 'CARGO', 'ESTADO', 'SUELDO BASE', 'DÍAS TRAB.',
            'SUELDO X DÍAS', 'COM. JEFE', 'COM. EQUIPO', 'COM. PLANES', 'COM. ONLINE',
            'TOTAL REMUNERACIÓN', 'RET. UNIFORME', 'FALTAS/PERMISOS', 'TARDANZAS',
            'FALTANTE EFECTIVO', 'ADELANTO', 'TOTAL DESCUENTOS', 'TOTAL A PAGAR',
        ];
        $ultimaCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cabeceras));

        $ss    = new Spreadsheet();
        $hoja  = $ss->getActiveSheet();
        $hoja->setTitle('Planilla ' . $mes);

        // Título
        $hoja->setCellValue('A1', 'PLANILLA DE PAGOS — ' . $mes);
        $hoja->mergeCells("A1:{$ultimaCol}1");
        $hoja->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Cabeceras
        foreach ($cabeceras as $i => $label) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $hoja->setCellValue("{$col}2", $label);
        }
        $hoja->getStyle("A2:{$ultimaCol}2")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Filas
        $fila = 3;
        foreach ($filas as $i => $f) {
            $valores = [
                $i + 1, $f['dni'], $f['nombres'], $f['tienda_base'], $f['cargo'], $f['estado'],
                $f['sueldo_base'], $f['dias_trabajados'], $f['sueldo_dias_lab'],
                $f['comision_jefe'], $f['comision_equipo'], $f['comision_planes'], $f['comision_online'],
                $f['total_remuneracion'], $f['retencion_uniforme'], $f['faltas_permisos'], $f['tardanzas'],
                $f['faltante_efectivo'], $f['adelanto_incluido'], $f['total_descuentos'], $f['total_pagar'],
            ];
            foreach ($valores as $c => $v) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1);
                $hoja->setCellValue("{$col}{$fila}", $v);
            }
            $fila++;
        }

        // Totales
        $hoja->setCellValue("F{$fila}", 'TOTALES');
        $hoja->setCellValue("G{$fila}", $totales['sueldo_base']);
        $hoja->setCellValue("N{$fila}", $totales['total_remuneracion']);
        $hoja->setCellValue("T{$fila}", $totales['total_descuentos']);
        $hoja->setCellValue("U{$fila}", $totales['total_pagar']);
        $hoja->getStyle("A{$fila}:{$ultimaCol}{$fila}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE2E8F0']],
        ]);

        // Formato numérico (columnas G..U) y autosize
        $hoja->getStyle("G3:U{$fila}")->getNumberFormat()->setFormatCode('#,##0.00');
        for ($c = 1; $c <= count($cabeceras); $c++) {
            $hoja->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        $nombre = "planilla_{$mes}.xlsx";

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $nombre, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * POST /api/v1/planilla/ajuste
     * Guarda o actualiza un ajuste manual de planilla.
     */
    public function guardarAjuste(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agente_id'          => ['required', 'integer', 'exists:agentes,id'],
            'mes'                => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'campo'              => ['required', 'string', 'in:dias_trabajados,comision_jefe,comision_equipo,comision_planes,comision_online,retencion_uniforme,faltas_permisos,tardanzas,faltante_efectivo,notas'],
            'valor'              => ['required'],
            'set_override'       => ['sometimes', 'boolean'],
        ]);

        $camposComision = ['comision_equipo', 'comision_planes', 'comision_online'];
        $setOverride    = $validated['set_override'] ?? false;

        $data = [$validated['campo'] => $validated['valor']];
        if ($setOverride && in_array($validated['campo'], $camposComision)) {
            $data['override_comisiones'] = true;
        }

        PlanillaAjuste::updateOrInsert(
            ['agente_id' => $validated['agente_id'], 'mes' => $validated['mes']],
            $data
        );

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/planilla/ajuste/reset-comisiones
     * Resetea el override de comisiones para un agente/mes.
     */
    public function resetarComisiones(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agente_id' => ['required', 'integer', 'exists:agentes,id'],
            'mes'       => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        PlanillaAjuste::updateOrInsert(
            ['agente_id' => $validated['agente_id'], 'mes' => $validated['mes']],
            ['override_comisiones' => false, 'comision_equipo' => 0, 'comision_planes' => 0, 'comision_online' => 0]
        );

        return response()->json(['ok' => true]);
    }

    // ── Métodos privados de cálculo ──────────────────────────────────────────────

    /**
     * Comisión de equipos: SUM(comision_generada) por vendedor, tipo EQUIPO,
     * con fallback plano a EQUIPO_ESTANDAR (config, default S/5) si la comisión está en 0.
     *
     * Paridad legacy (gerencia/planilla_agentes.php PASO B EQUIPOS y ver_agente.php:217-219):
     * el equipo NUNCA usa el escalonado por rango (eso es solo para PLAN, PASO A). Un equipo con
     * comision_agente<=0 (estándar, o CUOTAS aún PENDIENTE — ambos se congelan en 0.00 al registrar,
     * ver reportes/procesar_reporte.php:113-126) siempre cae al monto plano EQUIPO_ESTANDAR; solo un
     * comision_especial>0 congelado en el ítem lo reemplaza. La tabla config_comisiones tipo='EQUIPO'
     * (rangos) existe mas nunca se lee en ese PASO B del legacy.
     */
    private function calcularComisionesEquipo(array $ids, string $inicio, string $fin): array
    {
        $fallback = $this->montoConfig('EQUIPO_ESTANDAR', 5.0);

        $rows = DB::table('ventas')
            ->join('reportes', 'ventas.reporte_id', '=', 'reportes.id')
            ->whereIn('ventas.vendedor_id', $ids)
            ->where('ventas.tipo_venta', 'EQUIPO')
            ->where('ventas.comision_estado', '!=', 'ANULADA')
            ->whereBetween('reportes.fecha', [$inicio, $fin])
            ->select('ventas.vendedor_id', 'ventas.comision_generada')
            ->get();

        $totales = array_fill_keys($ids, 0.0);
        foreach ($rows as $row) {
            $agenteId = (int) $row->vendedor_id;
            $especial = (float) $row->comision_generada;
            $totales[$agenteId] += $especial > 0 ? $especial : $fallback;
        }

        return $totales;
    }

    /**
     * Comisión de planes: SUM(comision_generada) por vendedor para POSTPAGO/PREPAGO.
     * Excluye: es_remate=1, subtipo PAQUETE.
     */
    private function calcularComisionesPlanes(array $ids, string $inicio, string $fin): array
    {
        $rangos = $this->rangosProductividad('PLAN');
        if (empty($rangos)) {
            $query = DB::table('ventas')
                ->join('reportes', 'ventas.reporte_id', '=', 'reportes.id')
                ->whereIn('ventas.vendedor_id', $ids)
                ->whereIn('ventas.tipo_venta', ['POSTPAGO', 'PREPAGO'])
                ->where('ventas.comision_estado', '!=', 'ANULADA')
                ->whereBetween('reportes.fecha', [$inicio, $fin])
                ->groupBy('ventas.vendedor_id')
                ->selectRaw('ventas.vendedor_id, SUM(ventas.comision_generada) as total');
            RankingVentaScope::excluirRematesYPaquetes($query, 'ventas');
            $rows = $query->get();

            return $rows->pluck('total', 'vendedor_id')->map(fn($v) => floatval($v))->all();
        }

        $rows = DB::table('ventas')
            ->join('reportes', 'ventas.reporte_id', '=', 'reportes.id')
            ->leftJoin('venta_lineas', 'venta_lineas.venta_id', '=', 'ventas.id')
            ->whereIn('ventas.vendedor_id', $ids)
            ->whereIn('ventas.tipo_venta', ['POSTPAGO', 'PREPAGO'])
            ->where('ventas.comision_estado', '!=', 'ANULADA')
            ->where(fn($q) => $q->whereNull('ventas.subtipo')->orWhere('ventas.subtipo', '!=', 'PAQUETE'))
            ->whereBetween('reportes.fecha', [$inicio, $fin])
            ->select([
                'ventas.id',
                'ventas.vendedor_id',
                'ventas.tipo_venta',
                'ventas.es_remate',
                'ventas.comision_generada',
                'venta_lineas.tipo_alta',
                'venta_lineas.cantidad',
                'reportes.fecha',
            ])
            ->orderBy('reportes.fecha')
            ->orderBy('ventas.id')
            ->get();

        $totales = array_fill_keys($ids, 0.0);
        $contadores = array_fill_keys($ids, 0);
        foreach ($rows as $row) {
            $agenteId = (int) $row->vendedor_id;
            if ($row->tipo_venta === 'PREPAGO') {
                $totales[$agenteId] += (float) $row->comision_generada;
                continue;
            }
            if (str_contains(strtoupper((string) $row->tipo_alta), 'UPGRADE')) {
                continue;
            }

            $cantidad = max(1, (int) ($row->cantidad ?? 1));
            for ($i = 0; $i < $cantidad; $i++) {
                $contadores[$agenteId]++;
                if (! (bool) $row->es_remate) {
                    $totales[$agenteId] += $this->montoPorRango($rangos, $contadores[$agenteId], 0.0);
                }
            }
        }

        return $totales;
    }

    private function rangosProductividad(string $tipo): array
    {
        if (! DB::getSchemaBuilder()->hasTable('config_comisiones')) {
            return [];
        }

        return DB::table('config_comisiones')
            ->where('tipo', $tipo)
            ->whereNotNull('rango_desde')
            ->whereNotNull('rango_hasta')
            ->orderBy('rango_desde')
            ->get(['rango_desde', 'rango_hasta', 'monto'])
            ->map(fn ($r) => [
                'desde' => (int) $r->rango_desde,
                'hasta' => (int) $r->rango_hasta,
                'monto' => (float) $r->monto,
            ])
            ->all();
    }

    private function montoPorRango(array $rangos, int $posicion, float $fallback): float
    {
        foreach ($rangos as $rango) {
            if ($posicion >= $rango['desde'] && $posicion <= $rango['hasta']) {
                return $rango['monto'];
            }
        }

        return $fallback;
    }

    private function montoConfig(string $tipo, float $fallback): float
    {
        if (! DB::getSchemaBuilder()->hasTable('config_comisiones')) {
            return $fallback;
        }

        return (float) (DB::table('config_comisiones')->where('tipo', $tipo)->value('monto') ?? $fallback);
    }

    /**
     * Comisión online (OTROS_FLUJO de tipo recarga/BCP): SUM por vendedor_id del reporte.
     * Usa agente_id del reporte porque las recargas van al agente de tienda, no al vendedor.
     */
    private function calcularComisionesOnline(array $ids, string $inicio, string $fin): array
    {
        // E1 — paridad legacy: comisión online = recargas × (ganancia_recargas%) + ops_BCP × COMISION_BCP.
        // Requiere la tabla legacy `config_comisiones`. Si no existe, fallback seguro al método previo.
        $schema = DB::getSchemaBuilder();
        if (! $schema->hasTable('config_comisiones')) {
            return $this->comisionesOnlineFallback($ids, $inicio, $fin);
        }

        $tasaRecargas = (float) (DB::table('config_comisiones')->where('tipo', 'ganancia_recargas')->value('monto') ?? 0) / 100;
        $tasaBcp      = (float) (DB::table('config_comisiones')->where('tipo', 'COMISION_BCP')->value('monto') ?? 0);

        // Recargas por agente: reportes.recarga_bipay del mes (campo de recargas del modelo normalizado).
        $recargas = DB::table('reportes')
            ->whereIn('agente_id', $ids)
            ->whereBetween('fecha', [$inicio, $fin])
            ->groupBy('agente_id')
            ->selectRaw('agente_id, COALESCE(SUM(recarga_bipay), 0) as total')
            ->pluck('total', 'agente_id');

        // Operaciones BCP por agente (solo si reportes_bcp tiene agente_id en el esquema actual).
        $bcp = collect();
        if ($schema->hasTable('reportes_bcp') && $schema->hasColumn('reportes_bcp', 'agente_id')) {
            $bcp = DB::table('reportes_bcp')
                ->whereIn('agente_id', $ids)
                ->whereBetween('fecha', [$inicio, $fin])
                ->groupBy('agente_id')
                ->selectRaw('agente_id, COALESCE(SUM(cantidad_operaciones), 0) as ops')
                ->pluck('ops', 'agente_id');
        }

        $result = array_fill_keys($ids, 0.0);
        foreach ($ids as $id) {
            $result[$id] = round(
                (float) ($recargas[$id] ?? 0) * $tasaRecargas + (float) ($bcp[$id] ?? 0) * $tasaBcp,
                2
            );
        }

        return $result;
    }

    /** Fallback (sin config_comisiones): suma de comisiones OTROS_FLUJO ya registradas. */
    private function comisionesOnlineFallback(array $ids, string $inicio, string $fin): array
    {
        $rows = DB::table('ventas')
            ->join('reportes', 'ventas.reporte_id', '=', 'reportes.id')
            ->whereIn('ventas.vendedor_id', $ids)
            ->where('ventas.tipo_venta', 'OTROS_FLUJO')
            ->where('ventas.comision_estado', '!=', 'ANULADA')
            ->where('ventas.comision_generada', '>', 0)
            ->whereBetween('reportes.fecha', [$inicio, $fin])
            ->groupBy('ventas.vendedor_id')
            ->selectRaw('ventas.vendedor_id, SUM(ventas.comision_generada) as total')
            ->get();

        return $rows->pluck('total', 'vendedor_id')->map(fn($v) => floatval($v))->all();
    }

    /**
     * Cálculo de tardanzas (S/1 por minuto) y faltas (2 * valor_dia) desde asistencias.
     * Carga todos los registros del mes en una sola query.
     */
    private function calcularAsistencias(array $ids, string $inicio, string $fin, $agentes): array
    {
        $tardanzas = array_fill_keys($ids, 0.0);
        $faltas    = array_fill_keys($ids, 0.0);

        $sueldoMap = $agentes->pluck('sueldo_base', 'id')->all();

        // Verificar si existe la tabla asistencias antes de consultar
        if (!DB::getSchemaBuilder()->hasTable('asistencias')) {
            return [$tardanzas, $faltas];
        }

        $asistencias = DB::table('asistencias')
            ->whereIn('agente_id', $ids)
            ->whereBetween('fecha', [$inicio, $fin])
            ->get(['agente_id', 'estado_asistencia', 'minutos_tardanza', 'comodin_usado', 'min_comodin']);

        $valorMinutoTardanza = 1.00; // S/1 por minuto (igual que legacy)

        foreach ($asistencias as $a) {
            $aid      = $a->agente_id;
            $sueldo   = floatval($sueldoMap[$aid] ?? 0);
            $valorDia = $sueldo / 30.0;

            if ($a->estado_asistencia === 'FALTA_INJUSTIFICADA') {
                $faltas[$aid] += $valorDia * 2;
                continue;
            }

            $minTardanza = (int)($a->minutos_tardanza ?? 0);
            $minComodin  = ($a->comodin_usado ? (int)($a->min_comodin ?? 0) : 0);
            $netaTardanza = max(0, $minTardanza - $minComodin);
            $tardanzas[$aid] += $netaTardanza * $valorMinutoTardanza;
        }

        return [$tardanzas, $faltas];
    }

    /**
     * Carga adelantos del mes en una sola query (si existe la tabla).
     */
    private function cargarAdelantos(array $ids, string $inicio, string $fin): array
    {
        if (!DB::getSchemaBuilder()->hasTable('adelantos')) {
            return array_fill_keys($ids, 0.0);
        }

        $rows = DB::table('adelantos')
            ->whereIn('agente_id', $ids)
            ->whereBetween('fecha', [$inicio, $fin])
            ->groupBy('agente_id')
            ->selectRaw('agente_id, COALESCE(SUM(monto), 0) as total')
            ->get();

        $result = array_fill_keys($ids, 0.0);
        foreach ($rows as $row) {
            $result[$row->agente_id] = floatval($row->total);
        }
        return $result;
    }

    private function totalesVacios(): array
    {
        return [
            'sueldo_base'        => 0.0,
            'sueldo_dias_lab'    => 0.0,
            'total_remuneracion' => 0.0,
            'total_descuentos'   => 0.0,
            'total_pagar'        => 0.0,
            'com_planes'         => 0.0,
            'com_equipo'         => 0.0,
            'com_online'         => 0.0,
            'adelantos'          => 0.0,
        ];
    }
}
