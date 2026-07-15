<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Models\Venta;
use App\Support\Permisos;
use App\Support\TiendaGuard;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConstanciaController extends Controller
{
    /**
     * Perfil de la empresa que cabecera todas las constancias. La tabla real es
     * `configuracion_empresa` (migración 2026_07_09_000002, nombre heredado del
     * legacy `gerencia/configuracion_empresa.php`). Devuelve null si la fila
     * única aún no existe; las vistas ya tienen texto por defecto.
     */
    private function empresa(): ?object
    {
        return DB::table('configuracion_empresa')->first();
    }

    /**
     * `tiendas.codigo` y las columnas `tienda_origen`/`tienda_destino` de los
     * traslados pueden traer colaciones distintas cuando la BD viene adoptada
     * del legacy, y MySQL aborta el JOIN con "Illegal mix of collations". El
     * COLLATE explícito lo resuelve, pero SQLite —el motor de la suite— no
     * conoce esa colación y tumba la consulta entera. Se emite solo en MySQL.
     */
    private function codigoTienda(string $columna): Expression|string
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? DB::raw("{$columna} COLLATE utf8mb4_unicode_ci")
            : $columna;
    }

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
                ->leftJoin('tiendas as te', $this->codigoTienda('te.codigo'), '=', $this->codigoTienda('tc.tienda_origen'))
                ->leftJoin('tiendas as td', $this->codigoTienda('td.codigo'), '=', $this->codigoTienda('tc.tienda_destino'))
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
                ->leftJoin('tiendas as te', $this->codigoTienda('te.codigo'), '=', $this->codigoTienda('ts.tienda_origen'))
                ->leftJoin('tiendas as td', $this->codigoTienda('td.codigo'), '=', $this->codigoTienda('ts.tienda_destino'))
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
                ->leftJoin('tiendas as te', $this->codigoTienda('te.codigo'), '=', $this->codigoTienda('ts.tienda_origen'))
                ->leftJoin('tiendas as td', $this->codigoTienda('td.codigo'), '=', $this->codigoTienda('ts.tienda_destino'))
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
            TiendaGuard::bloqueaAcceso($user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE), $user->tienda_id, $traslado->tienda_origen, $traslado->tienda_destino),
            403,
            'No tienes permisos sobre este traslado.'
        );

        $empresa = $this->empresa();

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
            TiendaGuard::bloqueaAcceso($user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE), $user->tienda_id, $agente->tienda_base),
            403,
            'No tienes permisos sobre este agente.'
        );

        $empresa = $this->empresa();

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
            TiendaGuard::bloqueaAcceso($user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE), $user->tienda_id, $agente->tienda_base),
            403,
            'No tienes permisos sobre esta boleta.'
        );

        $empresa = $this->empresa();

        $pdf = Pdf::loadView('constancias.boleta', compact('pago', 'agente', 'empresa'))
            ->setPaper([0, 0, 595, 842], 'portrait');

        $nombre = str_replace(' ', '_', $agente->nombres ?? 'agente');
        return $pdf->download("boleta_{$nombre}_{$id}.pdf");
    }

    // ── POST /constancias/boleta — Crear boleta + devolver PDF ───────────────
    public function crearBoleta(Request $request)
    {
        $user = Auth::user();
        if (! Permisos::puede($user, 'gestionar_planilla')) {
            return response()->json(['message' => 'Solo administradores o gerentes.'], 403);
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
        if (! Permisos::puede($user, 'gestionar_planilla')) {
            return response()->json(['ok' => false, 'message' => 'Solo administradores o gerentes.'], 403);
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
            TiendaGuard::bloqueaAcceso($user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE), $user->tienda_id, $reporte->tienda_id),
            403,
            'No tienes permisos sobre este reporte.'
        );

        $ventas = Venta::with(['equipo', 'linea', 'cliente', 'vendedor'])
            ->where('reporte_id', $id)
            ->where('comision_estado', '!=', 'ANULADA')
            ->orderBy('id')
            ->get();

        $salidas = DB::table('reporte_salidas')->where('reporte_id', $id)->orderBy('id')->get();

        $ticketsPorVenta = DB::table('tickets_emitidos')
            ->whereIn('venta_id', $ventas->pluck('id'))
            ->get(['venta_id', 'forma_pago', 'efectivo', 'vuelto'])
            ->keyBy('venta_id');

        $empresa = $this->empresa();

        $pdf = Pdf::loadView('constancias.reporte', compact('reporte', 'ventas', 'salidas', 'empresa', 'ticketsPorVenta'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("reporte_{$id}.pdf");
    }
}
