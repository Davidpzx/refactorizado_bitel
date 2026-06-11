<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventarioTienda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = InventarioTienda::query()
            ->when($request->q,      fn($q, $t) => $q->buscar($t))
            ->when($request->tienda, fn($q, $t) => $q->porTienda($t))
            ->when($request->tipo,   fn($q, $t) => $q->porTipo($t))
            ->when($request->estado, fn($q, $e) => $q->porEstado($e))
            ->orderByDesc('fecha_registro')
            ->paginate($request->integer('per_page', 20));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tienda_id'         => 'required|string|max:50',
            'producto_nombre'   => 'required|string|max:150',
            'tipo'              => 'required|in:EQUIPO,ACCESORIO,CHIP',
            'imei_serial'       => 'nullable|string|max:50|unique:inventario_tiendas,imei_serial',
            'precio_costo'      => 'required|numeric|min:0',
            'precio_minimo'     => 'required|numeric|min:0',
            'precio_normal'     => 'required|numeric|min:0',
            'cantidad'          => 'required|integer|min:1',
            'estado'            => 'required|in:DISPONIBLE,VENDIDO,TRASLADO',
            'comision_especial' => 'nullable|numeric|min:0',
        ], [
            'tienda_id.required'       => 'La tienda es obligatoria.',
            'producto_nombre.required' => 'El nombre del producto es obligatorio.',
            'tipo.required'            => 'El tipo es obligatorio.',
            'tipo.in'                  => 'El tipo debe ser EQUIPO, ACCESORIO o CHIP.',
            'imei_serial.unique'       => 'Este IMEI/serie ya está registrado.',
            'precio_costo.required'    => 'El precio de costo es obligatorio.',
            'precio_normal.required'   => 'El precio normal es obligatorio.',
            'cantidad.required'        => 'La cantidad es obligatoria.',
            'estado.required'          => 'El estado es obligatorio.',
            'estado.in'                => 'El estado debe ser DISPONIBLE, VENDIDO o TRASLADO.',
        ]);

        $validated['fecha_registro'] = now();

        $item = InventarioTienda::create($validated);

        return response()->json($item, 201);
    }

    public function show(InventarioTienda $inventario): JsonResponse
    {
        return response()->json($inventario);
    }

    public function update(Request $request, InventarioTienda $inventario): JsonResponse
    {
        $validated = $request->validate([
            'tienda_id'         => 'sometimes|string|max:50',
            'producto_nombre'   => 'sometimes|string|max:150',
            'tipo'              => 'sometimes|in:EQUIPO,ACCESORIO,CHIP',
            'imei_serial'       => 'sometimes|nullable|string|max:50|unique:inventario_tiendas,imei_serial,' . $inventario->id,
            'precio_costo'      => 'sometimes|numeric|min:0',
            'precio_minimo'     => 'sometimes|numeric|min:0',
            'precio_normal'     => 'sometimes|numeric|min:0',
            'cantidad'          => 'sometimes|integer|min:0',
            'estado'            => 'sometimes|in:DISPONIBLE,VENDIDO,TRASLADO',
            'comision_especial' => 'sometimes|nullable|numeric|min:0',
        ]);

        $inventario->update($validated);

        return response()->json($inventario->fresh());
    }

    public function destroy(InventarioTienda $inventario): JsonResponse
    {
        $inventario->delete();
        return response()->json(null, 204);
    }

    // ── POST /inventario/{id}/recalcular-ganancias — Actualiza JSON detalle ─────
    public function recalcularGanancias(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'msg' => 'Solo administradores.'], 403);
        }

        $prod = InventarioTienda::find($id);
        if (!$prod) {
            return response()->json(['success' => false, 'msg' => 'Producto no encontrado.'], 404);
        }

        $nuevoCosto = round((float) $request->input('nuevo_costo', $prod->precio_costo), 2);
        if ($nuevoCosto < 0) {
            return response()->json(['success' => false, 'msg' => 'El costo debe ser >= 0.'], 422);
        }

        $imei   = $prod->imei_serial;
        $nombre = $prod->producto_nombre;

        $query = DB::table('reporte_categorias')->where('tipo', 'equipos_accesorios');
        if (!empty($imei)) {
            $query->whereRaw("JSON_CONTAINS(detalle, JSON_OBJECT('identificador', ?))", [$imei]);
        } else {
            $query->whereRaw("JSON_CONTAINS(detalle, JSON_OBJECT('producto', ?))", [$nombre]);
        }

        $filas = $query->select('id', 'detalle')->limit(200)->get();
        if ($filas->isEmpty()) {
            return response()->json(['success' => true, 'msg' => 'Sin ventas asociadas.', 'updated' => 0]);
        }

        $updated = 0;
        DB::beginTransaction();
        try {
            foreach ($filas as $fila) {
                $detalle = json_decode($fila->detalle, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($detalle)) continue;

                $items = isset($detalle[0]) ? $detalle : [$detalle];
                foreach ($items as &$item) {
                    if (!is_array($item)) continue;
                    $pv = floatval($item['precio_normal_agente'] ?? $item['precio_total'] ?? 0);
                    $item['costo_al_registrar'] = $nuevoCosto;
                    $item['ganancia']           = $pv - $nuevoCosto;
                    $item['precio_costo']       = $nuevoCosto;
                }
                unset($item);
                $nuevo = isset($detalle[0]) ? $items : $items[0];

                DB::table('reporte_categorias')->where('id', $fila->id)
                    ->update(['detalle' => json_encode($nuevo, JSON_UNESCAPED_UNICODE)]);
                $updated++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'msg' => 'Error: ' . $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'msg' => "Se actualizaron {$updated} registros.", 'updated' => $updated]);
    }

    // ── GET /inventario/kardex — Kardex con historial de ventas ─────────────────
    public function kardex(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->rol === 'admin';
        $tienda  = trim($request->input('tienda', ''));

        if (!$esAdmin) {
            $tienda = $user->tienda_id ?? '';
        }

        $centinelas = ['', 'all', 'todas', '0'];

        $where  = "WHERE it.tipo IN ('EQUIPO', 'ACCESORIO')";
        $params = [];

        if (!$esAdmin) {
            $where   .= " AND it.tienda_id COLLATE utf8mb4_unicode_ci = ?";
            $params[] = $tienda;
        } elseif (!in_array(strtolower($tienda), $centinelas, true)) {
            $where   .= " AND it.tienda_id COLLATE utf8mb4_unicode_ci = ?";
            $params[] = $tienda;
        }

        $estado = strtoupper(trim($request->input('estado', '')));
        if ($estado && !in_array($estado, ['', 'ALL', 'TODOS', '0'], true)) {
            $where   .= " AND it.estado = ?";
            $params[] = $estado;
        }

        $rows = DB::select("
            SELECT
                it.id,
                it.producto_nombre                                    AS nombre,
                it.tipo,
                it.imei_serial                                        AS imei,
                it.tienda_id                                          AS tienda,
                COALESCE(ti.nombre, it.tienda_id)                    AS tienda_nombre,
                DATE_FORMAT(it.fecha_registro, '%d/%m/%Y')           AS fecha_ingreso,
                it.estado,
                it.precio_costo,
                COALESCE(
                    DATE_FORMAT(it.fecha_venta, '%d/%m/%Y'),
                    (SELECT DATE_FORMAT(h.fecha_hora, '%d/%m/%Y')
                     FROM historial_inventario h
                     WHERE h.producto_id = it.id AND h.accion = 'RESTA'
                     ORDER BY h.fecha_hora DESC LIMIT 1),
                    (SELECT DATE_FORMAT(r.fecha, '%d/%m/%Y')
                     FROM reporte_categorias rc JOIN reportes r ON r.id = rc.reporte_id
                     WHERE rc.tipo = 'equipos_accesorios'
                       AND it.imei_serial IS NOT NULL AND it.imei_serial <> ''
                       AND JSON_UNQUOTE(JSON_EXTRACT(rc.detalle, '\$.identificador')) = it.imei_serial
                     ORDER BY r.fecha DESC LIMIT 1)
                ) AS fecha_venta,
                COALESCE(
                    (SELECT a_n.nombres FROM agentes a_n WHERE a_n.id = it.vendido_por_id LIMIT 1),
                    (SELECT a_h.nombres FROM historial_inventario hi
                     LEFT JOIN agentes a_h ON a_h.id = hi.agente_id
                     WHERE hi.producto_id = it.id AND hi.accion = 'RESTA'
                     ORDER BY hi.fecha_hora DESC LIMIT 1),
                    (SELECT a_v.nombres FROM reporte_categorias rc2
                     JOIN agentes a_v ON a_v.id = CAST(
                         NULLIF(JSON_UNQUOTE(JSON_EXTRACT(rc2.detalle, '\$.vendedor_id')), '') AS UNSIGNED)
                     WHERE rc2.tipo = 'equipos_accesorios'
                       AND it.imei_serial IS NOT NULL AND it.imei_serial <> ''
                       AND JSON_UNQUOTE(JSON_EXTRACT(rc2.detalle, '\$.identificador')) = it.imei_serial
                     ORDER BY rc2.id DESC LIMIT 1)
                ) AS agente,
                COALESCE(
                    NULLIF(it.precio_normal, 0),
                    (SELECT CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(rc3.detalle, '\$.precio_total')), 'null') AS DECIMAL(10,2))
                     FROM reporte_categorias rc3
                     WHERE rc3.tipo = 'equipos_accesorios'
                       AND it.imei_serial IS NOT NULL AND it.imei_serial <> ''
                       AND JSON_UNQUOTE(JSON_EXTRACT(rc3.detalle, '\$.identificador')) = it.imei_serial
                     ORDER BY rc3.id DESC LIMIT 1)
                ) AS precio,
                COALESCE(
                    (SELECT CASE
                        WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(rc4.detalle, '\$.tipo_pago'))) = 'CUOTAS' THEN 1
                        ELSE 0 END
                     FROM reporte_categorias rc4
                     WHERE rc4.tipo = 'equipos_accesorios'
                       AND it.imei_serial IS NOT NULL AND it.imei_serial <> ''
                       AND JSON_UNQUOTE(JSON_EXTRACT(rc4.detalle, '\$.identificador')) = it.imei_serial
                     ORDER BY rc4.id DESC LIMIT 1), 0
                ) AS es_cuota
            FROM inventario_tiendas it
            LEFT JOIN tiendas ti ON ti.codigo COLLATE utf8mb4_unicode_ci = it.tienda_id COLLATE utf8mb4_unicode_ci
            $where
            ORDER BY it.fecha_registro DESC
            LIMIT 150
        ", $params);

        foreach ($rows as &$r) {
            $r = (array) $r;
            $r['precio']       = floatval($r['precio']       ?? 0);
            $r['es_cuota']     = (int)($r['es_cuota']        ?? 0);
            $r['precio_costo'] = floatval($r['precio_costo'] ?? 0);
        }

        return response()->json(['ok' => true, 'rows' => $rows]);
    }

    // ── GET /inventario/stock-estancado — Items DISPONIBLE sin movimiento 30+ días ──
    public function stockEstancado(Request $request): JsonResponse
    {
        $user    = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['ok' => false, 'data' => [], 'capital_inmovilizado' => 0]);
        }

        $rows = DB::select("
            SELECT i.tienda_id, t.nombre AS nombre_tienda, i.tipo, i.producto_nombre,
                   i.cantidad, i.precio_costo,
                   DATEDIFF(CURRENT_DATE, i.fecha_registro) AS dias_estancado
            FROM inventario_tiendas i
            LEFT JOIN tiendas t
                   ON i.tienda_id COLLATE utf8mb4_unicode_ci = t.codigo COLLATE utf8mb4_unicode_ci
            WHERE i.estado = 'DISPONIBLE'
              AND i.fecha_registro <= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
            ORDER BY dias_estancado DESC
            LIMIT 15
        ");

        $capital = 0;
        $data    = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $r['precio_costo'] = floatval($r['precio_costo']);
            $r['cantidad']     = (int)($r['cantidad']);
            $r['capital']      = $r['cantidad'] * $r['precio_costo'];
            $capital += $r['capital'];
            $data[] = $r;
        }

        return response()->json(['ok' => true, 'data' => $data, 'capital_inmovilizado' => $capital]);
    }

    // ── GET /inventario/campana-costos — Ventas sin precio de costo (admin) ─────
    public function campanaCostos(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['ok' => true, 'count' => 0, 'data' => []]);
        }

        $rows = DB::select("
            SELECT rc.id AS rc_id,
                   IFNULL(JSON_UNQUOTE(JSON_EXTRACT(rc.detalle, '\$.producto')),
                          JSON_UNQUOTE(JSON_EXTRACT(rc.detalle, '\$.descripcion'))) AS producto,
                   JSON_UNQUOTE(JSON_EXTRACT(rc.detalle, '\$.identificador')) AS imei,
                   JSON_UNQUOTE(JSON_EXTRACT(rc.detalle, '\$.precio_total'))  AS precio_venta,
                   r.tienda_id,
                   r.fecha
            FROM reporte_categorias rc
            JOIN reportes r ON rc.reporte_id = r.id
            WHERE rc.tipo = 'equipos_accesorios'
              AND (
                  JSON_EXTRACT(rc.detalle, '\$.costo_al_registrar') IS NULL
                  OR JSON_EXTRACT(rc.detalle, '\$.costo_al_registrar') = 0
                  OR JSON_UNQUOTE(JSON_EXTRACT(rc.detalle, '\$.costo_al_registrar')) = '0'
                  OR JSON_UNQUOTE(JSON_EXTRACT(rc.detalle, '\$.costo_al_registrar')) = ''
                  OR JSON_EXTRACT(rc.detalle, '\$.ganancia') IS NULL
              )
            ORDER BY r.fecha DESC
            LIMIT 50
        ");

        $data = array_map(fn($r) => (array)$r, $rows);
        return response()->json(['ok' => true, 'count' => count($data), 'data' => $data]);
    }

    // ── GET /inventario/exportar-kardex — CSV del kardex ─────────────────────────
    public function exportarKardex(Request $request)
    {
        $user    = Auth::user();
        $esAdmin = $user->rol === 'admin';
        $tienda  = trim($request->input('tienda', ''));
        if (!$esAdmin) $tienda = $user->tienda_id ?? '';

        $where = "WHERE it.tipo IN ('EQUIPO', 'ACCESORIO')";
        $params = [];
        if ($tienda && !in_array(strtolower($tienda), ['', 'all', 'todas', '0'], true)) {
            $where .= " AND it.tienda_id = ?";
            $params[] = $tienda;
        }

        $rows = DB::select("
            SELECT it.tienda_id AS tienda, it.tipo, it.producto_nombre AS producto,
                   it.imei_serial AS imei, it.estado,
                   DATE_FORMAT(it.fecha_registro,'%d/%m/%Y') AS fecha_ingreso,
                   it.precio_costo, it.precio_normal AS precio_venta
            FROM inventario_tiendas it $where
            ORDER BY it.tienda_id, it.fecha_registro DESC
        ", $params);

        $bom  = "\xEF\xBB\xBF";
        $head = "Tienda,Tipo,Producto,IMEI/Serie,Estado,Fecha Ingreso,Costo,Precio Venta\n";
        $body = '';
        foreach ($rows as $r) {
            $r = (array)$r;
            $body .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v ?? '') . '"', $r)) . "\n";
        }

        return response($bom . $head . $body, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="kardex_' . date('Y-m-d') . '.csv"',
        ]);
    }

    // ── POST /inventario/{id}/restaurar — Rescate manual (solo admin) ──────────
    public function restaurar(int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['ok' => false, 'message' => 'Solo administradores.'], 403);
        }

        $equipo = InventarioTienda::find($id);
        if (!$equipo) {
            return response()->json(['ok' => false, 'message' => 'Equipo no encontrado en inventario.'], 404);
        }
        if ($equipo->estado !== 'VENDIDO') {
            return response()->json([
                'ok'      => false,
                'message' => "El equipo no está en estado VENDIDO (estado actual: {$equipo->estado}).",
            ], 422);
        }

        $equipo->update([
            'estado'         => 'DISPONIBLE',
            'fecha_venta'    => null,
            'vendido_por_id' => null,
        ]);

        try {
            DB::table('historial_inventario')->insert([
                'producto_id'  => $id,
                'agente_id'    => null,
                'accion'       => 'RESCATE_MANUAL',
                'cantidad'     => 1,
                'observacion'  => 'Restauración manual por admin desde Kardex de Inventario',
                'fecha_hora'   => now(),
            ]);
        } catch (\Throwable) {}

        return response()->json([
            'ok'      => true,
            'message' => "Equipo \"{$equipo->producto_nombre}\" devuelto al stock disponible.",
        ]);
    }
}
