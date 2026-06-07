<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agente;
use App\Models\PlanillaAjuste;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
            return response()->json(['mes' => $mes, 'agentes' => [], 'totales' => $this->totalesVacios()]);
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

        return response()->json([
            'mes'     => $mes,
            'agentes' => $filas,
            'totales' => $totales,
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
     * con fallback a S/5 si la comisión está en 0.
     */
    private function calcularComisionesEquipo(array $ids, string $inicio, string $fin): array
    {
        $rows = DB::table('ventas')
            ->join('reportes', 'ventas.reporte_id', '=', 'reportes.id')
            ->whereIn('ventas.vendedor_id', $ids)
            ->where('ventas.tipo_venta', 'EQUIPO')
            ->where('ventas.comision_estado', '!=', 'ANULADA')
            ->whereBetween('reportes.fecha', [$inicio, $fin])
            ->groupBy('ventas.vendedor_id')
            ->selectRaw('ventas.vendedor_id, SUM(CASE WHEN ventas.comision_generada > 0 THEN ventas.comision_generada ELSE 5.00 END) as total')
            ->get();

        return $rows->pluck('total', 'vendedor_id')->map(fn($v) => floatval($v))->all();
    }

    /**
     * Comisión de planes: SUM(comision_generada) por vendedor para POSTPAGO/PREPAGO.
     * Excluye: es_remate=1, subtipo PAQUETE.
     */
    private function calcularComisionesPlanes(array $ids, string $inicio, string $fin): array
    {
        $rows = DB::table('ventas')
            ->join('reportes', 'ventas.reporte_id', '=', 'reportes.id')
            ->whereIn('ventas.vendedor_id', $ids)
            ->whereIn('ventas.tipo_venta', ['POSTPAGO', 'PREPAGO'])
            ->where('ventas.es_remate', false)
            ->where('ventas.comision_estado', '!=', 'ANULADA')
            ->where(fn($q) => $q->whereNull('ventas.subtipo')->orWhere('ventas.subtipo', '!=', 'PAQUETE'))
            ->whereBetween('reportes.fecha', [$inicio, $fin])
            ->groupBy('ventas.vendedor_id')
            ->selectRaw('ventas.vendedor_id, SUM(ventas.comision_generada) as total')
            ->get();

        return $rows->pluck('total', 'vendedor_id')->map(fn($v) => floatval($v))->all();
    }

    /**
     * Comisión online (OTROS_FLUJO de tipo recarga/BCP): SUM por vendedor_id del reporte.
     * Usa agente_id del reporte porque las recargas van al agente de tienda, no al vendedor.
     */
    private function calcularComisionesOnline(array $ids, string $inicio, string $fin): array
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
