<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TicketController extends Controller
{
    // ── POST /tickets — Crear ticket (soporta payload simple o items[]) ─────────
    public function store(Request $request): JsonResponse
    {
        $user     = Auth::user();
        $itemsRaw = $request->input('items');

        $monto          = 0.0;
        $descripcion    = '';
        $nombreCliente  = substr(trim($request->input('nombre_cliente', '')), 0, 150);
        $dniCliente     = substr(trim($request->input('dni_cliente', '')), 0, 20);

        if (is_array($itemsRaw) && count($itemsRaw) > 0) {
            $partes  = [];
            $nombres = [];
            $dnis    = [];
            foreach ($itemsRaw as $item) {
                $con    = substr(trim((string)($item['concepto'] ?? '')), 0, 100);
                $mon    = round((float)($item['monto'] ?? 0), 2);
                $nom    = substr(trim((string)($item['nombre']  ?? '')), 0, 80);
                $dniIt  = substr(trim((string)($item['dni']     ?? '')), 0, 20);
                if ($mon > 0) {
                    $seg    = $con !== '' ? $con : 'Pago';
                    $extras = array_filter([$nom, $dniIt]);
                    if (!empty($extras)) $seg .= ' (' . implode(' - ', $extras) . ')';
                    $seg      .= ' S/' . number_format($mon, 2);
                    $partes[]  = $seg;
                    $monto    += $mon;
                    if ($nom   !== '') $nombres[] = $nom;
                    if ($dniIt !== '') $dnis[]    = $dniIt;
                }
            }
            $descripcion   = substr(implode(', ', $partes), 0, 1000);
            if (!empty($nombres)) $nombreCliente = substr(implode(', ', $nombres), 0, 150);
            if (!empty($dnis))    $dniCliente    = substr(implode(', ', $dnis),    0, 20);
        } else {
            $monto       = round((float) $request->input('monto', 0), 2);
            $descripcion = substr(trim($request->input('descripcion', '')), 0, 1000);
        }

        if ($monto <= 0) {
            return response()->json(['ok' => false, 'message' => 'Monto inválido.'], 422);
        }

        $efeBruto      = round((float) $request->input('efectivo', 0), 2);
        $yape          = round((float) $request->input('yape', 0), 2);
        $bipay         = round((float) $request->input('bipay', 0), 2);
        $transferencia = round((float) $request->input('plin', $request->input('transferencia', 0)), 2);
        $totalRec      = $efeBruto + $yape + $bipay + $transferencia;
        $vuelto        = max(0.0, round($totalRec - $monto, 2));
        $efectivo      = max(0.0, round($efeBruto - $vuelto, 2));
        $cantidad      = max(1, (int) $request->input('cantidad', 1));

        $metodos   = array_filter([
            $efectivo      > 0 ? 'Efectivo'    : '',
            $yape          > 0 ? 'Yape'        : '',
            $bipay         > 0 ? 'Bipay'       : '',
            $transferencia > 0 ? 'Plin/Transf' : '',
        ]);
        $formaPago = !empty($metodos) ? implode('+', $metodos) : 'Efectivo';

        $id = DB::table('tickets_emitidos')->insertGetId([
            'tienda_id'      => substr(trim($request->input('tienda_id', $user->tienda_id ?? '')), 0, 20),
            'agente_id'      => (int) $request->input('agente_id', 0),
            'vendedor'       => substr(trim($request->input('vendedor', '')), 0, 100),
            'nombre_cliente' => $nombreCliente,
            'dni_cliente'    => $dniCliente,
            'forma_pago'     => $formaPago,
            'telefono'       => substr(trim($request->input('telefono', '')), 0, 20),
            'descripcion'    => $descripcion,
            'monto'          => $monto,
            'cantidad'       => $cantidad,
            'efectivo'       => $efectivo,
            'yape'           => $yape,
            'bipay'          => $bipay,
            'transferencia'  => $transferencia,
            'vuelto'         => $vuelto,
            'creado_en'      => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    // ── GET /tickets/{id} — Ver ticket individual (para impresión) ───────────
    public function show(int $id): JsonResponse
    {
        $ticket = DB::table('tickets_emitidos as t')
            ->leftJoin('agentes as a', 'a.id', '=', 't.agente_id')
            ->where('t.id', $id)
            ->select('t.*', DB::raw("COALESCE(a.nombres, t.vendedor) AS cajero_nombres"))
            ->first();

        if (!$ticket) {
            return response()->json(['message' => 'Ticket no encontrado.'], 404);
        }
        return response()->json($ticket);
    }

    // ── PATCH /tickets/{id} — Actualizar datos del cliente / anular ───────────
    // Solo escribe los campos presentes en el request: un PATCH parcial (p.ej. anular
    // enviando solo `estado`) ya no borra el nombre/pagos del ticket.
    public function update(Request $request, int $id): JsonResponse
    {
        $textos = [
            'nombre_cliente' => 150,
            'forma_pago'     => 50,
            'telefono'       => 20,
        ];
        $montos = ['efectivo', 'yape', 'bipay', 'vuelto'];

        $update = [];
        foreach ($textos as $campo => $max) {
            if ($request->has($campo)) {
                $update[$campo] = substr(trim((string) $request->input($campo)), 0, $max);
            }
        }
        foreach ($montos as $campo) {
            if ($request->has($campo)) {
                $update[$campo] = round((float) $request->input($campo), 2);
            }
        }
        if ($request->has('plin') || $request->has('transferencia')) {
            $update['transferencia'] = round((float) $request->input('plin', $request->input('transferencia', 0)), 2);
        }
        // Anular (estado) — guardado por columna por drift legacy.
        if ($request->has('estado') && Schema::hasColumn('tickets_emitidos', 'estado')) {
            $update['estado'] = substr(trim((string) $request->input('estado')), 0, 30);
        }

        if (! empty($update)) {
            $update['updated_at'] = now();
            DB::table('tickets_emitidos')->where('id', $id)->update($update);
        }

        return response()->json(['ok' => true]);
    }

    // ── DELETE /tickets/{id} — Eliminar ticket (admin, paridad api/eliminar_ticket.php) ──
    public function destroy(int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }

        $deleted = DB::table('tickets_emitidos')->where('id', $id)->delete();
        if (! $deleted) {
            return response()->json(['success' => false, 'message' => 'Ticket no encontrado.'], 404);
        }
        return response()->json(['success' => true, 'message' => 'Ticket eliminado.']);
    }

    // ── GET /tickets — Listar tickets (admin o filtro por tienda) ─────────────
    public function index(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $esAdmin = $user->rol === 'admin';

        $query = DB::table('tickets_emitidos')
            ->orderByDesc('creado_en');

        if (!$esAdmin) {
            $query->where('tienda_id', $user->tienda_id);
        }

        if ($request->filled('tienda_id')) $query->where('tienda_id', $request->tienda_id);
        if ($request->filled('agente_id')) $query->where('agente_id', $request->agente_id);
        if ($request->filled('desde'))     $query->where('creado_en', '>=', $request->desde);
        if ($request->filled('hasta'))     $query->where('creado_en', '<=', $request->hasta . ' 23:59:59');

        $perPage = min((int)($request->per_page ?? 50), 200);
        return response()->json($query->paginate($perPage));
    }
}
