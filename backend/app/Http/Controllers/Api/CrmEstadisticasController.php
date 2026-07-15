<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmEstadisticasController extends Controller
{
    public function resumen(Request $request): JsonResponse
    {
        $user = $request->user();
        $tiendaId = trim((string) $request->query('tienda_id'));

        $esAdmin = $user?->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE) ?? false;
        $tiendaUsuario = trim((string) $user?->tienda_id);
        if (! $esAdmin && ($tiendaUsuario === '' || $tiendaId !== $tiendaUsuario)) {
            return response()->json(['message' => 'No tienes permisos sobre esta tienda.'], 403);
        }

        $inicioMes = now()->startOfMonth();

        $leadsActivos = DB::table('leads')
            ->where('tienda_id', $tiendaId)
            ->whereNotIn('estado', ['CONVERTIDO', 'PERDIDO'])
            ->count();

        $totalLeadsMes = DB::table('leads')
            ->where('tienda_id', $tiendaId)
            ->where('creado_en', '>=', $inicioMes)
            ->count();

        $convertidosMes = DB::table('leads')
            ->where('tienda_id', $tiendaId)
            ->where('estado', 'CONVERTIDO')
            ->where('creado_en', '>=', $inicioMes)
            ->count();

        $conversionMes = $totalLeadsMes > 0 ? round(($convertidosMes / $totalLeadsMes) * 100, 1) : 0.0;

        $ventasMes = (float) DB::table('ventas as v')
            ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
            ->where('r.tienda_id', $tiendaId)
            ->where('r.fecha', '>=', $inicioMes->toDateString())
            ->where('r.estado', '!=', 'borrador')
            ->where('v.comision_estado', '!=', 'ANULADA')
            ->sum('v.monto_total');

        return response()->json([
            'leads_activos' => $leadsActivos,
            'conversion_mes' => $conversionMes,
            'ventas_mes' => $ventasMes,
        ]);
    }
}