<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class HistorialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = $request->integer('per_page', 15);

        $query = Reporte::query()
            ->leftJoin('agentes as a', 'reportes.agente_id', '=', 'a.id')
            ->select([
                'reportes.*',
                DB::raw("COALESCE(a.nombres, 'Agente') AS agente_nombre"),
            ]);

        if ($user->rol === 'tienda') {
            $query->where('reportes.tienda_id', $user->tienda_id);
        } elseif ($request->tienda) {
            $query->where('reportes.tienda_id', $request->tienda);
        }

        $query
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereDate('reportes.fecha', '>=', $f))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereDate('reportes.fecha', '<=', $f))
            ->when($request->agente_id,   fn ($q, $v) => $q->where('reportes.agente_id', $v))
            ->when($request->estado,      fn ($q, $v) => $q->where('reportes.estado', $v));

        $reportes = $query
            ->orderByDesc('reportes.fecha')
            ->orderByDesc('reportes.id')
            ->paginate($perPage);

        return response()->json($reportes);
    }

    public function exportar(Request $request): Response
    {
        $user  = $request->user();

        $query = Reporte::query()
            ->leftJoin('agentes as a', 'reportes.agente_id', '=', 'a.id')
            ->select([
                'reportes.id', 'reportes.fecha', 'reportes.tienda_id',
                'reportes.total_calculado', 'reportes.efectivo_esperado',
                'reportes.efectivo_entregado', 'reportes.diferencia',
                'reportes.estado', 'reportes.destino_efectivo',
                DB::raw("COALESCE(a.nombres, 'Agente') AS agente_nombre"),
            ]);

        if ($user->rol === 'tienda') {
            $query->where('reportes.tienda_id', $user->tienda_id);
        } elseif ($request->tienda) {
            $query->where('reportes.tienda_id', $request->tienda);
        }

        $query
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereDate('reportes.fecha', '>=', $f))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereDate('reportes.fecha', '<=', $f))
            ->when($request->agente_id,   fn ($q, $v) => $q->where('reportes.agente_id', $v))
            ->when($request->estado,      fn ($q, $v) => $q->where('reportes.estado', $v));

        $registros = $query->orderByDesc('reportes.fecha')->orderByDesc('reportes.id')->get();

        $filename = 'Historial_' . date('Ymd_His') . '.csv';

        $callback = function () use ($registros) {
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, ['ID', 'Fecha', 'Tienda', 'Agente', 'Total General', 'Esperado', 'Entregado', 'Diferencia', 'Estado', 'Destino Efectivo']);
            foreach ($registros as $r) {
                fputcsv($out, [
                    $r->id,
                    $r->fecha,
                    $r->tienda_id,
                    $r->agente_nombre,
                    number_format((float) $r->total_calculado, 2, '.', ''),
                    number_format((float) $r->efectivo_esperado, 2, '.', ''),
                    number_format((float) $r->efectivo_entregado, 2, '.', ''),
                    number_format((float) $r->diferencia, 2, '.', ''),
                    $r->estado,
                    $r->destino_efectivo ?? 'TIENDA',
                ]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
