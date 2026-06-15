<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agente;
use App\Models\InventarioTienda;
use App\Models\Tienda;
use App\Models\TrasladoStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrasladoController extends Controller
{
    // ── GET /traslados — Listar traslados (filtro por estado/tienda) ────────────
    public function index(Request $request): JsonResponse
    {
        $user     = Auth::user();
        $esAdmin  = $user->rol === 'admin';

        $query = TrasladoStock::with(['producto', 'creadoPor', 'enviadoPor', 'confirmadoPor'])
            ->orderByDesc('created_at');

        if (!$esAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('tienda_origen', $user->tienda_id)
                  ->orWhere('tienda_destino', $user->tienda_id);
            });
        }

        if ($request->filled('estado')) {
            $query->where('estado', strtoupper($request->estado));
        }
        if ($request->filled('tienda_origen')) {
            $query->where('tienda_origen', $request->tienda_origen);
        }
        if ($request->filled('tienda_destino')) {
            $query->where('tienda_destino', $request->tienda_destino);
        }
        if ($request->filled('codigo_lote')) {
            $query->where('codigo_lote', $request->codigo_lote);
        }

        $perPage = min((int)($request->per_page ?? 50), 200);
        return response()->json($query->paginate($perPage));
    }

    // ── GET /traslados/{id} ────────────────────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $traslado = TrasladoStock::with(['producto', 'creadoPor', 'enviadoPor', 'confirmadoPor'])
            ->findOrFail($id);
        return response()->json($traslado);
    }

    // ── POST /traslados — Crear traslado (individual o masivo) ─────────────────
    public function store(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->rol === 'admin';

        $authDni     = trim($request->input('auth_dni', ''));
        $authAgenteId = (int) $request->input('auth_agente_id', 0);

        if (!$authDni) {
            return response()->json(['success' => false, 'message' => 'Credenciales de autorización requeridas.'], 422);
        }

        $enviadoPorId = null;
        if ($authAgenteId) {
            $agente = Agente::where('id', $authAgenteId)
                ->whereRaw('UPPER(TRIM(dni)) = UPPER(TRIM(?))', [$authDni])
                ->where('estado', 'ACTIVO')
                ->first();
            if ($agente) {
                $enviadoPorId = $authAgenteId;
            } elseif (!$esAdmin) {
                return response()->json(['success' => false, 'message' => 'Credenciales inválidas o agente no activo.'], 403);
            }
        } elseif (!$esAdmin) {
            return response()->json(['success' => false, 'message' => 'Credenciales de autorización inválidas.'], 403);
        }

        $isAuthorizedByAdmin = $esAdmin;
        if (!$isAuthorizedByAdmin && $authDni) {
            $isAuthorizedByAdmin = Agente::where('dni', $authDni)
                ->where('estado', 'ACTIVO')
                ->where('es_gerencia', 1)
                ->exists();
        }
        $estadoTraslado = $isAuthorizedByAdmin ? 'PENDIENTE' : 'PENDIENTE_APROBACION';

        $tiendaDestino = trim($request->input('tienda_destino', ''));
        $notas         = substr(trim($request->input('notas', '')), 0, 200);

        if (!$tiendaDestino) {
            return response()->json(['success' => false, 'message' => 'Tienda destino requerida.'], 422);
        }

        if (!Tienda::where('codigo', $tiendaDestino)->exists()) {
            return response()->json(['success' => false, 'message' => 'Tienda destino no encontrada.'], 422);
        }

        // ── Modo masivo ────────────────────────────────────────────────────────
        $equiposIdsJson = trim($request->input('equipos_ids', ''));
        if ($equiposIdsJson) {
            $idsRaw = json_decode($equiposIdsJson, true);
            if (!is_array($idsRaw) || empty($idsRaw)) {
                return response()->json(['success' => false, 'message' => 'Lista de equipos inválida.'], 422);
            }
            $ids = array_values(array_filter(array_map('intval', $idsRaw), fn($v) => $v > 0));
            if (empty($ids)) {
                return response()->json(['success' => false, 'message' => 'No se recibieron IDs válidos.'], 422);
            }

            $ok         = 0;
            $skip       = [];
            $codigoLote = strtoupper(bin2hex(random_bytes(6)));

            DB::transaction(function () use (
                $ids, $tiendaDestino, $estadoTraslado, $user, $esAdmin, $notas,
                $codigoLote, $enviadoPorId, $authDni, &$ok, &$skip
            ) {
                foreach ($ids as $pid) {
                    $item = InventarioTienda::where('id', $pid)
                        ->where('estado', 'DISPONIBLE')
                        ->lockForUpdate()
                        ->first();

                    if (!$item)                               { $skip[] = "ID {$pid}: no disponible"; continue; }
                    if ($item->tienda_id === $tiendaDestino)  { $skip[] = "ID {$pid}: ya en destino"; continue; }
                    if (!$esAdmin && $item->tienda_id !== $user->tienda_id) {
                        $skip[] = "ID {$pid}: no pertenece a tu tienda"; continue;
                    }

                    $item->update(['estado' => 'TRASLADO']);

                    $traslado = TrasladoStock::create([
                        'producto_id'    => $pid,
                        'tienda_origen'  => $item->tienda_id,
                        'tienda_destino' => $tiendaDestino,
                        'cantidad'       => $item->cantidad,
                        'estado'         => $estadoTraslado,
                        'creado_por'     => $user->id,
                        'notas'          => $notas ?: null,
                        'codigo_lote'    => $codigoLote,
                        'enviado_por_id' => $enviadoPorId,
                        'enviado_dni'    => $authDni ?: null,
                    ]);

                    $ok++;
                }
            });

            $msg = $isAuthorizedByAdmin
                ? "{$ok} item(s) enviado(s) a {$tiendaDestino} EN TRÁNSITO."
                : "{$ok} item(s) PENDIENTES DE APROBACIÓN.";
            if (!empty($skip)) $msg .= ' Omitidos: ' . implode('; ', $skip);

            return response()->json([
                'success'     => $ok > 0,
                'message'     => $msg,
                'codigo_lote' => $codigoLote,
            ]);
        }

        // ── Modo individual ────────────────────────────────────────────────────
        $productoId = (int) $request->input('producto_id', 0);
        $cantidad   = (int) $request->input('cantidad', 0);

        if (!$productoId || $cantidad <= 0) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos o incompletos.'], 422);
        }

        $result = DB::transaction(function () use (
            $productoId, $tiendaDestino, $cantidad, $estadoTraslado,
            $user, $esAdmin, $notas, $enviadoPorId, $authDni, $isAuthorizedByAdmin
        ) {
            $origen = InventarioTienda::where('id', $productoId)
                ->where('estado', 'DISPONIBLE')
                ->lockForUpdate()
                ->first();

            if (!$origen) return ['error' => 'Producto no encontrado o no disponible.'];
            if ($origen->cantidad < $cantidad) return ['error' => "Stock insuficiente. Disponible: {$origen->cantidad} ud."];
            if ($origen->tienda_id === $tiendaDestino) return ['error' => 'Tienda destino igual a origen.'];
            if (!$esAdmin && $origen->tienda_id !== $user->tienda_id) return ['error' => 'Solo puedes trasladar items de tu propia tienda.'];

            $nuevaCantidad = $origen->cantidad - $cantidad;

            if ($nuevaCantidad === 0) {
                $origen->update(['estado' => 'TRASLADO']);
                $idEnTransito = $origen->id;
            } else {
                $origen->update(['cantidad' => $nuevaCantidad]);
                $nuevoItem = InventarioTienda::create([
                    'tienda_id'       => $origen->tienda_id,
                    'tipo'            => $origen->tipo,
                    'producto_nombre' => $origen->producto_nombre,
                    'imei_serial'     => $origen->imei_serial,
                    'cantidad'        => $cantidad,
                    'precio_costo'    => $origen->precio_costo,
                    'precio_minimo'   => $origen->precio_minimo,
                    'precio_normal'   => $origen->precio_normal,
                    'estado'          => 'TRASLADO',
                    'fecha_registro'  => now(),
                ]);
                $idEnTransito = $nuevoItem->id;
            }

            $traslado = TrasladoStock::create([
                'producto_id'        => $idEnTransito,
                'tienda_origen'      => $origen->tienda_id,
                'tienda_destino'     => $tiendaDestino,
                'cantidad'           => $cantidad,
                'estado'             => $estadoTraslado,
                'creado_por'         => $user->id,
                'notas'              => $notas ?: null,
                'enviado_por_id'     => $enviadoPorId,
                'enviado_dni'        => $authDni ?: null,
                'producto_nombre_snap' => $origen->producto_nombre,
                'imei_serial_snap'   => $origen->imei_serial,
            ]);

            return [
                'traslado_id'    => $traslado->id,
                'producto_nombre' => $origen->producto_nombre,
            ];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        $msg = $isAuthorizedByAdmin
            ? "\"{$result['producto_nombre']}\" ({$cantidad} ud.) enviado a {$tiendaDestino} EN TRÁNSITO."
            : "\"{$result['producto_nombre']}\" ({$cantidad} ud.) PENDIENTE DE APROBACIÓN.";

        return response()->json([
            'success'     => true,
            'message'     => $msg,
            'traslado_id' => $result['traslado_id'],
        ]);
    }

    // ── POST /traslados/{id}/confirmar ─────────────────────────────────────────
    public function confirmar(Request $request, int $id): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->rol === 'admin';

        $observacion   = substr(trim($request->input('observacion', '')), 0, 200);
        $authAgenteId  = (int) $request->input('auth_agente_id', 0);
        $authDni       = trim($request->input('auth_dni', ''));

        $confirmadoPorId = null;
        if (!$esAdmin) {
            if (!$authAgenteId || !$authDni) {
                return response()->json(['success' => false, 'message' => 'Credenciales de autorización requeridas.'], 422);
            }
            $agente = Agente::where('id', $authAgenteId)
                ->whereRaw('UPPER(TRIM(dni)) = UPPER(TRIM(?))', [$authDni])
                ->where('estado', 'ACTIVO')
                ->first();
            if (!$agente) {
                return response()->json(['success' => false, 'message' => 'Credenciales inválidas o agente no activo.'], 403);
            }
            $confirmadoPorId = $authAgenteId;
        } else {
            if (empty($authDni)) {
                $usuarioDni = Schema::hasColumn('usuarios', 'dni')
                    ? DB::table('usuarios')->where('id', $user->id)->value('dni')
                    : null;
                $authDni    = $usuarioDni ?? '';
            }
        }

        $result = DB::transaction(function () use ($id, $user, $esAdmin, $observacion, $confirmadoPorId, $authDni) {
            $traslado = TrasladoStock::where('id', $id)
                ->where('estado', 'PENDIENTE')
                ->lockForUpdate()
                ->first();

            if (!$traslado) return ['error' => 'Traslado no encontrado o ya fue procesado.'];
            if (!$esAdmin && $traslado->tienda_destino !== $user->tienda_id) {
                return ['error' => 'Solo la tienda destino puede confirmar este traslado.'];
            }

            $origen = InventarioTienda::where('id', $traslado->producto_id)
                ->where('estado', 'TRASLADO')
                ->lockForUpdate()
                ->first();

            if (!$origen) return ['error' => 'Producto no encontrado en estado de tránsito.'];

            $cant           = $traslado->cantidad;
            $tiendaDestino  = $traslado->tienda_destino;
            $nombreSnap     = $origen->producto_nombre;
            $serialSnap     = $origen->imei_serial;

            // Guardar snapshot
            $traslado->update([
                'producto_nombre_snap' => $nombreSnap,
                'imei_serial_snap'     => $serialSnap,
            ]);

            // Eliminar item de origen
            $origen->delete();

            // Insertar en destino (accesorios se fusionan si ya existe el mismo nombre)
            $destId = null;
            if ($origen->tipo === 'ACCESORIO') {
                $existente = InventarioTienda::where('tienda_id', $tiendaDestino)
                    ->where('producto_nombre', $origen->producto_nombre)
                    ->where('tipo', 'ACCESORIO')
                    ->where('estado', 'DISPONIBLE')
                    ->first();
                if ($existente) {
                    $existente->increment('cantidad', $cant);
                    $destId = $existente->id;
                }
            }
            if (!$destId) {
                $nuevo = InventarioTienda::create([
                    'tienda_id'       => $tiendaDestino,
                    'tipo'            => $origen->tipo,
                    'producto_nombre' => $origen->producto_nombre,
                    'imei_serial'     => $origen->imei_serial,
                    'cantidad'        => $cant,
                    'precio_costo'    => $origen->precio_costo,
                    'precio_minimo'   => $origen->precio_minimo,
                    'precio_normal'   => $origen->precio_normal,
                    'estado'          => 'DISPONIBLE',
                    'fecha_registro'  => now(),
                ]);
                $destId = $nuevo->id;
            }

            $traslado->update([
                'producto_id'          => $destId,
                'estado'               => 'CONFIRMADO',
                'fecha_confirmacion'   => now(),
                'confirmado_por_id'    => $confirmadoPorId,
                'confirmado_dni'       => $authDni ?: null,
                'observacion_recepcion' => $observacion ?: null,
            ]);

            return ['ok' => true, 'nombre' => $nombreSnap, 'cant' => $cant, 'destino' => $tiendaDestino];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        return response()->json([
            'success'     => true,
            'traslado_id' => $id,
            'message'     => "Recepción confirmada. {$result['cant']} ud. de \"{$result['nombre']}\" recibidos en {$result['destino']}.",
        ]);
    }

    // POST /traslados/lote/{codigoLote}/confirmar
    // Confirma todos los items pendientes del lote en una sola transaccion.
    public function confirmarLote(Request $request, string $codigoLote): JsonResponse
    {
        $user = Auth::user();
        $esAdmin = $user->rol === 'admin';
        $codigoLote = trim($codigoLote);
        $observacion = substr(trim($request->input('observacion', '')), 0, 200);
        $authAgenteId = (int) $request->input('auth_agente_id', 0);
        $authDni = trim($request->input('auth_dni', ''));
        $confirmadoPorId = null;

        if ($codigoLote === '') {
            return response()->json(['success' => false, 'message' => 'Codigo de lote requerido.'], 422);
        }

        if (! $esAdmin) {
            if (! $authAgenteId || ! $authDni) {
                return response()->json(['success' => false, 'message' => 'Credenciales de autorizacion requeridas.'], 422);
            }
            $agente = Agente::whereKey($authAgenteId)
                ->whereRaw('UPPER(TRIM(dni)) = UPPER(TRIM(?))', [$authDni])
                ->where('estado', 'ACTIVO')
                ->first();
            if (! $agente) {
                return response()->json(['success' => false, 'message' => 'Credenciales invalidas o agente no activo.'], 403);
            }
            $confirmadoPorId = $authAgenteId;
        } elseif ($authDni === '' && Schema::hasColumn('usuarios', 'dni')) {
            $authDni = (string) (DB::table('usuarios')->where('id', $user->id)->value('dni') ?? '');
        }

        try {
            $resultado = DB::transaction(function () use (
                $codigoLote,
                $user,
                $esAdmin,
                $observacion,
                $confirmadoPorId,
                $authDni
            ) {
                $traslados = TrasladoStock::where('codigo_lote', $codigoLote)
                    ->where('estado', 'PENDIENTE')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($traslados->isEmpty()) {
                    throw new \RuntimeException('No hay items pendientes para este lote.');
                }

                $destinos = $traslados->pluck('tienda_destino')->unique();
                if ($destinos->count() !== 1) {
                    throw new \RuntimeException('El lote contiene mas de una tienda destino.');
                }
                $destino = (string) $destinos->first();
                if (! $esAdmin && $destino !== (string) $user->tienda_id) {
                    throw new \RuntimeException('Solo la tienda destino puede confirmar este lote.');
                }

                $confirmados = [];
                foreach ($traslados as $traslado) {
                    $origen = InventarioTienda::whereKey($traslado->producto_id)
                        ->where('estado', 'TRASLADO')
                        ->lockForUpdate()
                        ->first();
                    if (! $origen) {
                        throw new \RuntimeException("Item {$traslado->id}: producto no encontrado en transito.");
                    }

                    $cantidad = (int) $traslado->cantidad;
                    $nombre = $origen->producto_nombre;
                    $imei = $origen->imei_serial;
                    $tipo = $origen->tipo;
                    $precios = [
                        'precio_costo' => $origen->precio_costo,
                        'precio_minimo' => $origen->precio_minimo,
                        'precio_normal' => $origen->precio_normal,
                    ];

                    $origen->delete();

                    $destinoId = null;
                    if ($tipo === 'ACCESORIO') {
                        $existente = InventarioTienda::where('tienda_id', $destino)
                            ->where('producto_nombre', $nombre)
                            ->where('tipo', 'ACCESORIO')
                            ->where('estado', 'DISPONIBLE')
                            ->lockForUpdate()
                            ->first();
                        if ($existente) {
                            $existente->increment('cantidad', $cantidad);
                            $destinoId = $existente->id;
                        }
                    }

                    if (! $destinoId) {
                        $nuevo = InventarioTienda::create([
                            'tienda_id' => $destino,
                            'tipo' => $tipo,
                            'producto_nombre' => $nombre,
                            'imei_serial' => $imei,
                            'cantidad' => $cantidad,
                            ...$precios,
                            'estado' => 'DISPONIBLE',
                            'fecha_registro' => now(),
                        ]);
                        $destinoId = $nuevo->id;
                    }

                    $traslado->update([
                        'producto_id' => $destinoId,
                        'producto_nombre_snap' => $nombre,
                        'imei_serial_snap' => $imei,
                        'estado' => 'CONFIRMADO',
                        'fecha_confirmacion' => now(),
                        'confirmado_por_id' => $confirmadoPorId,
                        'confirmado_dni' => $authDni ?: null,
                        'observacion_recepcion' => $observacion ?: null,
                    ]);
                    $confirmados[] = $traslado->id;
                }

                return ['ids' => $confirmados, 'destino' => $destino];
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'codigo_lote' => $codigoLote,
            'confirmados' => $resultado['ids'],
            'message' => count($resultado['ids']) . " item(s) recibidos en {$resultado['destino']}.",
        ]);
    }

    // ── POST /traslados/{id}/gestionar — Aprobar/Rechazar/Cancelar (solo admin) ─
    public function gestionar(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }

        $action     = trim($request->input('action', ''));
        $codigoLote = trim($request->input('codigo_lote', ''));

        if (!in_array($action, ['aprobar', 'rechazar', 'cancelar'])) {
            return response()->json(['success' => false, 'message' => 'Acción inválida.'], 422);
        }

        $result = DB::transaction(function () use ($id, $action, $codigoLote) {
            $traslado = TrasladoStock::where('id', $id)->lockForUpdate()->firstOrFail();
            $lote     = $codigoLote ?: ($traslado->codigo_lote ?? '');

            if ($action === 'aprobar') {
                if ($traslado->estado !== 'PENDIENTE_APROBACION') {
                    return ['error' => 'El traslado no está pendiente de aprobación.'];
                }
                if ($lote) {
                    TrasladoStock::where('codigo_lote', $lote)
                        ->where('estado', 'PENDIENTE_APROBACION')
                        ->update(['estado' => 'PENDIENTE']);
                } else {
                    $traslado->update(['estado' => 'PENDIENTE']);
                }
                return ['ok' => true, 'msg' => 'Traslado aprobado y en tránsito.', 'codigo_lote' => $lote];
            }

            // Rechazar / Cancelar
            if ($action === 'rechazar' && $traslado->estado !== 'PENDIENTE_APROBACION') {
                return ['error' => 'El traslado no está pendiente de aprobación.'];
            }
            if ($action === 'cancelar' && !in_array($traslado->estado, ['PENDIENTE', 'PENDIENTE_APROBACION'])) {
                return ['error' => 'Solo se pueden cancelar traslados activos.'];
            }

            $estadoFinal = $action === 'rechazar' ? 'RECHAZADO' : 'CANCELADO';

            if ($lote) {
                $items = TrasladoStock::where('codigo_lote', $lote)->get();
                foreach ($items as $item) {
                    InventarioTienda::where('id', $item->producto_id)
                        ->update(['estado' => 'DISPONIBLE']);
                }
                TrasladoStock::where('codigo_lote', $lote)->update(['estado' => $estadoFinal]);
            } else {
                InventarioTienda::where('id', $traslado->producto_id)->update(['estado' => 'DISPONIBLE']);
                $traslado->update(['estado' => $estadoFinal]);
            }

            return ['ok' => true, 'msg' => 'Traslado cancelado/rechazado. Stock devuelto a origen.'];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        return response()->json(['success' => true, 'message' => $result['msg']]);
    }

    // ── GET /traslados/pendientes-aprobacion (solo admin) ───────────────────────
    public function pendientesAprobacion(): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }

        $traslados = TrasladoStock::with(['producto'])
            ->where('estado', 'PENDIENTE_APROBACION')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('codigo_lote');

        return response()->json(['data' => $traslados]);
    }
}
