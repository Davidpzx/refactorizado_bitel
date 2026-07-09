<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComisionPlan;
use App\Models\Reporte;
use App\Services\ComisionOperativaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ComisionPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $planes = ComisionPlan::query()
            ->when($request->tipo_servicio, fn($q, $t) => $q->where('tipo_servicio', $t))
            ->orderByRaw("CASE tipo_servicio WHEN 'POSTPAGO' THEN 1 WHEN 'PREPAGO' THEN 2 WHEN 'EQUIPO' THEN 3 WHEN 'ACCESORIO' THEN 4 ELSE 5 END ASC")
            ->orderBy('nombre_plan')
            ->get();

        return response()->json($planes);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo_servicio'   => ['required', Rule::in(['POSTPAGO', 'PREPAGO', 'EQUIPO', 'ACCESORIO', 'OTROS'])],
            'nombre_plan'     => ['required', 'string', 'max:100'],
            'tipo_alta'       => ['nullable', 'string', 'max:30'],
            'fee_monto'       => ['nullable', 'numeric', 'min:0'],
            'comision_dni_n'  => ['nullable', 'numeric', 'min:0'],
            'comision_dni_n3' => ['nullable', 'numeric', 'min:0'],
            'comision_ext_n'  => ['nullable', 'numeric', 'min:0'],
            'comision_ext_n3' => ['nullable', 'numeric', 'min:0'],
        ]);

        $plan = ComisionPlan::create($data);

        return response()->json($plan, 201);
    }

    public function update(Request $request, ComisionPlan $comisionesPlan): JsonResponse
    {
        $data = $request->validate([
            'tipo_servicio'   => ['sometimes', Rule::in(['POSTPAGO', 'PREPAGO', 'EQUIPO', 'ACCESORIO', 'OTROS'])],
            'nombre_plan'     => ['sometimes', 'string', 'max:100'],
            'tipo_alta'       => ['nullable', 'string', 'max:30'],
            'fee_monto'       => ['nullable', 'numeric', 'min:0'],
            'comision_dni_n'  => ['nullable', 'numeric', 'min:0'],
            'comision_dni_n3' => ['nullable', 'numeric', 'min:0'],
            'comision_ext_n'  => ['nullable', 'numeric', 'min:0'],
            'comision_ext_n3' => ['nullable', 'numeric', 'min:0'],
        ]);

        $comisionesPlan->update($data);

        return response()->json($comisionesPlan);
    }

    public function destroy(ComisionPlan $comisionesPlan): JsonResponse
    {
        $comisionesPlan->delete();
        return response()->json(null, 204);
    }

    /**
     * POST /v1/comisiones-planes/recalcular
     *
     * Recalcula ventas.comision_generada y venta_lineas.comision_unitaria
     * usando las tarifas actuales de comisiones_planes.
     *
     * Reglas de negocio (idénticas al legacy recalcular_comisiones_masivo.php):
     *  - Busca el plan por nombre en venta_lineas.plan_nombre_snap (POSTPAGO/PREPAGO)
     *  - Si es extranjero → comision_ext_n, si no → comision_dni_n
     *  - Remate (monto_total < 20) → comision = 0
     *  - Equipo/Accesorio → ganancia_snap no se recalcula (ya fijada al momento de venta)
     */
    public function recalcularMasivo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fecha_desde' => ['required', 'date'],
            'fecha_hasta' => ['required', 'date', 'gte:fecha_desde'],
            'tienda_id'   => ['nullable', 'string', 'max:20'],
        ]);

        $desde  = $data['fecha_desde'];
        $hasta  = $data['fecha_hasta'];
        $tienda = $data['tienda_id'] ?? null;

        // Cargar todos los planes en memoria para lookup O(1)
        $planesMap = ComisionPlan::all()
            ->keyBy(fn ($p) => strtolower($this->nombrePlanBase((string) $p->nombre_plan)));

        DB::beginTransaction();
        try {
            // ── Ventas de líneas (POSTPAGO + PREPAGO) ────────────────────────────
            $lineas = DB::table('ventas as v')
                ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
                ->join('venta_lineas as vl', 'vl.venta_id', '=', 'v.id')
                ->whereBetween('r.fecha', [$desde, $hasta])
                ->where('r.estado', '!=', 'borrador')
                ->whereIn('v.tipo_venta', ['POSTPAGO', 'PREPAGO', 'APOYO'])
                ->when($tienda, fn($q) => $q->where('r.tienda_id', $tienda))
                ->select(
                    'v.id as venta_id',
                    'v.es_remate',
                    'v.es_extranjero',
                    'v.monto_total',
                    'v.tipo_venta',
                    'vl.id as linea_id',
                    'vl.plan_nombre_snap',
                    'vl.tipo_alta',
                    'vl.cantidad',
                    'vl.cobrado_unitario',
                    'vl.es_migracion',
                    'vl.es_upgrade',
                    'vl.es_esim',
                    'vl.plan_anterior',
                )
                ->get();

            $updatedVentas  = 0;
            $updatedLineas  = 0;

            // Agrupar por venta para sumar comisiones por venta
            $comisionesPorVenta = [];

            foreach ($lineas as $row) {
                $planKey  = strtolower($this->nombrePlanBase((string) ($row->plan_nombre_snap ?? '')));
                $plan     = $planesMap->get($planKey);
                $cantidad = max(1, (int) $row->cantidad);

                // Sin plan configurado → no tocar
                if (!$plan) {
                    continue;
                }

                $comisionUnitaria = $this->recalcularComisionUnitaria($row, $plan);

                DB::table('venta_lineas')
                    ->where('id', $row->linea_id)
                    ->update(['comision_unitaria' => $comisionUnitaria]);
                $updatedLineas++;

                $comisionesPorVenta[$row->venta_id] = ($comisionesPorVenta[$row->venta_id] ?? 0.0)
                    + round($comisionUnitaria * $cantidad, 2);
            }

            // Actualizar ventas.comision_generada
            foreach ($comisionesPorVenta as $ventaId => $totalComision) {
                DB::table('ventas')
                    ->where('id', $ventaId)
                    ->update(['comision_generada' => round($totalComision, 2)]);
                $updatedVentas++;
            }

            $reportesOperativos = Reporte::query()
                ->whereBetween('fecha', [$desde, $hasta])
                ->when($tienda, fn ($q) => $q->where('tienda_id', $tienda))
                ->get();
            $updatedOperativos = 0;
            $operativas = new ComisionOperativaService();
            foreach ($reportesOperativos as $reporte) {
                $updatedOperativos += $operativas->recalcularReporte($reporte);
            }

            DB::commit();

            return response()->json([
                'success'        => true,
                'ventas_actualizadas' => $updatedVentas,
                'lineas_actualizadas' => $updatedLineas,
                'operativas_actualizadas' => $updatedOperativos,
                'periodo'        => "{$desde} → {$hasta}",
                'message'        => "Recálculo completado: {$updatedVentas} ventas, {$updatedLineas} líneas y {$updatedOperativos} ganancias operativas actualizadas.",
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function nombrePlanBase(string $nombre): string
    {
        return trim((string) preg_replace(
            '/\s*\((?:Upgrade|Migraci[oó]n)\)|\s*\[No Comisionable[^\]]*\]/iu',
            '',
            $nombre
        ));
    }

    private function recalcularComisionUnitaria(object $linea, ComisionPlan $plan): float
    {
        $nombre = mb_strtoupper((string) $linea->plan_nombre_snap);
        $tipoAlta = mb_strtoupper((string) $linea->tipo_alta);
        $esUpgrade = (bool) $linea->es_upgrade
            || str_contains($nombre, 'UPGRADE')
            || str_contains($tipoAlta, 'UPG');
        $esMigracion = (bool) $linea->es_migracion
            || str_contains($nombre, 'MIGRACI')
            || str_contains($tipoAlta, 'MIG');
        $esEsim = (bool) $linea->es_esim || str_contains($nombre, 'ESIM');
        $cobrado = (float) $linea->cobrado_unitario;
        $base = (float) ((bool) $linea->es_extranjero ? $plan->comision_ext_n : $plan->comision_dni_n);
        $esRemate = (bool) $linea->es_remate
            || ($linea->tipo_venta !== 'PREPAGO' && ! $esUpgrade && $cobrado > 0 && $cobrado < 20);

        if ($esRemate) {
            return 0.0;
        }

        if ($esUpgrade) {
            $diferencia = (float) $plan->fee_monto - (float) ($linea->plan_anterior ?? 0);

            return $diferencia >= 20 ? 20.0 : ($diferencia >= 10 ? 10.0 : 0.0);
        }

        $costoChip = ($esMigracion || $esEsim) ? 0.0 : 1.0;
        if (mb_strtoupper((string) $plan->tipo_servicio) === 'PREPAGO' || $linea->tipo_venta === 'PREPAGO') {
            return max(0.0, ($cobrado > 0 ? $cobrado : $base) - $costoChip);
        }

        return max(0.0, $base - $costoChip);
    }
}
