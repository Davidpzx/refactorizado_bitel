<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agente;
use App\Models\InventarioChip;
use App\Models\Tienda;
use App\Models\TrasladoChip;
use App\Models\Usuario;
use App\Support\Paginacion;
use App\Support\Permisos;
use App\Support\TiendaGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TrasladoChipsController extends Controller
{
    // ── GET /traslados-chips ───────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE);

        $query = TrasladoChip::with(['chipOrigen', 'creadoPor'])
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

        $perPage = Paginacion::desde($request, 50);
        return response()->json($query->paginate($perPage));
    }

    // ── POST /traslados-chips — Iniciar traslado de chips ──────────────────────
    public function store(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE);

        $chipId        = (int) $request->input('chip_id', 0);
        $tiendaOrigen  = trim($request->input('tienda_origen', ''));
        $tiendaDestino = trim($request->input('tienda_destino', ''));
        $cantidad      = (int) $request->input('cantidad', 0);
        $notas         = substr(trim($request->input('notas', '')), 0, 200);

        if (!$chipId || !$tiendaDestino || $cantidad <= 0) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos o incompletos.'], 422);
        }
        if ($tiendaOrigen === $tiendaDestino) {
            return response()->json(['success' => false, 'message' => 'Tienda destino igual a tienda origen.'], 422);
        }

        if (!$esAdmin && $tiendaOrigen !== $user->tienda_id) {
            return response()->json(['success' => false, 'message' => 'Solo puedes trasladar chips de tu propia tienda.'], 403);
        }

        $authDni = trim($request->input('auth_dni', ''));

        if (!$authDni) {
            return response()->json(['success' => false, 'message' => 'Tu DNI es requerido.'], 422);
        }

        $agente = Agente::whereRaw('UPPER(TRIM(dni)) = UPPER(TRIM(?))', [$authDni])
            ->where('estado', 'ACTIVO')
            ->first();

        if (!$agente) {
            return response()->json(['success' => false, 'message' => 'DNI no corresponde a un agente activo.'], 403);
        }

        $enviadoPorId = $agente->id;

        // Admin o gerente de la tienda de origen crean directamente en PENDIENTE; tienda
        // crea en PENDIENTE_APROBACION (admin aprueba). Paridad legacy procesar_traslado_chips.php.
        // El bypass de gerente solo aplica si el gerente pertenece a la tienda de origen del
        // traslado: un gerente de otra tienda no debe saltarse la aprobación.
        $esGerenteDeOrigen = $agente->es_gerencia && $agente->tienda_base === $tiendaOrigen;
        $estadoTraslado = ($esAdmin || $esGerenteDeOrigen) ? 'PENDIENTE' : 'PENDIENTE_APROBACION';

        $chip = InventarioChip::find($chipId);
        if (!$chip) {
            return response()->json(['success' => false, 'message' => 'Registro de chips no encontrado.'], 404);
        }
        if ($chip->stock_actual < $cantidad) {
            return response()->json([
                'success' => false,
                'message' => "Stock insuficiente. Disponible: {$chip->stock_actual} ud.",
            ], 422);
        }

        if (!Tienda::where('codigo', $tiendaDestino)->exists()) {
            return response()->json(['success' => false, 'message' => 'Tienda destino no encontrada.'], 422);
        }

        // INSERT traslado, luego descontar stock (evita race condition)
        $traslado = TrasladoChip::create([
            'chip_id_origen' => $chipId,
            'tienda_origen'  => $tiendaOrigen,
            'tienda_destino' => $tiendaDestino,
            'cantidad'       => $cantidad,
            'estado'         => $estadoTraslado,
            'creado_por'     => $user->id,
            'notas'          => $notas ?: null,
            'enviado_por_id' => $enviadoPorId,
            'enviado_dni'    => $authDni,
        ]);

        $updated = InventarioChip::where('id', $chipId)
            ->where('stock_actual', '>=', $cantidad)
            ->decrement('stock_actual', $cantidad);

        if (!$updated) {
            $traslado->delete();
            return response()->json(['success' => false, 'message' => 'Stock insuficiente. Intenta de nuevo.'], 422);
        }

        $msg = $estadoTraslado === 'PENDIENTE'
            ? "{$cantidad} chip(s) enviados a {$tiendaDestino} EN TRÁNSITO."
            : "{$cantidad} chip(s) PENDIENTES DE APROBACIÓN.";

        return response()->json([
            'success'     => true,
            'message'     => $msg,
            'traslado_id' => $traslado->id,
        ]);
    }

    // ── POST /traslados-chips/{id}/confirmar ───────────────────────────────────
    public function confirmar(Request $request, int $id): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE);

        $observacion     = substr(trim($request->input('observacion', '')), 0, 200);
        $authDni         = trim($request->input('auth_dni', ''));
        $confirmadoPorId = null;

        if (!$authDni) {
            return response()->json(['success' => false, 'message' => 'Tu DNI es requerido.'], 422);
        }

        $agente = Agente::whereRaw('UPPER(TRIM(dni)) = UPPER(TRIM(?))', [$authDni])
            ->where('estado', 'ACTIVO')
            ->first();

        if (!$agente) {
            return response()->json(['success' => false, 'message' => 'DNI no corresponde a un agente activo.'], 403);
        }

        $confirmadoPorId = $agente->id;

        $result = DB::transaction(function () use ($id, $user, $esAdmin, $observacion, $confirmadoPorId, $authDni) {
            $traslado = TrasladoChip::where('id', $id)
                ->where('estado', 'PENDIENTE')
                ->lockForUpdate()
                ->first();

            if (!$traslado) return ['error' => 'Traslado no encontrado o ya fue procesado.'];
            if (TiendaGuard::bloqueaAcceso($esAdmin, $user->tienda_id, $traslado->tienda_destino)) {
                return ['error' => 'Solo la tienda destino puede confirmar este traslado.'];
            }

            // Determinar chip_owner (propietario real del chip)
            $chipRow   = InventarioChip::find($traslado->chip_id_origen);
            $chipOwner = ($chipRow && $chipRow->tienda_origen) ? $chipRow->tienda_origen : $traslado->tienda_origen;

            $tiendaDest = Tienda::where('codigo', $traslado->tienda_destino)->first();
            if (!$tiendaDest) return ['error' => 'Tienda destino no encontrada: ' . $traslado->tienda_destino];

            // UPSERT en inventario_chips del destino
            $existente = InventarioChip::where('tienda_id', $tiendaDest->id)
                ->whereRaw('tienda_origen COLLATE utf8mb4_general_ci = ?', [$chipOwner])
                ->first();

            if ($existente) {
                $existente->increment('stock_actual', $traslado->cantidad);
            } else {
                InventarioChip::create([
                    'tienda_id'    => $tiendaDest->id,
                    'tienda_origen' => $chipOwner,
                    'tipo_chip'    => 'FÍSICO',
                    'stock_actual' => $traslado->cantidad,
                ]);
            }

            // Registrar en historial_inventario si existe la tabla
            try {
                DB::table('historial_inventario')->insert([
                    'tienda_id'    => $tiendaDest->id,
                    'agente_id'    => $confirmadoPorId,
                    'accion'       => 'SUMA',
                    'cantidad'     => $traslado->cantidad,
                    'tienda_origen' => $traslado->tienda_origen,
                    'observacion'  => "Ingreso por traslado chips #{$id} desde {$traslado->tienda_origen}",
                    'producto_id'  => null,
                    'fecha_hora'   => now(),
                ]);
            } catch (\Throwable) {}

            $traslado->update([
                'estado'               => 'CONFIRMADO',
                'fecha_confirmacion'   => now(),
                'confirmado_por_id'    => $confirmadoPorId,
                'confirmado_dni'       => $authDni ?: null,
                'observacion_recepcion' => $observacion ?: null,
            ]);

            return [
                'ok'       => true,
                'cantidad' => $traslado->cantidad,
                'origen'   => $traslado->tienda_origen,
            ];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        return response()->json([
            'success'     => true,
            'traslado_id' => $id,
            'message'     => "Recepción confirmada. {$result['cantidad']} chip(s) de {$result['origen']} agregados a tu stock.",
        ]);
    }

    // ── POST /traslados-chips/{id}/gestionar — Aprobar/Rechazar/Cancelar ───────
    public function gestionar(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if (! Permisos::puede($user, 'aprobar_traslados')) {
            return response()->json(['success' => false, 'message' => 'Solo administradores o gerentes.'], 403);
        }

        $action = trim($request->input('action', ''));
        if (!in_array($action, ['aprobar', 'rechazar', 'cancelar'])) {
            return response()->json(['success' => false, 'message' => 'Acción inválida.'], 422);
        }

        $result = DB::transaction(function () use ($id, $action) {
            $traslado = TrasladoChip::where('id', $id)->lockForUpdate()->firstOrFail();

            if ($action === 'aprobar') {
                if ($traslado->estado !== 'PENDIENTE_APROBACION') {
                    return ['error' => 'El traslado no está pendiente de aprobación.'];
                }
                $traslado->update(['estado' => 'PENDIENTE']);
                return ['ok' => true, 'msg' => 'Traslado de chips aprobado y en tránsito.'];
            }

            if ($action === 'rechazar' && $traslado->estado !== 'PENDIENTE_APROBACION') {
                return ['error' => 'El traslado no está pendiente de aprobación.'];
            }
            if ($action === 'cancelar' && !in_array($traslado->estado, ['PENDIENTE', 'PENDIENTE_APROBACION'])) {
                return ['error' => 'Solo se pueden cancelar traslados activos.'];
            }

            $estadoFinal = $action === 'rechazar' ? 'RECHAZADO' : 'CANCELADO';

            // Devolver stock a origen
            $chipRow    = InventarioChip::find($traslado->chip_id_origen);
            $chipOwner  = $chipRow ? $chipRow->tienda_origen : $traslado->tienda_origen;
            $tiendaOri  = Tienda::where('codigo', $traslado->tienda_origen)->first();

            if ($tiendaOri) {
                $existente = InventarioChip::where('tienda_id', $tiendaOri->id)
                    ->whereRaw('tienda_origen COLLATE utf8mb4_general_ci = ?', [$chipOwner])
                    ->first();
                if ($existente) {
                    $existente->increment('stock_actual', $traslado->cantidad);
                } else {
                    InventarioChip::create([
                        'tienda_id'     => $tiendaOri->id,
                        'tienda_origen' => $chipOwner,
                        'tipo_chip'     => 'FÍSICO',
                        'stock_actual'  => $traslado->cantidad,
                    ]);
                }
            }

            $traslado->update(['estado' => $estadoFinal]);
            return ['ok' => true, 'msg' => 'Traslado de chips cancelado/rechazado. Stock devuelto.'];
        });

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        return response()->json(['success' => true, 'message' => $result['msg']]);
    }

    // ── GET /inventario-chips — Stock de chips por tienda ─────────────────────
    public function inventario(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE);

        $query = InventarioChip::with('tienda');

        if (!$esAdmin) {
            $query->where('tienda_id', function ($q) use ($user) {
                $q->select('id')->from('tiendas')->where('codigo', $user->tienda_id);
            });
        }

        if ($request->filled('tienda_id')) {
            $query->where('tienda_id', $request->tienda_id);
        }

        $query->orderByDesc('stock_actual')->orderByDesc('id');

        if ($request->hasAny(['page', 'per_page'])) {
            $perPage = Paginacion::desde($request, 50);
            return response()->json($query->paginate($perPage));
        }

        // El frontend pagina este stock en memoria y espera {data: Chip[]}.
        return response()->json(['data' => $query->limit(500)->get()]);
    }
}
