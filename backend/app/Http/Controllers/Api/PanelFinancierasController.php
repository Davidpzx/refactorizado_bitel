<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PanelFinancierasController extends Controller
{
    // ── GET /financieras — Ventas de equipos a cuotas (esquema NORMALIZADO) ─────
    // D1: lee ventas/venta_equipos (no la tabla huérfana reporte_categorias).
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $filtroFinanciera = trim($request->input('financiera', ''));
        $filtroTienda     = trim($request->input('tienda', ''));
        $filtroEstado     = $request->input('estado', 'PENDIENTE');
        $filtroMes        = $request->input('mes', date('Y-m'));

        $fechaIni = $filtroMes . '-01';
        $fechaFin = date('Y-m-t', strtotime($fechaIni));

        $query = DB::table('ventas as v')
            ->join('venta_equipos as ve', 've.venta_id', '=', 'v.id')
            ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
            ->leftJoin('tiendas as ti', 'ti.codigo', '=', 'r.tienda_id')
            ->leftJoin('agentes as ag', 'ag.id', '=', 'v.vendedor_id')
            ->where('v.tipo_venta', 'EQUIPO')
            ->where('ve.tipo_pago', 'CUOTAS')
            ->whereBetween('r.fecha', [$fechaIni, $fechaFin])
            ->select(
                'v.id',
                'v.comision_estado',
                'v.comision_generada',
                've.financiera',
                've.por_cobrar_financiera',
                've.producto_nombre_snap',
                'r.fecha',
                'r.tienda_id',
                'ti.nombre as tienda_nombre',
                'ag.nombres as vendedor_nombre'
            )
            ->orderByRaw('v.comision_estado ASC')
            ->orderByDesc('r.fecha');

        if ($filtroEstado !== 'TODAS') {
            $query->where('v.comision_estado', $filtroEstado);
        } else {
            $query->whereIn('v.comision_estado', ['PENDIENTE', 'APROBADA']);
        }
        if ($filtroFinanciera !== '') {
            $query->where('ve.financiera', $filtroFinanciera);
        }
        if ($filtroTienda !== '') {
            $query->where('r.tienda_id', $filtroTienda);
        }

        $ventas = $query->get();

        $totalPendiente  = 0.0;
        $totalConfirmado = 0.0;
        $countPendiente  = 0;

        $data = $ventas->map(function ($v) use (&$totalPendiente, &$totalConfirmado, &$countPendiente) {
            $porCobrar = (float) $v->por_cobrar_financiera;
            if ($v->comision_estado === 'PENDIENTE') {
                $totalPendiente += $porCobrar;
                $countPendiente++;
            } else {
                $totalConfirmado += $porCobrar;
            }

            return [
                'id'                => $v->id,
                'fecha'             => $v->fecha,
                'tienda_id'         => $v->tienda_id,
                'tienda_nombre'     => $v->tienda_nombre,
                'financiera'        => $v->financiera,
                'vendedor_nombre'   => $v->vendedor_nombre,
                'comision_estado'   => $v->comision_estado,
                'comision_generada' => (float) $v->comision_generada,
                'por_cobrar'        => $porCobrar,
                // Compat con el front (item.detalle?.producto_nombre).
                'detalle'           => ['producto_nombre' => $v->producto_nombre_snap],
            ];
        });

        $financierasLista = DB::table('venta_equipos')
            ->whereNotNull('financiera')
            ->where('financiera', '!=', '')
            ->distinct()
            ->orderBy('financiera')
            ->pluck('financiera');

        $tiendasLista = DB::table('tiendas')
            ->orderBy('nombre')
            ->select('codigo', 'nombre')
            ->get();

        return response()->json([
            'data'    => $data->values(),
            'totales' => [
                'pendiente'       => round($totalPendiente, 2),
                'confirmado'      => round($totalConfirmado, 2),
                'total'           => round($totalPendiente + $totalConfirmado, 2),
                'count_pendiente' => $countPendiente,
            ],
            'filtros_disponibles' => [
                'financieras' => $financierasLista,
                'tiendas'     => $tiendasLista,
            ],
        ]);
    }

    // ── POST /financieras/{id}/confirmar-desembolso ───────────────────────────
    public function confirmarDesembolso(int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $venta = DB::table('ventas')->where('id', $id)->where('comision_estado', 'PENDIENTE')->first();
        if (! $venta) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado o ya confirmado.'], 404);
        }

        // D2 — Liberar la comisión del agente al confirmar el desembolso
        // (paridad legacy: EQUIPO_ESTANDAR de config_comisiones, default S/5).
        $comisionEquipo = 5.00;
        if (Schema::hasTable('config_comisiones')) {
            $valor = DB::table('config_comisiones')->where('clave', 'EQUIPO_ESTANDAR')->value('valor');
            if ($valor !== null) {
                $comisionEquipo = (float) $valor;
            }
        }

        DB::table('ventas')->where('id', $id)->update([
            'comision_estado'   => 'APROBADA',
            'comision_generada' => $comisionEquipo,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Desembolso confirmado. Comisión del agente liberada: S/ ' . number_format($comisionEquipo, 2),
        ]);
    }

    // ── POST /financieras/{id}/revertir-desembolso ────────────────────────────
    public function revertirDesembolso(int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $updated = DB::table('ventas')
            ->where('id', $id)
            ->where('comision_estado', 'APROBADA')
            ->update([
                'comision_estado'   => 'PENDIENTE',
                'comision_generada' => 0,
            ]);

        if (! $updated) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado o no está en estado aprobado.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Desembolso revertido a pendiente.']);
    }
}
