<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventarioChip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChipsController extends Controller
{
    // ── GET /chips — Stock de chips por tienda ─────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->rol === 'admin';

        $query = InventarioChip::with('tienda')
            ->where('stock_actual', '>', 0);

        if (!$esAdmin) {
            $tiendaId = DB::table('tiendas')->where('codigo', $user->tienda_id)->value('id');
            if ($tiendaId) $query->where('tienda_id', $tiendaId);
        }

        if ($request->filled('tienda_id')) {
            $query->where('tienda_id', $request->tienda_id);
        }

        return response()->json(['data' => $query->orderByDesc('stock_actual')->get()]);
    }

    // ── POST /chips — Agregar stock manualmente (admin) ───────────────────────
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }

        $tiendaId     = (int) $request->input('tienda_id', 0);
        $tiendaOrigen = trim($request->input('tienda_origen', ''));
        $tipoChip     = trim($request->input('tipo_chip', 'FÍSICO'));
        $cantidad     = (int) $request->input('cantidad', 0);

        if (!$tiendaId || !$tiendaOrigen || $cantidad <= 0) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos.'], 422);
        }

        $existente = InventarioChip::where('tienda_id', $tiendaId)
            ->where('tienda_origen', $tiendaOrigen)
            ->first();

        if ($existente) {
            $existente->increment('stock_actual', $cantidad);
            $chip = $existente->fresh();
        } else {
            $chip = InventarioChip::create([
                'tienda_id'    => $tiendaId,
                'tienda_origen' => $tiendaOrigen,
                'tipo_chip'    => $tipoChip,
                'stock_actual' => $cantidad,
            ]);
        }

        $this->registrarHistorial($tiendaId, null, 'SUMA', $cantidad, $tiendaOrigen, "Ingreso manual de {$cantidad} chip(s)");

        return response()->json([
            'success' => true,
            'message' => "{$cantidad} chip(s) agregados correctamente.",
            'data'    => $chip,
        ]);
    }

    // ── POST /chips/{id}/cambiar-codigo — Mover chips a otro código ───────────
    public function cambiarCodigo(Request $request, int $id): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->rol === 'admin';

        $codigoDestino = trim($request->input('codigo_destino', ''));
        $cantidad      = (int) $request->input('cantidad', 0);

        if (!$codigoDestino || $cantidad <= 0) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos o incompletos.'], 422);
        }

        $result = DB::transaction(function () use ($id, $codigoDestino, $cantidad, $user, $esAdmin) {
            $origen = InventarioChip::where('id', $id)->lockForUpdate()->first();
            if (!$origen) return ['error' => 'Registro origen no encontrado.'];

            // Verificar propiedad
            if (!$esAdmin) {
                $tiendaCodigo = DB::table('tiendas')->where('id', $origen->tienda_id)->value('codigo');
                if ($tiendaCodigo !== $user->tienda_id) {
                    return ['error' => 'No tienes permiso sobre este registro.'];
                }
            }

            $codigoActual = $origen->tienda_origen ?: DB::table('tiendas')->where('id', $origen->tienda_id)->value('codigo');

            if ($codigoDestino === $codigoActual) {
                return ['error' => 'El código destino es igual al código origen.'];
            }
            if ($origen->stock_actual < $cantidad) {
                return ['error' => "Stock insuficiente. Disponible: {$origen->stock_actual} ud."];
            }

            $origen->decrement('stock_actual', $cantidad);

            $destino = InventarioChip::where('tienda_id', $origen->tienda_id)
                ->where('tienda_origen', $codigoDestino)
                ->first();

            if ($destino) {
                $destino->increment('stock_actual', $cantidad);
            } else {
                InventarioChip::create([
                    'tienda_id'     => $origen->tienda_id,
                    'tienda_origen' => $codigoDestino,
                    'tipo_chip'     => 'FÍSICO',
                    'stock_actual'  => $cantidad,
                ]);
            }

            return ['ok' => true, 'codigoActual' => $codigoActual];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "{$cantidad} chip(s) movidos de [{$result['codigoActual']}] → [{$codigoDestino}] exitosamente.",
        ]);
    }

    // ── DELETE /chips/{id} — Eliminar lote (solo admin) ───────────────────────
    public function destroy(int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }

        $chip = InventarioChip::find($id);
        if (!$chip) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado.'], 404);
        }

        $this->registrarHistorial(
            $chip->tienda_id, null, 'ELIMINACION', $chip->stock_actual,
            $chip->tienda_origen, "Eliminación de lote de chips (admin)"
        );
        $chip->delete();

        return response()->json(['success' => true, 'message' => 'Lote de chips eliminado correctamente.']);
    }

    // ── GET /chips/{id}/historial ──────────────────────────────────────────────
    public function historial(int $id): JsonResponse
    {
        $chip = InventarioChip::with('tienda')->find($id);
        if (!$chip) {
            return response()->json(['success' => false, 'message' => 'Lote de chips no encontrado.'], 404);
        }

        $tiendaId    = $chip->tienda_id;
        $tiendaCodigo = $chip->tienda?->codigo ?? '';
        $codigoOrigen = $chip->tienda_origen ?: $tiendaCodigo;

        $timeline = [];

        // Correcciones manuales
        $historial = DB::table('historial_inventario as h')
            ->leftJoin('agentes as a', 'h.agente_id', '=', 'a.id')
            ->where('h.tienda_id', $tiendaId)
            ->whereRaw('h.tienda_origen COLLATE utf8mb4_unicode_ci = ?', [$codigoOrigen])
            ->whereNull('h.producto_id')
            ->orderByDesc('h.fecha_hora')
            ->select('h.fecha_hora', 'h.accion', 'h.cantidad', 'h.observacion',
                     'h.stock_anterior', 'h.stock_nuevo', 'a.nombres as agente_nombre')
            ->get();

        foreach ($historial as $row) {
            $signo = $row->accion === 'SUMA' ? '+' : '-';
            $timeline[] = [
                'fecha_hora'     => $row->fecha_hora,
                'tipo'           => $row->accion === 'SUMA' ? 'Ingreso (Corrección)' : ($row->accion === 'ELIMINACION' ? 'Eliminación' : 'Salida (Corrección)'),
                'cantidad'       => $signo . $row->cantidad,
                'stock_anterior' => $row->stock_anterior,
                'stock_nuevo'    => $row->stock_nuevo,
                'detalle'        => $row->observacion,
                'agente'         => $row->agente_nombre ?? 'Sistema',
            ];
        }

        // Traslados entrantes confirmados
        $trasladosIn = DB::table('traslados_chips as tc')
            ->leftJoin('inventario_chips as ic', 'tc.chip_id_origen', '=', 'ic.id')
            ->leftJoin('agentes as ae', 'tc.enviado_por_id', '=', 'ae.id')
            ->leftJoin('agentes as ac', 'tc.confirmado_por_id', '=', 'ac.id')
            ->whereRaw('tc.tienda_destino COLLATE utf8mb4_unicode_ci = ?', [$tiendaCodigo])
            ->where('tc.estado', 'CONFIRMADO')
            ->whereRaw(
                '(COALESCE(ic.tienda_origen, tc.tienda_origen) COLLATE utf8mb4_unicode_ci = ? OR tc.tienda_origen COLLATE utf8mb4_unicode_ci = ?)',
                [$codigoOrigen, $codigoOrigen]
            )
            ->select('tc.fecha_confirmacion', 'tc.cantidad', 'tc.tienda_origen',
                     'tc.observacion_recepcion', 'ac.nombres as conf_nombre')
            ->get();

        foreach ($trasladosIn as $row) {
            $timeline[] = [
                'fecha_hora' => $row->fecha_confirmacion,
                'tipo'       => 'Ingreso (Traslado)',
                'cantidad'   => '+' . $row->cantidad,
                'detalle'    => "Traslado desde {$row->tienda_origen}" . ($row->observacion_recepcion ? " ({$row->observacion_recepcion})" : ''),
                'agente'     => $row->conf_nombre ?? 'Sistema',
            ];
        }

        // Traslados salientes confirmados
        $trasladosOut = DB::table('traslados_chips as tc')
            ->leftJoin('inventario_chips as ic', 'tc.chip_id_origen', '=', 'ic.id')
            ->leftJoin('agentes as ae', 'tc.enviado_por_id', '=', 'ae.id')
            ->whereRaw('tc.tienda_origen COLLATE utf8mb4_unicode_ci = ?', [$tiendaCodigo])
            ->where('tc.estado', 'CONFIRMADO')
            ->whereRaw(
                '(COALESCE(ic.tienda_origen, tc.tienda_origen) COLLATE utf8mb4_unicode_ci = ? OR tc.tienda_origen COLLATE utf8mb4_unicode_ci = ?)',
                [$codigoOrigen, $codigoOrigen]
            )
            ->select('tc.fecha_confirmacion', 'tc.cantidad', 'tc.tienda_destino',
                     'tc.observacion_recepcion', 'ae.nombres as enviado_nombre')
            ->get();

        foreach ($trasladosOut as $row) {
            $timeline[] = [
                'fecha_hora' => $row->fecha_confirmacion,
                'tipo'       => 'Salida (Traslado)',
                'cantidad'   => '-' . $row->cantidad,
                'detalle'    => "Traslado a {$row->tienda_destino}" . ($row->observacion_recepcion ? " ({$row->observacion_recepcion})" : ''),
                'agente'     => $row->enviado_nombre ?? 'Sistema',
            ];
        }

        // Ordenar por fecha desc
        usort($timeline, fn($a, $b) => strcmp($b['fecha_hora'] ?? '', $a['fecha_hora'] ?? ''));

        // Calcular saldos Kardex (de más reciente a más antiguo)
        $running = $chip->stock_actual;
        foreach ($timeline as &$entry) {
            $delta = (int)$entry['cantidad'];
            $entry['stock_nuevo']    = $running;
            $entry['stock_anterior'] = $running - $delta;
            $running = $entry['stock_anterior'];
        }
        unset($entry);

        return response()->json([
            'success'        => true,
            'stock_restante' => $chip->stock_actual,
            'timeline'       => $timeline,
        ]);
    }

    private function registrarHistorial(int $tiendaId, ?int $agenteId, string $accion, int $cantidad, string $tiendaOrigen, string $observacion): void
    {
        try {
            DB::table('historial_inventario')->insert([
                'tienda_id'    => $tiendaId,
                'agente_id'    => $agenteId,
                'accion'       => $accion,
                'cantidad'     => $cantidad,
                'tienda_origen' => $tiendaOrigen,
                'observacion'  => $observacion,
                'producto_id'  => null,
                'fecha_hora'   => now(),
            ]);
        } catch (\Throwable) {}
    }
}
