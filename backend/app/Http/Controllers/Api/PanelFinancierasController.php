<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PanelFinancierasController extends Controller
{
    // ── GET /financieras — Listado de ventas con financieras ──────────────────
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

        [$anio, $mes] = explode('-', $filtroMes . '-01');
        $fechaIni = "{$anio}-{$mes}-01";
        $fechaFin = date('Y-m-t', strtotime($fechaIni));

        $query = DB::table('reporte_categorias as rc')
            ->join('reportes as r', 'r.id', '=', 'rc.reporte_id')
            ->leftJoin('tiendas as ti', DB::raw('ti.codigo COLLATE utf8mb4_unicode_ci'), '=', DB::raw('r.tienda_id COLLATE utf8mb4_unicode_ci'))
            ->leftJoin('usuarios as u_conf', 'u_conf.id', '=', 'rc.desembolso_confirmado_por')
            ->where('rc.tipo', 'equipos_accesorios')
            ->where(function ($q) {
                $q->whereNotNull('rc.financiera')
                  ->where('rc.financiera', '!=', '')
                  ->orWhereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(rc.detalle, '$.tipo_pago'))) = 'CUOTAS'");
            })
            ->whereBetween('r.fecha', [$fechaIni, $fechaFin])
            ->select(
                'rc.id',
                'rc.financiera',
                'rc.comision_estado',
                'rc.desembolso_confirmado_en',
                'rc.monto as efectivo_inicial_cobrado',
                'rc.detalle',
                'r.fecha',
                'r.tienda_id',
                'ti.nombre as tienda_nombre',
                'u_conf.nombre as confirmado_por_nombre'
            )
            ->orderByRaw("rc.comision_estado ASC")
            ->orderByDesc('r.fecha');

        if ($filtroEstado !== 'TODAS') {
            $query->where('rc.comision_estado', $filtroEstado);
        } else {
            $query->whereIn('rc.comision_estado', ['PENDIENTE', 'APROBADA']);
        }

        if ($filtroFinanciera) $query->where('rc.financiera', $filtroFinanciera);
        if ($filtroTienda)     $query->where('r.tienda_id', $filtroTienda);

        $ventas = $query->get();

        // Resolver nombres de vendedores desde detalle JSON
        $vendedorIds = [];
        foreach ($ventas as $v) {
            $d   = json_decode($v->detalle, true) ?? [];
            $vid = (int)($d['vendedor_id'] ?? 0);
            if ($vid > 0) $vendedorIds[$vid] = true;
        }
        $nombresVendedores = [];
        if (!empty($vendedorIds)) {
            $nombresVendedores = DB::table('agentes')
                ->whereIn('id', array_keys($vendedorIds))
                ->pluck('nombres', 'id')
                ->toArray();
        }

        // Calcular totales y enriquecer datos
        $totalPendiente  = 0.0;
        $totalConfirmado = 0.0;
        $countPendiente  = 0;

        $ventasEnriquecidas = $ventas->map(function ($v) use ($nombresVendedores, &$totalPendiente, &$totalConfirmado, &$countPendiente) {
            $detalle  = json_decode($v->detalle, true) ?? [];
            $vendId   = (int)($detalle['vendedor_id'] ?? 0);
            $porCobrar = (float)($detalle['por_cobrar_financiera'] ?? 0);

            if ($v->comision_estado === 'PENDIENTE') {
                $totalPendiente += $porCobrar;
                $countPendiente++;
            } else {
                $totalConfirmado += $porCobrar;
            }

            return array_merge((array)$v, [
                'detalle'        => $detalle,
                'vendedor_nombre' => $nombresVendedores[$vendId] ?? null,
                'por_cobrar'     => $porCobrar,
            ]);
        });

        // Opciones de filtros
        $financierasLista = DB::table('reporte_categorias')
            ->whereNotNull('financiera')
            ->distinct()
            ->orderBy('financiera')
            ->pluck('financiera');

        $tiendasLista = DB::table('tiendas')
            ->orderBy('nombre')
            ->select('codigo', 'nombre')
            ->get();

        return response()->json([
            'data'             => $ventasEnriquecidas->values(),
            'totales'          => [
                'pendiente'   => round($totalPendiente, 2),
                'confirmado'  => round($totalConfirmado, 2),
                'total'       => round($totalPendiente + $totalConfirmado, 2),
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

        $updated = DB::table('reporte_categorias')
            ->where('id', $id)
            ->where('comision_estado', 'PENDIENTE')
            ->update([
                'comision_estado'           => 'APROBADA',
                'desembolso_confirmado_en'  => now(),
                'desembolso_confirmado_por' => $user->id,
            ]);

        if (!$updated) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado o ya confirmado.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Desembolso confirmado correctamente.']);
    }

    // ── POST /financieras/{id}/revertir-desembolso ────────────────────────────
    public function revertirDesembolso(int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $updated = DB::table('reporte_categorias')
            ->where('id', $id)
            ->where('comision_estado', 'APROBADA')
            ->update([
                'comision_estado'           => 'PENDIENTE',
                'desembolso_confirmado_en'  => null,
                'desembolso_confirmado_por' => null,
            ]);

        if (!$updated) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado o no está en estado aprobado.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Desembolso revertido a pendiente.']);
    }
}
