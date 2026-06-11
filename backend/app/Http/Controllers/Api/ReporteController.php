<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\HistorialReporte;
use App\Models\Reporte;
use App\Models\ReporteBorrador;
use App\Models\Venta;
use App\Models\VentaEquipo;
use App\Models\VentaLinea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReporteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reportes = Reporte::query()
            ->when($request->tienda,      fn($q, $t) => $q->where('tienda_id', $t))
            ->when($request->estado,      fn($q, $e) => $q->where('estado', $e))
            ->when($request->fecha_desde, fn($q, $f) => $q->whereDate('fecha', '>=', $f))
            ->when($request->fecha_hasta, fn($q, $f) => $q->whereDate('fecha', '<=', $f))
            ->withCount('ventas')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($reportes);
    }

    public function show(Reporte $reporte): JsonResponse
    {
        $reporte->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente']);
        return response()->json($reporte);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agente_id'                      => 'required|integer',
            'tienda_id'                      => 'required|string|max:50',
            'usuario_id'                     => 'required|integer',
            'fecha'                          => 'required|date',
            'caja_inicial'                   => 'required|numeric|min:0',
            'yape'                           => 'nullable|numeric|min:0',
            'bipay'                          => 'nullable|numeric|min:0',
            'transferencia'                  => 'nullable|numeric|min:0',
            'recarga_bipay'                  => 'nullable|numeric|min:0',
            'pago_servicio'                  => 'nullable|numeric|min:0',
            'pago_krece'                     => 'nullable|numeric|min:0',
            'tickets_tusamy'                 => 'nullable|numeric|min:0',
            'retiro_bipay'                   => 'nullable|numeric|min:0',
            'efectivo_entregado'             => 'required|numeric|min:0',
            'total_salidas'                  => 'nullable|numeric|min:0',
            'nombre_cubre'                   => 'nullable|string|max:100',
            'observaciones'                  => 'nullable|string',
            'obs_dia'                        => 'nullable|string',
            'destino_efectivo'               => 'nullable|string|max:50',
            'ventas'                         => 'nullable|array',
            'ventas.*.tipo_venta'            => 'required_with:ventas|in:EQUIPO,ACCESORIO,POSTPAGO,PREPAGO,OTROS_FLUJO',
            'ventas.*.subtipo'               => 'nullable|string|max:50',
            'ventas.*.monto_total'           => 'required_with:ventas|numeric|min:0',
            'ventas.*.efectivo_inicial'      => 'nullable|numeric|min:0',
            'ventas.*.cross_selling'         => 'nullable|boolean',
            'ventas.*.tienda_destino'        => 'nullable|string|max:10',
            'ventas.*.es_remate'             => 'nullable|boolean',
            'ventas.*.es_extranjero'         => 'nullable|boolean',
            'ventas.*.cliente_dni'           => 'nullable|string|max:11',
            'ventas.*.inventario_tienda_id'  => 'nullable|integer',
            'ventas.*.producto_nombre'       => 'nullable|string|max:150',
            'ventas.*.imei_serial'           => 'nullable|string|max:50',
            'ventas.*.tipo_pago'             => 'nullable|in:CONTADO,CUOTAS',
            'ventas.*.financiera'            => 'nullable|string|max:50',
            'ventas.*.precio_venta'          => 'nullable|numeric|min:0',
            'ventas.*.costo_snap'            => 'nullable|numeric|min:0',
            'ventas.*.por_cobrar_financiera' => 'nullable|numeric|min:0',
            'ventas.*.plan_nombre'           => 'nullable|string|max:150',
            'ventas.*.tipo_alta'             => 'nullable|string|max:30',
            'ventas.*.cantidad'              => 'nullable|integer|min:1',
            'ventas.*.cobrado_unitario'      => 'nullable|numeric|min:0',
            'ventas.*.comision_unitaria'     => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $ventas_data        = $validated['ventas'] ?? [];
            $total_calculado    = collect($ventas_data)->sum('monto_total');
            $total_salidas      = (float) ($validated['total_salidas'] ?? 0);
            $yape               = (float) ($validated['yape'] ?? 0);
            $bipay              = (float) ($validated['bipay'] ?? 0);
            $transferencia      = (float) ($validated['transferencia'] ?? 0);
            $recarga_bipay      = (float) ($validated['recarga_bipay'] ?? 0);
            $efectivo_entregado = (float) $validated['efectivo_entregado'];
            $caja_inicial       = (float) $validated['caja_inicial'];

            $suma_efectivo_ventas = collect($ventas_data)->sum(fn($v) => $v['efectivo_inicial'] ?? $v['monto_total']);
            $efectivo_esperado    = $suma_efectivo_ventas + $caja_inicial - $total_salidas;
            $diferencia           = $efectivo_entregado - $efectivo_esperado;

            $reporte = Reporte::create([
                'agente_id'           => $validated['agente_id'],
                'tienda_id'           => $validated['tienda_id'],
                'usuario_id'          => $validated['usuario_id'],
                'fecha'               => $validated['fecha'],
                'total_dia'           => $efectivo_entregado,
                'total_calculado'     => $total_calculado,
                'yape'                => $yape,
                'bipay'               => $bipay,
                'recarga_bipay'       => $recarga_bipay,
                'pago_servicio'       => (float) ($validated['pago_servicio'] ?? 0),
                'pago_krece'          => (float) ($validated['pago_krece'] ?? 0),
                'tickets_tusamy'      => (float) ($validated['tickets_tusamy'] ?? 0),
                'retiro_bipay'        => (float) ($validated['retiro_bipay'] ?? 0),
                'transferencia'       => $transferencia,
                'caja_inicial'        => $caja_inicial,
                'efectivo_entregado'  => $efectivo_entregado,
                'total_salidas'       => $total_salidas,
                'total_restantes'     => 0,
                'efectivo_esperado'   => $efectivo_esperado,
                'diferencia'          => $diferencia,
                'estado'              => 'borrador',
                'requiere_aprobacion' => abs($diferencia) > 10,
                'nombre_cubre'        => $validated['nombre_cubre'] ?? null,
                'observaciones'       => $validated['observaciones'] ?? null,
                'obs_dia'             => $validated['obs_dia'] ?? null,
                'destino_efectivo'    => $validated['destino_efectivo'] ?? 'TIENDA',
            ]);

            foreach ($ventas_data as $vd) {
                $cliente_id = null;
                if (!empty($vd['cliente_dni'])) {
                    $dni = $vd['cliente_dni'];
                    $cliente = Cliente::firstOrCreate(
                        ['dni_ruc' => $dni],
                        [
                            'nombre'         => 'Por identificar',
                            'tipo_documento' => strlen($dni) === 8 ? 'DNI' : 'RUC',
                        ]
                    );
                    $cliente_id = $cliente->id;
                }

                $venta = Venta::create([
                    'reporte_id'       => $reporte->id,
                    'vendedor_id'      => $validated['agente_id'],
                    'cliente_id'       => $cliente_id,
                    'tipo_venta'       => $vd['tipo_venta'],
                    'subtipo'          => $vd['subtipo'] ?? null,
                    'cross_selling'    => (bool) ($vd['cross_selling'] ?? false),
                    'tienda_destino'   => ($vd['cross_selling'] ?? false) ? ($vd['tienda_destino'] ?? null) : null,
                    'monto_total'      => $vd['monto_total'],
                    'efectivo_inicial' => $vd['efectivo_inicial'] ?? $vd['monto_total'],
                    'comision_generada'=> 0,
                    'comision_estado'  => 'ACTIVA',
                    'es_remate'        => (bool) ($vd['es_remate'] ?? false),
                    'es_extranjero'    => (bool) ($vd['es_extranjero'] ?? false),
                ]);

                if (in_array($vd['tipo_venta'], ['EQUIPO', 'ACCESORIO']) && !empty($vd['producto_nombre'])) {
                    VentaEquipo::create([
                        'venta_id'              => $venta->id,
                        'inventario_tienda_id'  => $vd['inventario_tienda_id'] ?? 0,
                        'producto_nombre_snap'  => $vd['producto_nombre'],
                        'imei_serial_snap'      => $vd['imei_serial'] ?? null,
                        'tipo_item'             => $vd['tipo_venta'],
                        'tipo_pago'             => $vd['tipo_pago'] ?? 'CONTADO',
                        'financiera'            => $vd['financiera'] ?? null,
                        'precio_venta'          => $vd['precio_venta'] ?? $vd['monto_total'],
                        'costo_snap'            => $vd['costo_snap'] ?? 0,
                        'ganancia_snap'         => isset($vd['precio_venta'], $vd['costo_snap'])
                            ? ((float)$vd['precio_venta'] - (float)$vd['costo_snap']) : null,
                        'por_cobrar_financiera' => $vd['por_cobrar_financiera'] ?? 0,
                    ]);
                }

                if (in_array($vd['tipo_venta'], ['POSTPAGO', 'PREPAGO']) && !empty($vd['plan_nombre'])) {
                    VentaLinea::create([
                        'venta_id'          => $venta->id,
                        'plan_nombre_snap'  => $vd['plan_nombre'],
                        'tipo_alta'         => $vd['tipo_alta'] ?? 'LN',
                        'cantidad'          => $vd['cantidad'] ?? 1,
                        'cobrado_unitario'  => $vd['cobrado_unitario'] ?? $vd['monto_total'],
                        'comision_unitaria' => $vd['comision_unitaria'] ?? 0,
                        'es_esim'           => false,
                    ]);
                }
            }

            DB::commit();

            $user = $request->user();
            if ($user && $user->tienda_id) {
                try {
                    ReporteBorrador::query()
                        ->where('agente_id', $user->id)
                        ->where('tienda_id', $user->tienda_id)
                        ->whereDate('fecha', now(config('reportes.timezone'))->toDateString())
                        ->delete();
                } catch (\Throwable $cleanupError) {
                    Log::warning('No se pudo limpiar el borrador tras guardar el reporte.', [
                        'reporte_id' => $reporte->id,
                        'usuario_id' => $user->id,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            $reporte->load('ventas');
            return response()->json($reporte, 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al guardar: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, Reporte $reporte): JsonResponse
    {
        if ($reporte->estado === 'aprobado') {
            return response()->json(['error' => 'No se puede editar un reporte aprobado.'], 422);
        }

        $validated = $request->validate([
            'observaciones'      => 'nullable|string',
            'obs_dia'            => 'nullable|string',
            'efectivo_entregado' => 'sometimes|numeric|min:0',
            'destino_efectivo'   => 'sometimes|string|max:50',
            'estado'             => 'sometimes|in:borrador,enviado',
        ]);

        $reporte->update($validated);
        return response()->json($reporte->fresh());
    }

    public function destroy(Reporte $reporte): JsonResponse
    {
        if ($reporte->estado === 'aprobado') {
            return response()->json(['error' => 'No se puede eliminar un reporte aprobado.'], 422);
        }
        $reporte->delete();
        return response()->json(null, 204);
    }

    public function misReportes(Request $request): JsonResponse
    {
        $user = $request->user();

        $reportes = Reporte::query()
            ->where('usuario_id', $user->id)
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereDate('fecha', '>=', $f))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereDate('fecha', '<=', $f))
            ->when($request->estado,      fn ($q, $e) => $q->where('estado', $e))
            ->withCount('ventas')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($reportes);
    }

    public function actualizarDestino(Request $request, Reporte $reporte): JsonResponse
    {
        $validated = $request->validate([
            'destino_efectivo' => 'required|string|max:50',
        ]);

        $reporte->update(['destino_efectivo' => $validated['destino_efectivo']]);
        return response()->json($reporte->fresh());
    }

    public function solicitarEdicion(Request $request, Reporte $reporte): JsonResponse
    {
        $validated = $request->validate([
            'motivo_edicion' => 'required|string|max:500',
        ]);

        if ($reporte->estado === 'borrador') {
            return response()->json(['error' => 'El reporte aún está en borrador y puede editarse directamente.'], 422);
        }

        if ($reporte->estado_edicion === 'SOLICITADO') {
            return response()->json(['error' => 'Ya existe una solicitud de edición pendiente.'], 422);
        }

        $reporte->update([
            'estado_edicion' => 'SOLICITADO',
            'motivo_edicion' => $validated['motivo_edicion'],
        ]);

        HistorialReporte::create([
            'reporte_id' => $reporte->id,
            'usuario_id' => auth()->id(),
            'accion'     => 'solicito_edicion',
            'detalle'    => $validated['motivo_edicion'],
        ]);

        return response()->json($reporte->fresh());
    }

    public function aprobarEdicion(Request $request, Reporte $reporte): JsonResponse
    {
        if ($reporte->estado_edicion !== 'SOLICITADO') {
            return response()->json(['error' => 'No hay solicitud de edición pendiente.'], 422);
        }

        $reporte->update([
            'estado_edicion' => 'APROBADO',
            'estado'         => 'borrador',
        ]);

        HistorialReporte::create([
            'reporte_id' => $reporte->id,
            'usuario_id' => auth()->id(),
            'accion'     => 'edicion_aprobada',
            'detalle'    => 'Edición aprobada por administración.',
        ]);

        return response()->json($reporte->fresh());
    }

    public function historial(Reporte $reporte): JsonResponse
    {
        $historial = $reporte->historialReportes()
            ->with('usuario:id,nombre')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($historial);
    }

    // ── POST /reporte-categorias/{id}/fijar-costo — Campana: fijar costo rápido
    public function fijarCosto(Request $request, int $rcId): JsonResponse
    {
        $user = $request->user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        $precioCosto = round((float) $request->input('precio_costo', 0), 2);
        $precioVenta = round((float) $request->input('precio_venta', 0), 2);

        if ($rcId <= 0 || $precioCosto <= 0) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos.'], 422);
        }

        $row = \Illuminate\Support\Facades\DB::table('reporte_categorias')
            ->where('id', $rcId)
            ->where('tipo', 'equipos_accesorios')
            ->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado.'], 404);
        }

        $detalle = json_decode($row->detalle, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['success' => false, 'message' => 'JSON del detalle inválido.'], 500);
        }

        $pv = $precioVenta > 0
            ? $precioVenta
            : floatval($detalle['precio_normal_agente'] ?? $detalle['precio_total'] ?? 0);

        $detalle['costo_al_registrar'] = $precioCosto;
        $detalle['ganancia']           = $pv - $precioCosto;
        $detalle['precio_costo']       = $precioCosto;

        \Illuminate\Support\Facades\DB::table('reporte_categorias')
            ->where('id', $rcId)
            ->update(['detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE)]);

        return response()->json([
            'success'  => true,
            'message'  => 'Costo actualizado correctamente.',
            'ganancia' => $detalle['ganancia'],
            'costo'    => $precioCosto,
        ]);
    }
}
