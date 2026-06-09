<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BitacoraStockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('historial_inventario')) {
            return response()->json([
                'kpis'        => null,
                'movimientos' => ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1],
                'warning'     => 'La tabla historial_inventario aún no ha sido migrada.',
            ]);
        }

        $user    = $request->user();
        $perPage = $request->integer('per_page', 20);

        $base = DB::table('historial_inventario as h')
            ->leftJoin('agentes as a', 'h.agente_id', '=', 'a.id')
            ->leftJoin('inventario_tiendas as it', 'h.producto_id', '=', 'it.id')
            ->when($request->fecha_desde, fn($q, $f) => $q->whereDate('h.fecha_hora', '>=', $f))
            ->when($request->fecha_hasta, fn($q, $f) => $q->whereDate('h.fecha_hora', '<=', $f))
            ->when($request->accion,      fn($q, $v) => $q->where('h.accion', $v))
            ->when($request->agente_id,   fn($q, $v) => $q->where('h.agente_id', $v));

        if ($user->rol === 'tienda') {
            $base->where('h.tienda_id', $user->tienda_id);
        } elseif ($request->tienda) {
            $base->where('h.tienda_id', $request->tienda);
        }

        $kpis = (clone $base)->selectRaw("
            COUNT(*)                                                            AS total_mov,
            COALESCE(SUM(CASE WHEN h.accion='SUMA'  THEN h.cantidad ELSE 0 END),0) AS total_entradas,
            COALESCE(SUM(CASE WHEN h.accion='RESTA' THEN h.cantidad ELSE 0 END),0) AS total_salidas,
            COUNT(DISTINCT h.tienda_id)                                         AS tiendas_afectadas,
            COUNT(DISTINCT h.agente_id)                                         AS agentes_involucrados
        ")->first();

        $movimientos = (clone $base)
            ->select([
                'h.id', 'h.fecha_hora', 'h.tienda_id', 'h.accion', 'h.cantidad',
                'h.motivo', 'h.observacion',
                DB::raw("COALESCE(a.nombres, 'Sistema') AS agente_nombre"),
                DB::raw("COALESCE(it.producto_nombre, 'Chip/Otros') AS producto_nombre"),
                DB::raw("COALESCE(it.tipo, 'CHIP') AS producto_tipo"),
            ])
            ->orderByDesc('h.fecha_hora')
            ->paginate($perPage);

        return response()->json(['kpis' => $kpis, 'movimientos' => $movimientos]);
    }

    public function kpis(Request $request): JsonResponse
    {
        if (! Schema::hasTable('historial_inventario')) {
            return response()->json(null);
        }

        $user  = $request->user();
        $query = DB::table('historial_inventario as h')
            ->when($request->fecha_desde, fn($q, $f) => $q->whereDate('h.fecha_hora', '>=', $f))
            ->when($request->fecha_hasta, fn($q, $f) => $q->whereDate('h.fecha_hora', '<=', $f));

        if ($user->rol === 'tienda') {
            $query->where('h.tienda_id', $user->tienda_id);
        }

        return response()->json($query->selectRaw("
            COUNT(*)                                                                AS total_mov,
            COALESCE(SUM(CASE WHEN h.accion='SUMA'  THEN h.cantidad ELSE 0 END),0) AS total_entradas,
            COALESCE(SUM(CASE WHEN h.accion='RESTA' THEN h.cantidad ELSE 0 END),0) AS total_salidas,
            COUNT(DISTINCT h.tienda_id)                                             AS tiendas_afectadas
        ")->first());
    }
}
