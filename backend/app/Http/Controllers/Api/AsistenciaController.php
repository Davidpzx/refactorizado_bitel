<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AsistenciaController extends Controller
{
    private function tablaExiste(): bool
    {
        return Schema::hasTable('asistencias');
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json([
                'warning' => 'La tabla asistencias no existe.',
                'data'    => [],
                'kpis'    => ['presentes' => 0, 'ausentes' => 0, 'tardanzas' => 0],
            ]);
        }

        $desde   = $request->get('fecha_desde', now()->toDateString());
        $hasta   = $request->get('fecha_hasta', now()->toDateString());
        $agente  = $request->get('agente_id');

        $query = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->when($agente, fn($q) => $q->where('a.agente_id', $agente))
            ->select([
                'a.*',
                'ag.nombres',
                'ag.tienda_base',
                'ag.hora_salida as salida_oficial',
                'ag.dia_descanso',
            ])
            ->orderByDesc('a.fecha')
            ->orderByDesc('a.hora_ingreso');

        $kpis = (clone $query)
            ->selectRaw("
                SUM(CASE WHEN a.hora_ingreso IS NOT NULL THEN 1 ELSE 0 END)       AS presentes,
                SUM(CASE WHEN a.hora_ingreso IS NULL THEN 1 ELSE 0 END)           AS ausentes,
                SUM(CASE WHEN a.hora_ingreso > '09:00:00' THEN 1 ELSE 0 END)      AS tardanzas,
                SUM(CASE WHEN a.requiere_revision = 1 THEN 1 ELSE 0 END)          AS pendientes_revision
            ")
            ->from('asistencias as a')
            ->join('agentes as ag2', 'ag2.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->when($agente, fn($q) => $q->where('a.agente_id', $agente))
            ->first();

        $data = $query->paginate($request->integer('per_page', 30));

        return response()->json([
            'kpis'   => $kpis,
            'data'   => $data,
        ]);
    }

    public function registrar(Request $request): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        $data = $request->validate([
            'agente_id'       => ['required', 'integer'],
            'fecha'           => ['required', 'date'],
            'hora_ingreso'    => ['nullable', 'string'],
            'hora_salida'     => ['nullable', 'string'],
            'tipo'            => ['nullable', 'string', Rule::in(['ENTRADA', 'SALIDA'])],
            'metodo_marcacion'=> ['nullable', Rule::in(['MANUAL', 'FOTO', 'QR'])],
            'observacion'     => ['nullable', 'string', 'max:255'],
        ]);

        // Verificar si ya tiene asistencia hoy
        $existente = DB::table('asistencias')
            ->where('agente_id', $data['agente_id'])
            ->where('fecha', $data['fecha'])
            ->first();

        if ($existente) {
            // Actualizar salida si ya tiene entrada
            if ($data['tipo'] === 'SALIDA' && !$existente->hora_salida) {
                DB::table('asistencias')
                    ->where('id', $existente->id)
                    ->update(['hora_salida' => $data['hora_salida'] ?? now()->toTimeString()]);
                return response()->json(['message' => 'Salida registrada.', 'id' => $existente->id]);
            }
            return response()->json(['message' => 'Ya tiene asistencia registrada hoy.', 'id' => $existente->id]);
        }

        $id = DB::table('asistencias')->insertGetId([
            'agente_id'        => $data['agente_id'],
            'fecha'            => $data['fecha'],
            'hora_ingreso'     => $data['hora_ingreso'] ?? now()->toTimeString(),
            'metodo_marcacion' => $data['metodo_marcacion'] ?? 'MANUAL',
            'observacion'      => $data['observacion'] ?? null,
            'requiere_revision'=> 0,
            'created_at'       => now(),
        ]);

        return response()->json(['message' => 'Asistencia registrada.', 'id' => $id], 201);
    }

    public function aprobar(Request $request, int $id): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        DB::table('asistencias')->where('id', $id)->update(['requiere_revision' => 0]);
        return response()->json(['message' => 'Asistencia aprobada.']);
    }

    public function exportar(Request $request)
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        $desde  = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta  = $request->get('fecha_hasta', now()->toDateString());
        $agente = $request->get('agente_id');

        $rows = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->when($agente, fn($q) => $q->where('a.agente_id', $agente))
            ->select('a.fecha', 'ag.nombres', 'ag.tienda_base', 'a.hora_ingreso', 'a.hora_salida', 'a.metodo_marcacion', 'a.observacion')
            ->orderByDesc('a.fecha')
            ->get();

        $csv  = "\xEF\xBB\xBF";
        $csv .= "Fecha,Agente,Tienda,Ingreso,Salida,Método,Observación\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row->fecha,
                '"' . str_replace('"', '""', $row->nombres) . '"',
                $row->tienda_base,
                $row->hora_ingreso ?? '',
                $row->hora_salida  ?? '',
                $row->metodo_marcacion ?? 'MANUAL',
                '"' . str_replace('"', '""', $row->observacion ?? '') . '"',
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="asistencias_' . $desde . '_' . $hasta . '.csv"',
        ]);
    }
}
