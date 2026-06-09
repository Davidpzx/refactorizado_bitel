<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComisionPlan;
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
            ->orderByRaw("FIELD(tipo_servicio, 'POSTPAGO', 'PREPAGO', 'EQUIPO', 'ACCESORIO') ASC")
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
            ->keyBy(fn($p) => strtolower(trim((string) $p->nombre_plan)));

        if ($planesMap->isEmpty()) {
            return response()->json(['error' => 'No hay planes de comisión configurados.'], 422);
        }

        DB::beginTransaction();
        try {
            // ── Ventas de líneas (POSTPAGO + PREPAGO) ────────────────────────────
            $lineas = DB::table('ventas as v')
                ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
                ->join('venta_lineas as vl', 'vl.venta_id', '=', 'v.id')
                ->whereBetween('r.fecha', [$desde, $hasta])
                ->where('r.estado', '!=', 'borrador')
                ->whereIn('v.tipo_venta', ['POSTPAGO', 'PREPAGO'])
                ->when($tienda, fn($q) => $q->where('r.tienda_id', $tienda))
                ->select(
                    'v.id as venta_id',
                    'v.es_remate',
                    'v.es_extranjero',
                    'v.monto_total',
                    'vl.id as linea_id',
                    'vl.plan_nombre_snap',
                    'vl.cantidad',
                )
                ->get();

            $updatedVentas  = 0;
            $updatedLineas  = 0;

            // Agrupar por venta para sumar comisiones por venta
            $comisionesPorVenta = [];

            foreach ($lineas as $row) {
                $planKey  = strtolower(trim((string) ($row->plan_nombre_snap ?? '')));
                $plan     = $planesMap->get($planKey);
                $cantidad = max(1, (int) $row->cantidad);

                // Sin plan configurado → no tocar
                if (!$plan) {
                    continue;
                }

                $esExtranjero = (bool) $row->es_extranjero;
                $esRemate     = (bool) $row->es_remate || ((float) $row->monto_total < 20.00);

                if ($esRemate) {
                    $comisionUnitaria = 0.00;
                } else {
                    $base = (float) ($esExtranjero ? $plan->comision_ext_n : $plan->comision_dni_n);
                    // Costo chip: 1 sol para altas nuevas (no migración/upgrade)
                    $costoChip        = 1.00;
                    $comisionUnitaria = max(0.00, $base - $costoChip);
                }

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

            DB::commit();

            return response()->json([
                'success'        => true,
                'ventas_actualizadas' => $updatedVentas,
                'lineas_actualizadas' => $updatedLineas,
                'periodo'        => "{$desde} → {$hasta}",
                'message'        => "Recálculo completado: {$updatedVentas} ventas, {$updatedLineas} líneas actualizadas.",
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
