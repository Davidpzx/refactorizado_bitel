<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventarioChip;
use App\Models\InventarioTienda;
use App\Models\Usuario;
use App\Models\VentaEquipo;
use App\Services\ReporteDetalleNormalizer;
use App\Support\Permisos;
use App\Support\PlanillaGuard;
use App\Support\TiendaGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = InventarioTienda::query()
            ->when($request->q,      fn($q, $t) => $q->buscar($t))
            ->when(! $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE), fn($q) => $q->porTienda($user->tienda_id))
            ->when($user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE) && $request->filled('tienda'), fn($q) => $q->porTienda($request->tienda))
            ->when($request->tipo,   fn($q, $t) => $q->porTipo($t))
            ->when($request->estado, fn($q, $e) => $q->porEstado($e))
            ->orderByDesc('fecha_registro')
            ->paginate($request->integer('per_page', 20));

        return response()->json($items);
    }

    // ── GET /inventario/precios-pendientes — Stock DISPONIBLE sin precios (admin) ──
    public function preciosPendientes(Request $request): JsonResponse
    {
        if (! Schema::hasTable('inventario_tiendas')) {
            return response()->json(['data' => [], 'total' => 0, 'tiendas' => []]);
        }

        $filtroPrecios = fn ($q) => $q
            ->whereNull('precio_minimo')->orWhere('precio_minimo', '<=', 0)
            ->orWhereNull('precio_normal')->orWhere('precio_normal', '<=', 0)
            ->orWhereNull('precio_costo')->orWhere('precio_costo', '<=', 0);

        $base = DB::table('inventario_tiendas')
            ->where('estado', 'DISPONIBLE')
            ->where('tipo', '!=', 'CHIP')
            ->where($filtroPrecios);

        $items = (clone $base)
            ->when($request->filled('tienda'), fn ($q) => $q->where('tienda_id', $request->tienda))
            ->select([
                'id', 'tienda_id', 'producto_nombre', 'tipo', 'imei_serial',
                'cantidad', 'precio_costo', 'precio_minimo', 'precio_normal', 'fecha_registro',
            ])
            ->orderBy('tienda_id')->orderBy('producto_nombre')
            ->get();

        $tiendas = (clone $base)->distinct()->orderBy('tienda_id')->pluck('tienda_id');

        return response()->json([
            'data' => $items,
            'total' => $items->count(),
            'tiendas' => $tiendas,
        ]);
    }

    // ── GET /inventario/precios-matriz — Matriz COMPLETA de precios (admin) ──────
    // Paridad legacy gerencia/revisar_stock.php pero SIN el filtro de "solo pendientes":
    // devuelve TODOS los items DISPONIBLE no-CHIP con su precio actual, ordenados por
    // tienda_id + tipo (control-break del legacy) + producto_nombre, con filtros
    // tienda / tipo (categoría) / q (búsqueda). Reusa inventario_tiendas igual que
    // preciosPendientes; el guardado de precios se hace vía PUT /inventario/{id}.
    public function preciosMatriz(Request $request): JsonResponse
    {
        if (! Schema::hasTable('inventario_tiendas')) {
            return response()->json(['data' => [], 'total' => 0, 'tiendas' => []]);
        }

        $base = DB::table('inventario_tiendas')
            ->where('estado', 'DISPONIBLE')
            ->where('tipo', '!=', 'CHIP');

        $q = trim((string) $request->input('q', ''));

        $items = (clone $base)
            ->when($request->filled('tienda'), fn ($qb) => $qb->where('tienda_id', $request->tienda))
            ->when($request->filled('tipo'), fn ($qb) => $qb->where('tipo', strtoupper((string) $request->tipo)))
            ->when($q !== '', function ($qb) use ($q) {
                $like = '%'.$q.'%';
                $qb->where(fn ($w) => $w->where('producto_nombre', 'like', $like)
                    ->orWhere('imei_serial', 'like', $like));
            })
            ->select([
                'id', 'tienda_id', 'producto_nombre', 'tipo', 'imei_serial',
                'cantidad', 'precio_costo', 'precio_minimo', 'precio_normal', 'fecha_registro',
            ])
            ->orderBy('tienda_id')->orderBy('tipo')->orderBy('producto_nombre')
            ->get();

        $tiendas = (clone $base)->distinct()->orderBy('tienda_id')->pluck('tienda_id');

        return response()->json([
            'data' => $items,
            'total' => $items->count(),
            'tiendas' => $tiendas,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'tienda_id'         => 'required|string|max:50',
            'producto_nombre'   => 'required|string|max:150',
            'tipo'              => 'required|in:EQUIPO,ACCESORIO,CHIP',
            'imei_serial'       => 'nullable|string|max:50|unique:inventario_tiendas,imei_serial',
            'imei_seriales'     => 'nullable|array|max:200',
            'imei_seriales.*'   => 'required|string|max:50|distinct',
            // Precios opcionales: paso 1 del flujo de 2 pasos (ver comentario abajo).
            'precio_costo'      => 'nullable|numeric|min:0',
            'precio_minimo'     => 'nullable|numeric|min:0',
            'precio_normal'     => 'nullable|numeric|min:0',
            'cantidad'          => 'required|integer|min:1',
            'estado'            => 'required|in:DISPONIBLE,VENDIDO,TRASLADO',
            'comision_especial' => 'nullable|numeric|min:0',
            'dni_autoriza'      => ['nullable', 'regex:/^\d{8}$/'],
        ], [
            'tienda_id.required'       => 'La tienda es obligatoria.',
            'producto_nombre.required' => 'El nombre del producto es obligatorio.',
            'tipo.required'            => 'El tipo es obligatorio.',
            'tipo.in'                  => 'El tipo debe ser EQUIPO, ACCESORIO o CHIP.',
            'imei_serial.unique'       => 'Este IMEI/serie ya está registrado.',
            'cantidad.required'        => 'La cantidad es obligatoria.',
            'estado.required'          => 'El estado es obligatorio.',
            'estado.in'                => 'El estado debe ser DISPONIBLE, VENDIDO o TRASLADO.',
            'dni_autoriza.regex'       => 'DNI inválido (debe tener 8 dígitos).',
        ]);

        if (! $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            if ((string) $validated['tienda_id'] !== (string) $user->tienda_id) {
                return response()->json(['message' => 'No puedes registrar stock para otra tienda.'], 403);
            }

            if (empty($validated['dni_autoriza'])) {
                return response()->json(['message' => 'Tu DNI es requerido.'], 422);
            }

            $agente = DB::table('agentes')
                ->where('dni', $validated['dni_autoriza'])
                ->where('estado', 'ACTIVO')
                ->first();

            if (!$agente) {
                return response()->json(['message' => 'DNI no corresponde a un agente activo.'], 422);
            }
        }

        unset($validated['dni_autoriza']);

        // Paso 1 del flujo legacy de 2 pasos (tienda/guardar_stock.php): la tienda
        // ingresa stock SIN precio de venta y gerencia lo fija después desde
        // "Precios pendientes". Un precio omitido o null se deja fuera del insert
        // para que aplique el default 0 de la columna, que es justamente lo que
        // busca preciosPendientes() (precio <= 0 OR NULL).
        foreach (['precio_costo', 'precio_minimo', 'precio_normal'] as $campoPrecio) {
            if (($validated[$campoPrecio] ?? null) === null) {
                unset($validated[$campoPrecio]);
            }
        }

        if ($validated['tipo'] === 'CHIP') {
            $tiendaCodigo = (string) $validated['tienda_id'];
            $tiendaDbId = DB::table('tiendas')->where('codigo', $tiendaCodigo)->value('id');

            if (! $tiendaDbId) {
                return response()->json(['message' => 'La tienda no existe.'], 422);
            }

            $chip = DB::transaction(function () use ($tiendaDbId, $tiendaCodigo, $validated) {
                $existente = InventarioChip::where('tienda_id', $tiendaDbId)
                    ->where('tienda_origen', $tiendaCodigo)
                    ->first();

                if ($existente) {
                    $existente->increment('stock_actual', $validated['cantidad']);
                    return $existente->fresh();
                }

                return InventarioChip::create([
                    'tienda_id'     => $tiendaDbId,
                    'tienda_origen' => $tiendaCodigo,
                    'tipo_chip'     => 'FÍSICO',
                    'stock_actual'  => $validated['cantidad'],
                ]);
            });

            return response()->json($chip, 201);
        }

        $imeis = collect($validated['imei_seriales'] ?? [])
            ->push($validated['imei_serial'] ?? null)
            ->filter(fn ($imei) => trim((string) $imei) !== '')
            ->map(fn ($imei) => trim((string) $imei))
            ->unique()
            ->values();

        if ($validated['tipo'] === 'EQUIPO' && $imeis->isNotEmpty()) {
            $existentes = InventarioTienda::whereIn('imei_serial', $imeis)->pluck('imei_serial');
            if ($existentes->isNotEmpty()) {
                return response()->json([
                    'message' => 'Estos IMEI/series ya existen: ' . $existentes->implode(', '),
                ], 422);
            }

            unset($validated['imei_serial'], $validated['imei_seriales']);
            $items = DB::transaction(function () use ($validated, $imeis, $request) {
                return $imeis->map(function ($imei) use ($validated, $request) {
                    return InventarioTienda::create([
                        ...$validated,
                        'imei_serial' => $imei,
                        'cantidad' => 1,
                        'registrado_por' => $request->user()->id,
                        'fecha_registro' => now(),
                    ]);
                });
            });

            return response()->json([
                'message' => $items->count() . ' equipos registrados.',
                'data' => $items,
                'registrados' => $items->count(),
            ], 201);
        }

        unset($validated['imei_seriales']);
        $validated['registrado_por'] = $request->user()->id;
        $validated['fecha_registro'] = now();
        $item = InventarioTienda::create($validated);

        return response()->json($item, 201);
    }

    public function show(Request $request, InventarioTienda $inventario): JsonResponse
    {
        abort_if(
            TiendaGuard::bloqueaAcceso($request->user()->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE), $request->user()->tienda_id, $inventario->tienda_id),
            403,
            'No tienes permisos sobre este inventario.'
        );
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

    public function destroy(Request $request, InventarioTienda $inventario): JsonResponse
    {
        DB::transaction(function () use ($request, $inventario) {
            $this->registrarMovimientoInventario(
                $request,
                $inventario,
                'RESTA',
                (int) $inventario->cantidad,
                (int) $inventario->cantidad,
                0,
                '[ELIMINACION] Item eliminado del inventario por un administrador.'
            );
            $inventario->delete();
        });
        return response()->json(null, 204);
    }

    public function ajustarStockReal(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'cantidad_real' => 'required|integer|min:0|max:99999',
            'observacion' => 'required|string|min:10|max:500',
        ]);

        $item = InventarioTienda::find($id);
        if (! $item) {
            return response()->json(['message' => 'Item de inventario no encontrado.'], 404);
        }

        $anterior = (int) $item->cantidad;
        $nuevo = (int) $validated['cantidad_real'];
        $accion = $nuevo >= $anterior ? 'SUMA' : 'RESTA';

        DB::transaction(function () use ($request, $item, $anterior, $nuevo, $accion, $validated) {
            $item->update([
                'cantidad' => $nuevo,
                'estado' => $nuevo > 0 ? 'DISPONIBLE' : 'VENDIDO',
            ]);

            $this->registrarMovimientoInventario(
                $request,
                $item,
                $accion,
                abs($nuevo - $anterior),
                $anterior,
                $nuevo,
                '[AJUSTE MAESTRO] ' . $validated['observacion']
            );
        });

        return response()->json([
            'message' => "Stock sincronizado: {$anterior} -> {$nuevo}.",
            'stock_anterior' => $anterior,
            'stock_nuevo' => $nuevo,
            'diferencia' => $nuevo - $anterior,
        ]);
    }

    // ── POST /inventario/{id}/precio-agente — Agente fija SOLO precio_normal ─────
    // Paridad legacy tienda/fijar_precio_agente.php: el agente/tienda fija el precio
    // de venta (≥ precio_minimo), con DNI de un agente activo. Nunca toca costo/mínimo.
    public function fijarPrecioAgente(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            return response()->json(['success' => false, 'msg' => 'Los administradores y gerentes usan el panel de gerencia.'], 403);
        }

        $validated = $request->validate([
            'precio_normal' => 'required|numeric|gt:0',
            'dni_autoriza'  => ['required', 'regex:/^\d{8}$/'],
        ], [
            'precio_normal.gt'    => 'El precio de venta debe ser mayor a cero.',
            'dni_autoriza.regex'  => 'DNI inválido (debe tener 8 dígitos).',
        ]);

        $precioNormal = round((float) $validated['precio_normal'], 2);

        $agente = DB::table('agentes')
            ->where('dni', $validated['dni_autoriza'])
            ->where('estado', 'ACTIVO')
            ->first();
        if (! $agente) {
            return response()->json(['success' => false, 'msg' => 'DNI no corresponde a un agente activo.'], 422);
        }

        $producto = InventarioTienda::find($id);
        if (! $producto) {
            return response()->json(['success' => false, 'msg' => 'El producto no existe en el inventario.'], 404);
        }

        // Blindaje de propiedad: el producto debe ser de la tienda del usuario.
        if (TiendaGuard::bloqueaAcceso(false, $user->tienda_id, $producto->tienda_id)) {
            return response()->json(['success' => false, 'msg' => 'El producto no pertenece a tu tienda.'], 403);
        }

        // Defensa en profundidad: precio_normal nunca por debajo del mínimo.
        $precioMinimo = (float) ($producto->precio_minimo ?? 0);
        if ($precioMinimo > 0 && $precioNormal < $precioMinimo) {
            return response()->json([
                'success' => false,
                'msg'     => sprintf(
                    'El precio de venta (S/ %.2f) no puede ser menor al mínimo (S/ %.2f).',
                    $precioNormal, $precioMinimo
                ),
            ], 422);
        }

        $producto->update(['precio_normal' => $precioNormal]);

        return response()->json([
            'success'  => true,
            'msg'      => 'Precio de venta actualizado correctamente.',
            'producto' => $producto->producto_nombre,
            'precio'   => $precioNormal,
        ]);
    }

    private function registrarMovimientoInventario(
        Request $request,
        InventarioTienda $item,
        string $accion,
        int $cantidad,
        int $stockAnterior,
        int $stockNuevo,
        string $observacion
    ): void {
        if (! Schema::hasTable('historial_inventario')) {
            return;
        }

        $user = $request->user();
        $agenteId = $user->agente_id ?? null;
        $dni = $agenteId ? DB::table('agentes')->where('id', $agenteId)->value('dni') : null;
        $tiendaId = Schema::hasColumn('tiendas', 'id')
            ? DB::table('tiendas')->where('codigo', $item->tienda_id)->value('id')
            : $item->tienda_id;

        $data = [
            'tienda_id' => $tiendaId ?: $item->tienda_id,
            'agente_id' => $agenteId,
            'producto_id' => $item->id,
            'accion' => $accion,
            'cantidad' => $cantidad,
            'observacion' => $observacion,
            'fecha_hora' => now(),
        ];

        $opcionales = [
            'motivo' => 'AJUSTE',
            'tienda_origen' => $item->tienda_id,
            'imei_serial' => $item->imei_serial,
            'precio_en_ese_momento' => $item->precio_normal,
            'dni_autorizacion' => $dni,
            'stock_anterior' => $stockAnterior,
            'stock_nuevo' => $stockNuevo,
        ];
        foreach ($opcionales as $column => $value) {
            if (Schema::hasColumn('historial_inventario', $column)) {
                $data[$column] = $value;
            }
        }

        DB::table('historial_inventario')->insert($data);
    }

    // ── POST /inventario/{id}/recalcular-ganancias — Actualiza JSON detalle ─────
    public function recalcularGanancias(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if (! Permisos::puede($user, 'fijar_precios')) {
            return response()->json(['success' => false, 'msg' => 'Solo administradores o gerentes.'], 403);
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
                // Normalizador único: decodifica el blob (objeto o lista) y recuerda
                // la forma original para preservarla al guardar (paridad legacy).
                $detalle = ReporteDetalleNormalizer::decodificar($fila->detalle);
                if (!$detalle['valido']) continue;

                $items = $detalle['items'];
                foreach ($items as &$item) {
                    $pv = floatval($item['precio_normal_agente'] ?? $item['precio_total'] ?? 0);
                    $item['costo_al_registrar'] = $nuevoCosto;
                    $item['ganancia']           = $pv - $nuevoCosto;
                    $item['precio_costo']       = $nuevoCosto;
                }
                unset($item);

                DB::table('reporte_categorias')->where('id', $fila->id)
                    ->update(['detalle' => ReporteDetalleNormalizer::encodearPreservandoForma($items, $detalle['eraLista'])]);
                $updated++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($e);

            return response()->json(['success' => false, 'msg' => 'Error interno del servidor'], 500);
        }

        return response()->json(['success' => true, 'msg' => "Se actualizaron {$updated} registros.", 'updated' => $updated]);
    }

    // ── GET /inventario/kardex — Kardex con historial de ventas ─────────────────
    public function kardex(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE);
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
                    -- C2: el dato nuevo (esquema normalizado) tiene prioridad sobre el legacy.
                    (SELECT CASE WHEN UPPER(ve.tipo_pago) = 'CUOTAS' THEN 1 ELSE 0 END
                     FROM venta_equipos ve
                     WHERE ve.inventario_tienda_id = it.id
                     ORDER BY ve.id DESC LIMIT 1),
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

    // ── GET /inventario/capital-invertido — Franja de KPIs (paridad legacy ver_inventario.php) ──
    // Capital = costo * cantidad de lo DISPONIBLE (chips cuestan S/1.00 fijo); admin-only.
    public function capitalInvertido(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            return response()->json([
                'capital_equipos' => 0, 'capital_accesorios' => 0, 'capital_chips' => 0,
                'total_uds_equipos' => 0, 'total_uds_accesorios' => 0, 'total_uds_chips' => 0,
            ]);
        }

        $tienda = $request->get('tienda');

        $agg = DB::table('inventario_tiendas')
            ->when($tienda, fn ($q, $t) => $q->where('tienda_id', $t))
            ->selectRaw("
                COALESCE(SUM(CASE WHEN tipo = 'EQUIPO'    AND estado = 'DISPONIBLE' THEN precio_costo * cantidad ELSE 0 END), 0) AS capital_equipos,
                COALESCE(SUM(CASE WHEN tipo = 'ACCESORIO' AND estado = 'DISPONIBLE' THEN precio_costo * cantidad ELSE 0 END), 0) AS capital_accesorios,
                COALESCE(SUM(CASE WHEN tipo = 'EQUIPO'    AND estado = 'DISPONIBLE' THEN cantidad ELSE 0 END), 0)                AS total_uds_equipos,
                COALESCE(SUM(CASE WHEN tipo = 'ACCESORIO' AND estado = 'DISPONIBLE' AND cantidad > 0 THEN cantidad ELSE 0 END), 0) AS total_uds_accesorios
            ")
            ->first();

        $chipsQuery = DB::table('inventario_chips as ic');
        if ($tienda) {
            $chipsQuery->join('tiendas as t', 'ic.tienda_id', '=', 't.id')->where('t.codigo', $tienda);
        }
        $chips = $chipsQuery->selectRaw('COALESCE(SUM(ic.stock_actual), 0) AS total_stock')->first();

        return response()->json([
            'capital_equipos'      => (float) $agg->capital_equipos,
            'capital_accesorios'   => (float) $agg->capital_accesorios,
            'capital_chips'        => (float) $chips->total_stock, // S/1.00 fijo por unidad
            'total_uds_equipos'    => (int) $agg->total_uds_equipos,
            'total_uds_accesorios' => (int) $agg->total_uds_accesorios,
            'total_uds_chips'      => (int) $chips->total_stock,
        ]);
    }

    // ── GET /inventario/stock-estancado — Items DISPONIBLE sin movimiento 30+ días ──
    public function stockEstancado(Request $request): JsonResponse
    {
        $user    = Auth::user();
        if (! $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
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
        if (! $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            return response()->json(['ok' => true, 'count' => 0, 'items' => [], 'data' => []]);
        }

        if (! Schema::hasTable('venta_equipos') || ! Schema::hasTable('ventas') || ! Schema::hasTable('reportes')) {
            return response()->json(['ok' => true, 'count' => 0, 'items' => [], 'data' => []]);
        }

        $items = DB::table('venta_equipos as ve')
            ->join('ventas as v', 'v.id', '=', 've.venta_id')
            ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
            ->where(function ($query) {
                $query->whereNull('ve.costo_snap')
                    ->orWhere('ve.costo_snap', '<=', 0)
                    ->orWhereNull('ve.ganancia_snap');
            })
            ->select([
                've.id as rc_id',
                've.producto_nombre_snap as producto',
                've.imei_serial_snap as imei',
                've.precio_venta',
                'r.tienda_id as tienda',
                'r.fecha',
            ])
            ->orderByDesc('r.fecha')
            ->orderByDesc('ve.id')
            ->limit(50)
            ->get()
            ->map(fn ($item) => (array) $item)
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'count' => count($items),
            'items' => $items,
            'data' => $items,
        ]);
    }

    // ── GET /inventario/exportar-kardex — XLSX del kardex ────────────────────────
    public function exportarKardex(Request $request): StreamedResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE);
        $tienda  = trim($request->input('tienda', ''));
        if (!$esAdmin) $tienda = $user->tienda_id ?? '';

        $items = InventarioTienda::query()
            ->whereIn('tipo', ['EQUIPO', 'ACCESORIO'])
            ->when(
                $tienda && ! in_array(strtolower($tienda), ['all', 'todas', '0'], true),
                fn ($query) => $query->where('tienda_id', $tienda)
            )
            ->when($request->filled('tipo'), fn ($query) => $query->where('tipo', strtoupper($request->string('tipo'))))
            ->when($request->filled('estado'), fn ($query) => $query->where('estado', strtoupper($request->string('estado'))))
            ->when($request->filled('q'), function ($query) use ($request) {
                $texto = '%'.trim((string) $request->input('q')).'%';
                $query->where(fn ($q) => $q->where('producto_nombre', 'like', $texto)->orWhere('imei_serial', 'like', $texto));
            })
            ->orderByDesc('fecha_registro')
            ->orderByDesc('id')
            ->get();

        $tiendas = DB::table('tiendas')->pluck('nombre', 'codigo');
        $rows = $items->map(function (InventarioTienda $item) use ($tiendas, $esAdmin) {
            $venta = DB::table('venta_equipos as ve')
                ->join('ventas as v', 'v.id', '=', 've.venta_id')
                ->leftJoin('reportes as r', 'r.id', '=', 'v.reporte_id')
                ->leftJoin('agentes as a', 'a.id', '=', 'v.vendedor_id')
                ->where('ve.inventario_tienda_id', $item->id)
                ->orderByDesc('ve.id')
                ->select('ve.precio_venta', 've.tipo_pago', 'r.fecha', 'a.nombres')
                ->first();

            $historial = DB::table('historial_inventario as h')
                ->leftJoin('agentes as a', 'a.id', '=', 'h.agente_id')
                ->where('h.producto_id', $item->id)
                ->where('h.accion', 'RESTA')
                ->orderByDesc('h.fecha_hora')
                ->select('h.fecha_hora', 'a.nombres')
                ->first();

            return [
                $item->producto_nombre,
                $item->tipo,
                $item->imei_serial,
                $tiendas[$item->tienda_id] ?? $item->tienda_id,
                $item->fecha_registro ? date('d/m/Y', strtotime((string) $item->fecha_registro)) : null,
                ($item->fecha_venta ? date('d/m/Y', strtotime((string) $item->fecha_venta)) : null)
                    ?? ($venta?->fecha ? date('d/m/Y', strtotime($venta->fecha)) : null)
                    ?? ($historial?->fecha_hora ? date('d/m/Y', strtotime($historial->fecha_hora)) : null),
                $venta?->nombres ?? $historial?->nombres,
                ...($esAdmin ? [(float) $item->precio_costo] : []),
                (float) ($venta?->precio_venta ?? $item->precio_normal),
                ucfirst(strtolower((string) ($venta?->tipo_pago ?? 'CONTADO'))),
                $item->estado,
            ];
        });

        $headers = ['Producto', 'Tipo', 'IMEI / Serial', 'Tienda', 'Fecha Ingreso', 'Fecha Venta', 'Agente Vendedor'];
        if ($esAdmin) {
            $headers[] = 'Costo S/';
        }
        array_push($headers, 'Precio S/', 'Tipo Pago', 'Estado');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kardex');
        $sheet->fromArray($headers, null, 'A1');
        $ultimaColumna = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$ultimaColumna}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A1A2E']],
        ]);
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }
        for ($column = 1; $column <= count($headers); $column++) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column)
            )->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        return response()->streamDownload(
            static function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'kardex_'.($tienda !== '' ? strtolower($tienda).'_' : 'global_').date('Y-m-d').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    // ── POST /inventario/{id}/restaurar — Rescate manual (solo admin) ──────────
    public function restaurar(int $id): JsonResponse
    {
        $user = Auth::user();
        if (! $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            return response()->json(['ok' => false, 'message' => 'Solo administradores o gerentes.'], 403);
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

        // La fila más reciente: al conservar histórico (no borrar), un mismo
        // inventario_tienda_id puede tener más de una venta si se restauró y se
        // volvió a vender. La última es la que corresponde al estado VENDIDO actual.
        $ventaEquipo = VentaEquipo::where('inventario_tienda_id', $id)->latest('id')->first();
        $venta = $ventaEquipo?->venta;

        if ($venta) {
            $reporteFecha = DB::table('reportes')->where('id', $venta->reporte_id)->value('fecha');
            if ($reporteFecha) {
                $boletaPagada = PlanillaGuard::boletaPagada((int) $venta->vendedor_id, $reporteFecha);

                if ($boletaPagada) {
                    return response()->json([
                        'ok'      => false,
                        'message' => "No se puede restaurar: la comisión de esta venta ya fue pagada en la planilla del "
                            . "{$boletaPagada->fecha_inicio} al {$boletaPagada->fecha_fin} (boleta #{$boletaPagada->id}).",
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($equipo, $venta) {
            $equipo->update([
                'estado'         => 'DISPONIBLE',
                'fecha_venta'    => null,
                'vendido_por_id' => null,
            ]);

            if ($venta) {
                $venta->update([
                    'comision_estado'            => 'ANULADA',
                    'comision_generada'          => 0,
                    'desembolso_confirmado_por'  => null,
                    'desembolso_confirmado_en'   => null,
                ]);
            }
        });

        try {
            $admin = Auth::user();
            $observacion = 'Restauración manual por admin desde Kardex de Inventario'
                . " (admin id={$admin->id}, {$admin->nombre})";
            if ($venta) {
                $observacion .= " — venta #{$venta->id} marcada ANULADA.";
            }

            // accion='SUMA': el enum de la columna solo admite SUMA|RESTA (ver migración
            // create_historial_inventario_table). 'RESCATE_MANUAL' no es un valor válido
            // y el insert fallaba silenciosamente (atrapado por este mismo catch) — el
            // detalle real queda en la observación.
            DB::table('historial_inventario')->insert([
                'tienda_id'    => $equipo->tienda_id,
                'producto_id'  => $id,
                'agente_id'    => null,
                'accion'       => 'SUMA',
                'cantidad'     => 1,
                'observacion'  => $observacion,
                'fecha_hora'   => now(),
            ]);
        } catch (\Throwable) {}

        return response()->json([
            'ok'      => true,
            'message' => "Equipo \"{$equipo->producto_nombre}\" devuelto al stock disponible.",
        ]);
    }
}
