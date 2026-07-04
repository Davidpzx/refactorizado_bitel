<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConstanciaController extends Controller
{
    // ── GET /constancias/traslado?tipo=equipos&id=&lote= ──────────────────────
    public function traslado(Request $request)
    {
        $tipo = $request->input('tipo', 'equipos');
        $id   = (int) $request->input('id', 0);
        $lote = trim($request->input('lote', ''));

        if (!$id && !$lote) {
            return response()->json(['message' => 'ID o código de lote requerido.'], 422);
        }

        $traslado = null;
        $items    = [];

        if ($tipo === 'chips') {
            $traslado = DB::table('traslados_chips as tc')
                ->leftJoin('agentes as ae', 'ae.id', '=', 'tc.enviado_por_id')
                ->leftJoin('agentes as ac', 'ac.id', '=', 'tc.confirmado_por_id')
                ->leftJoin('tiendas as te', DB::raw('te.codigo COLLATE utf8mb4_unicode_ci'), '=', DB::raw('tc.tienda_origen COLLATE utf8mb4_unicode_ci'))
                ->leftJoin('tiendas as td', DB::raw('td.codigo COLLATE utf8mb4_unicode_ci'), '=', DB::raw('tc.tienda_destino COLLATE utf8mb4_unicode_ci'))
                ->where('tc.id', $id)
                ->select(
                    'tc.*',
                    'ae.nombres as enviado_por_nombres', 'ae.dni as enviado_por_dni',
                    'ac.nombres as confirmado_por_nombres', 'ac.dni as confirmado_por_dni',
                    'te.nombre as tienda_origen_nombre',
                    'td.nombre as tienda_destino_nombre'
                )
                ->first();
            if ($traslado) $items = [$traslado];
        } elseif ($lote) {
            $items = DB::table('traslados_stock as ts')
                ->leftJoin('inventario_tiendas as it', 'it.id', '=', 'ts.producto_id')
                ->leftJoin('agentes as ae', 'ae.id', '=', 'ts.enviado_por_id')
                ->leftJoin('agentes as ac', 'ac.id', '=', 'ts.confirmado_por_id')
                ->leftJoin('tiendas as te', DB::raw('te.codigo COLLATE utf8mb4_unicode_ci'), '=', DB::raw('ts.tienda_origen COLLATE utf8mb4_unicode_ci'))
                ->leftJoin('tiendas as td', DB::raw('td.codigo COLLATE utf8mb4_unicode_ci'), '=', DB::raw('ts.tienda_destino COLLATE utf8mb4_unicode_ci'))
                ->where('ts.codigo_lote', $lote)
                ->select(
                    'ts.*',
                    DB::raw('COALESCE(ts.producto_nombre_snap, it.producto_nombre) as producto_nombre'),
                    DB::raw('COALESCE(ts.imei_serial_snap, it.imei_serial) as imei_serial'),
                    'it.tipo as tipo_producto',
                    'ae.nombres as enviado_por_nombres', 'ae.dni as enviado_por_dni',
                    'ac.nombres as confirmado_por_nombres', 'ac.dni as confirmado_por_dni',
                    'te.nombre as tienda_origen_nombre',
                    'td.nombre as tienda_destino_nombre'
                )
                ->get()->toArray();
            if (!empty($items)) $traslado = $items[0];
        } else {
            $traslado = DB::table('traslados_stock as ts')
                ->leftJoin('inventario_tiendas as it', 'it.id', '=', 'ts.producto_id')
                ->leftJoin('agentes as ae', 'ae.id', '=', 'ts.enviado_por_id')
                ->leftJoin('agentes as ac', 'ac.id', '=', 'ts.confirmado_por_id')
                ->leftJoin('tiendas as te', DB::raw('te.codigo COLLATE utf8mb4_unicode_ci'), '=', DB::raw('ts.tienda_origen COLLATE utf8mb4_unicode_ci'))
                ->leftJoin('tiendas as td', DB::raw('td.codigo COLLATE utf8mb4_unicode_ci'), '=', DB::raw('ts.tienda_destino COLLATE utf8mb4_unicode_ci'))
                ->where('ts.id', $id)
                ->select(
                    'ts.*',
                    DB::raw('COALESCE(ts.producto_nombre_snap, it.producto_nombre) as producto_nombre'),
                    DB::raw('COALESCE(ts.imei_serial_snap, it.imei_serial) as imei_serial'),
                    'it.tipo as tipo_producto',
                    'ae.nombres as enviado_por_nombres', 'ae.dni as enviado_por_dni',
                    'ac.nombres as confirmado_por_nombres', 'ac.dni as confirmado_por_dni',
                    'te.nombre as tienda_origen_nombre',
                    'td.nombre as tienda_destino_nombre'
                )
                ->first();
            if ($traslado) $items = [$traslado];
        }

        if (!$traslado) {
            return response()->json(['message' => 'Constancia no encontrada.'], 404);
        }

        $user = Auth::user();
        abort_if(
            $user->rol !== 'admin'
                && $traslado->tienda_origen !== $user->tienda_id
                && $traslado->tienda_destino !== $user->tienda_id,
            403,
            'No tienes permisos sobre este traslado.'
        );

        $empresa = DB::table('configuraciones')->first();

        $pdf = Pdf::loadView('constancias.traslado', compact('traslado', 'items', 'tipo', 'empresa'))
            ->setPaper('a4', 'portrait');

        $filename = "constancia_traslado_{$id}" . ($lote ? "_{$lote}" : '') . ".pdf";
        return $pdf->download($filename);
    }

    // ── GET /constancias/agente/{id} ──────────────────────────────────────────
    public function agente(int $id)
    {
        $agente = DB::table('agentes as a')
            ->leftJoin('tiendas as t', 't.codigo', '=', 'a.tienda_base')
            ->where('a.id', $id)
            ->select('a.*', 't.nombre as tienda_nombre')
            ->first();

        if (!$agente) {
            return response()->json(['message' => 'Agente no encontrado.'], 404);
        }

        $user = Auth::user();
        abort_if(
            $user->rol !== 'admin' && $agente->tienda_base !== $user->tienda_id,
            403,
            'No tienes permisos sobre este agente.'
        );

        $empresa = DB::table('configuraciones')->first();

        $pdf = Pdf::loadView('constancias.agente', compact('agente', 'empresa'))
            ->setPaper('a4', 'portrait');

        $nombres = str_replace(' ', '_', $agente->nombres ?? 'agente');
        return $pdf->download("certificado_{$nombres}_{$id}.pdf");
    }

    // ── GET /constancias/boleta/{id} — Boleta de pago de planilla ────────────
    public function boleta(int $id)
    {
        $pago = DB::table('pagos_planilla')->where('id', $id)->first();
        if (!$pago) {
            return response()->json(['message' => 'Boleta no encontrada.'], 404);
        }

        $agente  = DB::table('agentes')->where('id', $pago->agente_id)->first();
        if (!$agente) {
            return response()->json(['message' => 'Agente no encontrado.'], 404);
        }

        $user = Auth::user();
        abort_if(
            $user->rol !== 'admin' && $agente->tienda_base !== $user->tienda_id,
            403,
            'No tienes permisos sobre esta boleta.'
        );

        $empresa = DB::table('configuraciones')->first();

        $pdf = Pdf::loadView('constancias.boleta', compact('pago', 'agente', 'empresa'))
            ->setPaper([0, 0, 595, 842], 'portrait');

        $nombre = str_replace(' ', '_', $agente->nombres ?? 'agente');
        return $pdf->download("boleta_{$nombre}_{$id}.pdf");
    }

    // ── POST /constancias/boleta — Crear boleta + devolver PDF ───────────────
    public function crearBoleta(Request $request)
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $agenteId    = (int) $request->input('agente_id', 0);
        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin    = $request->input('fecha_fin');
        $sueldo      = round((float) $request->input('sueldo_base', 0), 2);
        $bonos       = round((float) $request->input('bonos', 0), 2);
        $tardanza    = round((float) $request->input('dscto_tardanza', 0), 2);
        $adelantos   = round((float) $request->input('dscto_adelantos', 0), 2);
        $total       = round((float) $request->input('total_neto', 0), 2);

        if (!$agenteId || !$fechaInicio || !$fechaFin || $total <= 0) {
            return response()->json(['message' => 'Datos incompletos o inválidos.'], 422);
        }

        $id = DB::table('pagos_planilla')->insertGetId([
            'agente_id'           => $agenteId,
            'fecha_inicio'        => $fechaInicio,
            'fecha_fin'           => $fechaFin,
            'sueldo_base'         => $sueldo,
            'bonos_comisiones'    => $bonos,
            'descuento_tardanza'  => $tardanza,
            'descuento_adelantos' => $adelantos,
            'total_pagado'        => $total,
            'estado'              => 'PENDIENTE',
            'fecha_pago'          => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return $this->boleta($id);
    }

    // ── PATCH /constancias/boleta/{id} — Marcar como PAGADO o eliminar ────────
    public function accionBoleta(int $id, Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['ok' => false, 'message' => 'Solo administradores.'], 403);
        }

        $accion = $request->input('accion'); // 'pagar' | 'eliminar'

        if ($accion === 'eliminar') {
            DB::table('pagos_planilla')->where('id', $id)->delete();
            return response()->json(['ok' => true, 'message' => 'Boleta eliminada.']);
        }

        DB::table('pagos_planilla')->where('id', $id)->update(['estado' => 'PAGADO', 'updated_at' => now()]);
        return response()->json(['ok' => true, 'message' => 'Boleta marcada como PAGADO.']);
    }

    // ── GET /constancias/reporte/{id} — Voucher del reporte ───────────────────
    public function reporte(int $id)
    {
        $reporte = DB::table('reportes as r')
            ->leftJoin('agentes as a', 'a.id', '=', 'r.agente_id')
            ->leftJoin('tiendas as t', 't.codigo', '=', 'r.tienda_id')
            ->where('r.id', $id)
            ->select('r.*', 'a.nombres as agente_nombre', 'a.dni as agente_dni', 't.nombre as tienda_nombre')
            ->first();

        if (!$reporte) {
            return response()->json(['message' => 'Reporte no encontrado.'], 404);
        }

        $user = Auth::user();
        abort_if(
            $user->rol !== 'admin' && $reporte->tienda_id !== $user->tienda_id,
            403,
            'No tienes permisos sobre este reporte.'
        );

        $ventas = Venta::with(['equipo', 'linea', 'cliente', 'vendedor'])
            ->where('reporte_id', $id)
            ->orderBy('id')
            ->get();

        $salidas = DB::table('reporte_salidas')->where('reporte_id', $id)->orderBy('id')->get();

        $empresa = DB::table('configuraciones')->first();

        $pdf = Pdf::loadView('constancias.reporte', compact('reporte', 'ventas', 'salidas', 'empresa'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("reporte_{$id}.pdf");
    }
}
