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
use App\Services\ComisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
            'ventas.*.tipo_venta'            => 'required_with:ventas|in:EQUIPO,ACCESORIO,POSTPAGO,PREPAGO,OTROS_FLUJO,APOYO',
            'ventas.*.subtipo'               => 'nullable|string|max:50',
            'ventas.*.monto_total'           => 'required_with:ventas|numeric|min:0',
            'ventas.*.efectivo_inicial'      => 'nullable|numeric|min:0',
            'ventas.*.cross_selling'         => 'nullable|boolean',
            'ventas.*.tienda_destino'        => 'nullable|string|max:10|required_if:ventas.*.tipo_venta,APOYO',
            'ventas.*.es_remate'             => 'nullable|boolean',
            'ventas.*.es_extranjero'         => 'nullable|boolean',
            'ventas.*.es_migracion'          => 'nullable|boolean',
            'ventas.*.es_upgrade'            => 'nullable|boolean',
            'ventas.*.es_esim'               => 'nullable|boolean',
            'ventas.*.plan_anterior'         => 'nullable|numeric|min:0',
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

        // B5 — Guardia anti-duplicados (paridad legacy procesar_reporte.php):
        // impide más de un reporte por (agente, tienda, fecha) aunque el front falle o sea evadido.
        $duplicado = Reporte::query()
            ->where('agente_id', $validated['agente_id'])
            ->where('tienda_id', $validated['tienda_id'])
            ->whereDate('fecha', $validated['fecha'])
            ->exists();
        if ($duplicado) {
            return response()->json([
                'error' => 'Ya existe un reporte para este agente, tienda y fecha.',
                'code'  => 'DUPLICATE_REPORT',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $ventas_data        = $validated['ventas'] ?? [];
            $total_calculado    = collect($ventas_data)->sum('monto_total');
            $comisionService    = new ComisionService();
            $total_salidas      = (float) ($validated['total_salidas'] ?? 0);
            $yape               = (float) ($validated['yape'] ?? 0);
            $bipay              = (float) ($validated['bipay'] ?? 0);
            $transferencia      = (float) ($validated['transferencia'] ?? 0);
            $recarga_bipay      = (float) ($validated['recarga_bipay'] ?? 0);
            $efectivo_entregado = (float) $validated['efectivo_entregado'];
            $caja_inicial       = (float) $validated['caja_inicial'];

            // B3 — Fórmula del cuadre 1:1 con el legacy y lib/cuadre.ts:
            //   total_sistema     = Σ ventas + ingresos fijos (recargas/servicios/krece/tickets)
            //   total_no_fisico   = yape + bipay + transferencia + retiro_bipay
            //   efectivo_esperado = total_sistema − total_no_fisico − total_salidas  (NO incluye caja_inicial)
            $retiro_bipay      = (float) ($validated['retiro_bipay'] ?? 0);
            $total_sistema     = (float) collect($ventas_data)->sum('monto_total')
                + (float) ($validated['recarga_bipay'] ?? 0)
                + (float) ($validated['pago_servicio'] ?? 0)
                + (float) ($validated['pago_krece'] ?? 0)
                + (float) ($validated['tickets_tusamy'] ?? 0);
            $total_no_fisico   = $yape + $bipay + $transferencia + $retiro_bipay;
            $efectivo_esperado = round($total_sistema - $total_no_fisico - $total_salidas, 2);
            $diferencia        = round($efectivo_entregado - $efectivo_esperado, 2);

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

            $this->procesarVentas($reporte, $ventas_data, (string) $validated['tienda_id'], (int) $validated['agente_id'], $comisionService);

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

        } catch (\RuntimeException $e) {
            DB::rollBack();
            // Violación de regla de negocio (p.ej. guardia de stock) → 422, no 500.
            return response()->json(['error' => $e->getMessage(), 'code' => 'STOCK_GUARD'], 422);
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
            'motivo_edicion'     => 'sometimes|nullable|string|max:500',
        ]);

        // Al corregir el efectivo entregado, recalcular diferencia/total_dia (el esperado no cambia
        // porque las ventas no se tocan en la edición ligera).
        if (array_key_exists('efectivo_entregado', $validated)) {
            $entregado = (float) $validated['efectivo_entregado'];
            $esperado  = (float) $reporte->efectivo_esperado;
            $validated['total_dia']           = $entregado;
            $validated['diferencia']          = round($entregado - $esperado, 2);
            $validated['requiere_aprobacion'] = abs($validated['diferencia']) > 10;
        }

        // Si la corrección venía de una edición autorizada, registrarla y cerrarla.
        if ($reporte->estado_edicion === 'APROBADO') {
            $validated['estado_edicion'] = 'CERRADO';
            HistorialReporte::create([
                'reporte_id' => $reporte->id,
                'usuario_id' => $request->user()?->id,
                'accion'     => 'edicion_aplicada',
                'detalle'    => $request->input('motivo_edicion') ?: 'Corrección de campos autorizados.',
            ]);
        }

        unset($validated['motivo_edicion']);

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
            'observacion'      => 'nullable|string|max:255',
        ]);

        $reporte->update(['destino_efectivo' => $validated['destino_efectivo']]);

        // Auditoría (paridad gerencia/marcar_entregado.php): registrar el movimiento + obs.
        HistorialReporte::create([
            'reporte_id' => $reporte->id,
            'usuario_id' => auth()->id(),
            'accion'     => 'cambio_destino',
            'detalle'    => 'Destino efectivo: ' . $validated['destino_efectivo']
                . (! empty($validated['observacion']) ? ' | Obs: ' . $validated['observacion'] : ''),
        ]);

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

    /**
     * B4 helper — crea las ventas normalizadas del reporte (línea/equipo), con comisión
     * server-side (B2) y descuento de stock con guardia (B1). Reutilizado por store() y reprocesar().
     */
    private function procesarVentas(Reporte $reporte, array $ventasData, string $tiendaId, int $agenteId, ComisionService $comisionService): void
    {
        // ID interno (numérico) de la tienda para inventario_chips.tienda_id (paridad legacy procesar_reporte).
        $idTiendaInterna = DB::table('tiendas')->where('codigo', $tiendaId)->value('id');

        foreach ($ventasData as $vd) {
            $cliente_id = null;
            if (! empty($vd['cliente_dni'])) {
                $dni = $vd['cliente_dni'];
                $cliente = Cliente::firstOrCreate(
                    ['dni_ruc' => $dni],
                    ['nombre' => 'Por identificar', 'tipo_documento' => strlen($dni) === 8 ? 'DNI' : 'RUC']
                );
                $cliente_id = $cliente->id;
            }

            // B2 — Comisión SERVER-AUTHORITATIVE (no se confía en el cliente).
            $comisionTotal = $comisionService->calcularComisionVenta($vd);

            $venta = Venta::create([
                'reporte_id'        => $reporte->id,
                'vendedor_id'       => $agenteId,
                'cliente_id'        => $cliente_id,
                'tipo_venta'        => $vd['tipo_venta'],
                'subtipo'           => $vd['subtipo'] ?? null,
                'cross_selling'     => (bool) ($vd['cross_selling'] ?? false),
                'tienda_destino'    => (($vd['cross_selling'] ?? false) || $vd['tipo_venta'] === 'APOYO')
                    ? ($vd['tienda_destino'] ?? null) : null,
                'monto_total'       => $vd['monto_total'],
                'efectivo_inicial'  => $vd['efectivo_inicial'] ?? $vd['monto_total'],
                'comision_generada' => $comisionTotal,
                'comision_estado'   => ($vd['tipo_venta'] === 'EQUIPO'
                    && strtoupper((string) ($vd['tipo_pago'] ?? 'CONTADO')) === 'CUOTAS') ? 'PENDIENTE' : 'ACTIVA',
                'es_remate'         => (bool) ($vd['es_remate'] ?? false),
                'es_extranjero'     => (bool) ($vd['es_extranjero'] ?? false),
            ]);

            if (in_array($vd['tipo_venta'], ['EQUIPO', 'ACCESORIO']) && ! empty($vd['producto_nombre'])) {
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
                        ? ((float) $vd['precio_venta'] - (float) $vd['costo_snap']) : null,
                    'por_cobrar_financiera' => $vd['por_cobrar_financiera'] ?? 0,
                ]);

                // B1 — Descuento de stock con guardia (rowCount !== 1 ⇒ throw ⇒ rollBack).
                $invId = (int) ($vd['inventario_tienda_id'] ?? 0);
                if ($invId > 0) {
                    // El WHERE cantidad>0 garantiza cantidad-1>=0 (sin GREATEST, portable a sqlite/mysql).
                    $update = ['cantidad' => DB::raw('cantidad - 1')];
                    if (Schema::hasColumn('inventario_tiendas', 'estado')) {
                        $update['estado'] = DB::raw("CASE WHEN cantidad - 1 <= 0 THEN 'VENDIDO' ELSE estado END");
                    }
                    if (Schema::hasColumn('inventario_tiendas', 'fecha_venta')) {
                        $update['fecha_venta'] = now();
                    }
                    if (Schema::hasColumn('inventario_tiendas', 'vendido_por_id')) {
                        $update['vendido_por_id'] = $agenteId;
                    }
                    if (Schema::hasColumn('inventario_tiendas', 'reporte_venta_id')) {
                        $update['reporte_venta_id'] = $reporte->id;
                    }

                    $afectadas = DB::table('inventario_tiendas')
                        ->where('id', $invId)
                        ->where('tienda_id', $tiendaId)
                        ->where('cantidad', '>', 0)
                        ->update($update);

                    if ($afectadas !== 1) {
                        throw new \RuntimeException(
                            "El producto \"{$vd['producto_nombre']}\" ya fue vendido, trasladado o no pertenece a esta tienda."
                        );
                    }
                }
            }

            if (in_array($vd['tipo_venta'], ['POSTPAGO', 'PREPAGO', 'APOYO'], true) && ! empty($vd['plan_nombre'])) {
                VentaLinea::create([
                    'venta_id'          => $venta->id,
                    'plan_nombre_snap'  => $vd['plan_nombre'],
                    'tipo_alta'         => $vd['tipo_alta'] ?? 'LN',
                    'cantidad'          => $vd['cantidad'] ?? 1,
                    'cobrado_unitario'  => $vd['cobrado_unitario'] ?? $vd['monto_total'],
                    'comision_unitaria' => round($comisionTotal / max(1, (int) ($vd['cantidad'] ?? 1)), 2),
                    'es_esim'           => (bool) ($vd['es_esim'] ?? false),
                ]);

                // Descuento de inventario_chips (paridad legacy procesar_reporte.php:227-301).
                // Migración/upgrade/eSIM no consumen chip físico.
                $consumeChip = ! ((bool) ($vd['es_migracion'] ?? false))
                    && ! ((bool) ($vd['es_upgrade'] ?? false))
                    && ! ((bool) ($vd['es_esim'] ?? false));

                if ($consumeChip && $idTiendaInterna) {
                    $cantidad = max(1, (int) ($vd['cantidad'] ?? 1));
                    if ($vd['tipo_venta'] === 'APOYO') {
                        // Apoyo: descuenta del lote del store destino que esta tienda tiene físicamente.
                        $origenCode  = (string) ($vd['tienda_destino'] ?? '');
                        $incluirNull = false;
                    } else {
                        // Postpago/Prepago: lote propio (tienda_origen = código propio o NULL).
                        $origenCode  = $tiendaId;
                        $incluirNull = true;
                    }

                    if ($origenCode !== '') {
                        $descontados = $this->descontarChips((int) $idTiendaInterna, $origenCode, $cantidad, $incluirNull);
                        if ($descontados > 0 && Schema::hasColumn('ventas', 'chips_descontados')) {
                            $venta->update(['chips_descontados' => $descontados]);
                        }
                        if ($descontados < $cantidad) {
                            Log::warning('Stock de chips insuficiente al guardar el cuadre.', [
                                'reporte_id'   => $reporte->id,
                                'tienda'       => $tiendaId,
                                'origen'       => $origenCode,
                                'solicitado'   => $cantidad,
                                'descontado'   => $descontados,
                            ]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Descuenta `cantidad` chips del bucket (tienda_id, tienda_origen=origen [, OR NULL]).
     * Soft-fail como el legacy: descuenta lo que haya, devuelve cuánto descontó (no lanza).
     */
    private function descontarChips(int $idTiendaInterna, string $origenCode, int $cantidad, bool $incluirNull): int
    {
        $restante = $cantidad;

        $lotes = DB::table('inventario_chips')
            ->where('tienda_id', $idTiendaInterna)
            ->where('stock_actual', '>', 0)
            ->where(function ($q) use ($origenCode, $incluirNull) {
                $q->where('tienda_origen', $origenCode);
                if ($incluirNull) {
                    $q->orWhereNull('tienda_origen');
                }
            })
            ->orderByRaw('tienda_origen = ? DESC', [$origenCode]) // primero el lote exacto
            ->orderByDesc('stock_actual')
            ->lockForUpdate()
            ->get();

        foreach ($lotes as $lote) {
            if ($restante <= 0) break;
            $quita = min($restante, (int) $lote->stock_actual);
            DB::table('inventario_chips')->where('id', $lote->id)->decrement('stock_actual', $quita);
            $restante -= $quita;
        }

        return $cantidad - $restante;
    }

    /**
     * Repone `cantidad` chips al lote canónico (tienda_id, tienda_origen=origen); lo crea si no existe.
     * Usado al revertir un reporte (edición) para no dejar el stock de chips descuadrado.
     */
    private function reponerChips(int $idTiendaInterna, string $origenCode, int $cantidad): void
    {
        if ($cantidad <= 0) {
            return;
        }

        $afectadas = DB::table('inventario_chips')
            ->where('tienda_id', $idTiendaInterna)
            ->where('tienda_origen', $origenCode)
            ->increment('stock_actual', $cantidad);

        if ($afectadas === 0) {
            DB::table('inventario_chips')->insert([
                'tienda_id'     => $idTiendaInterna,
                'tienda_origen' => $origenCode,
                'tipo_chip'     => 'FÍSICO',
                'stock_actual'  => $cantidad,
            ]);
        }
    }

    /**
     * B4 helper — revierte el stock de los equipos vendidos del reporte (lo devuelve a inventario)
     * y borra sus ventas/lineas/equipos. Usado por reprocesar() (edición 1:1).
     */
    private function revertirVentas(Reporte $reporte): void
    {
        $ventas = Venta::where('reporte_id', $reporte->id)->with('equipo')->get();

        // ID interno de la tienda para reponer inventario_chips al revertir.
        $idTiendaInterna = DB::table('tiendas')->where('codigo', $reporte->tienda_id)->value('id');
        $tieneChipsCol   = Schema::hasColumn('ventas', 'chips_descontados');

        foreach ($ventas as $venta) {
            // Reponer chips descontados al guardar (paridad: la edición revierte el stock previo).
            $chipsPrevios = $tieneChipsCol ? (int) ($venta->chips_descontados ?? 0) : 0;
            if ($chipsPrevios > 0 && $idTiendaInterna) {
                $origenCode = $venta->tipo_venta === 'APOYO'
                    ? (string) ($venta->tienda_destino ?? '')
                    : (string) $reporte->tienda_id;
                if ($origenCode !== '') {
                    $this->reponerChips((int) $idTiendaInterna, $origenCode, $chipsPrevios);
                }
            }

            $invId = (int) ($venta->equipo->inventario_tienda_id ?? 0);
            if ($venta->equipo && $invId > 0) {
                $update = ['cantidad' => DB::raw('cantidad + 1')];
                if (Schema::hasColumn('inventario_tiendas', 'estado')) {
                    $update['estado'] = 'DISPONIBLE';
                }
                if (Schema::hasColumn('inventario_tiendas', 'fecha_venta')) {
                    $update['fecha_venta'] = null;
                }
                if (Schema::hasColumn('inventario_tiendas', 'reporte_venta_id')) {
                    $update['reporte_venta_id'] = null;
                }
                DB::table('inventario_tiendas')->where('id', $invId)->update($update);
            }
        }

        $ids = $ventas->pluck('id');
        VentaEquipo::whereIn('venta_id', $ids)->delete();
        VentaLinea::whereIn('venta_id', $ids)->delete();
        Venta::whereIn('id', $ids)->delete();
    }

    // ── PUT /reportes/{reporte}/reprocesar — Edición 1:1 (B4, paridad procesar_edicion) ──
    // Revierte stock → borra ventas → re-procesa todo. Requiere edición autorizada (estado_edicion=APROBADO).
    public function reprocesar(Request $request, Reporte $reporte): JsonResponse
    {
        if ($reporte->estado_edicion !== 'APROBADO') {
            return response()->json(['error' => 'La edición no fue autorizada por administración.'], 422);
        }

        $validated = $request->validate([
            'agente_id'                      => 'required|integer',
            'tienda_id'                      => 'required|string|max:50',
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
            'ventas.*.tipo_venta'            => 'required_with:ventas|in:EQUIPO,ACCESORIO,POSTPAGO,PREPAGO,OTROS_FLUJO,APOYO',
            'ventas.*.subtipo'               => 'nullable|string|max:50',
            'ventas.*.monto_total'           => 'required_with:ventas|numeric|min:0',
            'ventas.*.efectivo_inicial'      => 'nullable|numeric|min:0',
            'ventas.*.cross_selling'         => 'nullable|boolean',
            'ventas.*.tienda_destino'        => 'nullable|string|max:10',
            'ventas.*.es_remate'             => 'nullable|boolean',
            'ventas.*.es_extranjero'         => 'nullable|boolean',
            'ventas.*.es_migracion'          => 'nullable|boolean',
            'ventas.*.es_upgrade'            => 'nullable|boolean',
            'ventas.*.es_esim'               => 'nullable|boolean',
            'ventas.*.plan_anterior'         => 'nullable|numeric|min:0',
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
        ]);

        DB::beginTransaction();
        try {
            $ventas_data        = $validated['ventas'] ?? [];
            $svc                = new ComisionService();
            $total_salidas      = (float) ($validated['total_salidas'] ?? 0);
            $yape               = (float) ($validated['yape'] ?? 0);
            $bipay              = (float) ($validated['bipay'] ?? 0);
            $transferencia      = (float) ($validated['transferencia'] ?? 0);
            $retiro_bipay       = (float) ($validated['retiro_bipay'] ?? 0);
            $efectivo_entregado = (float) $validated['efectivo_entregado'];

            $total_calculado   = (float) collect($ventas_data)->sum('monto_total');
            $total_sistema     = $total_calculado
                + (float) ($validated['recarga_bipay'] ?? 0)
                + (float) ($validated['pago_servicio'] ?? 0)
                + (float) ($validated['pago_krece'] ?? 0)
                + (float) ($validated['tickets_tusamy'] ?? 0);
            $total_no_fisico   = $yape + $bipay + $transferencia + $retiro_bipay;
            $efectivo_esperado = round($total_sistema - $total_no_fisico - $total_salidas, 2);
            $diferencia        = round($efectivo_entregado - $efectivo_esperado, 2);

            // 1) Revertir stock + borrar ventas previas (paridad legacy: anulación completa).
            $this->revertirVentas($reporte);

            // 2) Re-escribir cabecera con los nuevos totales.
            $reporte->update([
                'total_dia'           => $efectivo_entregado,
                'total_calculado'     => $total_calculado,
                'yape'                => $yape,
                'bipay'               => $bipay,
                'recarga_bipay'       => (float) ($validated['recarga_bipay'] ?? 0),
                'pago_servicio'       => (float) ($validated['pago_servicio'] ?? 0),
                'pago_krece'          => (float) ($validated['pago_krece'] ?? 0),
                'tickets_tusamy'      => (float) ($validated['tickets_tusamy'] ?? 0),
                'retiro_bipay'        => $retiro_bipay,
                'transferencia'       => $transferencia,
                'caja_inicial'        => (float) $validated['caja_inicial'],
                'efectivo_entregado'  => $efectivo_entregado,
                'total_salidas'       => $total_salidas,
                'efectivo_esperado'   => $efectivo_esperado,
                'diferencia'          => $diferencia,
                'requiere_aprobacion' => abs($diferencia) > 10,
                'observaciones'       => $validated['observaciones'] ?? $reporte->observaciones,
                'obs_dia'             => $validated['obs_dia'] ?? $reporte->obs_dia,
                'destino_efectivo'    => $validated['destino_efectivo'] ?? $reporte->destino_efectivo,
                'estado_edicion'      => 'CERRADO',
            ]);

            // 3) Re-procesar las nuevas ventas (stock + comisión server-side).
            $this->procesarVentas($reporte, $ventas_data, (string) $validated['tienda_id'], (int) $validated['agente_id'], $svc);

            HistorialReporte::create([
                'reporte_id' => $reporte->id,
                'usuario_id' => $request->user()?->id,
                'accion'     => 'edicion_aplicada',
                'detalle'    => 'Reporte re-procesado tras edición autorizada.',
            ]);

            DB::commit();

            return response()->json($reporte->fresh()->load('ventas'));
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage(), 'code' => 'STOCK_GUARD'], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al reprocesar: ' . $e->getMessage()], 500);
        }
    }

    // ── POST /reporte-categorias/{id}/fijar-costo — Campana: fijar costo rápido
    public function fijarCosto(Request $request, int $ventaEquipoId): JsonResponse
    {
        $user = $request->user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        $validated = $request->validate([
            'precio_costo' => 'required|numeric|gt:0',
            'precio_venta' => 'nullable|numeric|min:0',
        ]);

        if ($ventaEquipoId <= 0) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos.'], 422);
        }

        $ventaEquipo = VentaEquipo::find($ventaEquipoId);

        if (! $ventaEquipo) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado.'], 404);
        }

        $precioCosto = round((float) $validated['precio_costo'], 2);
        $precioVenta = array_key_exists('precio_venta', $validated) && $validated['precio_venta'] !== null
            ? round((float) $validated['precio_venta'], 2)
            : (float) $ventaEquipo->precio_venta;
        $ganancia = round($precioVenta - $precioCosto, 2);

        $ventaEquipo->update([
            'precio_venta' => $precioVenta,
            'costo_snap' => $precioCosto,
            'ganancia_snap' => $ganancia,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'Costo actualizado correctamente.',
            'ganancia' => $ganancia,
            'costo'    => $precioCosto,
        ]);
    }
}
