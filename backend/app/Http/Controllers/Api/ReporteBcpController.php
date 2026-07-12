<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\UserAgentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReporteBcpController extends Controller
{
    public function __construct(private readonly UserAgentResolver $userAgentResolver)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if (!Schema::hasTable('reportes_bcp')) {
            return response()->json([
                'warning' => 'La tabla reportes_bcp no existe en esta base de datos.',
                'data'    => [],
                'kpis'    => ['total_efectivo' => 0, 'total_tarjeta' => 0, 'total_operaciones' => 0, 'total_registros' => 0],
            ]);
        }

        $desde  = $request->get('fecha_desde', now()->toDateString());
        $hasta  = $request->get('fecha_hasta', now()->toDateString());
        $tienda = $request->get('tienda_id');

        $query = DB::table('reportes_bcp as rb')
            ->leftJoin('tiendas as t', 't.id', '=', 'rb.sucursal_id')
            ->leftJoin('agentes as a', 'a.id', '=', 'rb.agente_id')
            ->whereBetween('rb.fecha', [$desde, $hasta]);

        if ($tienda) {
            $query->where('rb.sucursal_id', $tienda);
        }

        $kpis = (clone $query)
            ->selectRaw('
                COALESCE(SUM(rb.queda_efectivo), 0)         AS total_efectivo,
                COALESCE(SUM(rb.queda_tarjeta), 0)          AS total_tarjeta,
                COALESCE(SUM(rb.cantidad_operaciones), 0)   AS total_operaciones,
                COUNT(*) AS total_registros
            ')
            ->first();

        // Alertas: tiendas con < 200 operaciones hoy
        $alertas = [];
        try {
            $alertas = DB::table('reportes_bcp as rb')
                ->leftJoin('tiendas as t', 't.id', '=', 'rb.sucursal_id')
                ->where('rb.fecha', now()->toDateString())
                ->selectRaw('rb.sucursal_id, t.nombre AS nombre_tienda, SUM(rb.cantidad_operaciones) AS total_ops')
                ->groupBy('rb.sucursal_id', 't.nombre')
                ->having('total_ops', '<', 200)
                ->orderBy('total_ops')
                ->get();
        } catch (\Throwable $e) {
            $alertas = [];
        }

        $reportes = (clone $query)
            ->select([
                'rb.*',
                't.nombre AS nombre_tienda',
                'a.nombres AS nombre_agente',
            ])
            ->orderBy('rb.sucursal_id')
            ->orderByDesc('rb.fecha')
            ->limit(300)
            ->get()
            ->map(function ($row) {
                // turno_hora almacena "TURNO|NombreAgente" — separar
                $partes = explode('|', $row->turno_hora ?? '', 2);
                $row->turno          = $partes[0] ?? '';
                $row->agente_nombre_bcp = $partes[1] ?? null;
                return $row;
            });

        return response()->json([
            'kpis'     => $kpis,
            'alertas'  => $alertas,
            'reportes' => $reportes,
            'periodo'  => ['desde' => $desde, 'hasta' => $hasta],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!Schema::hasTable('reportes_bcp')) {
            return response()->json(['error' => 'Tabla reportes_bcp no existe.'], 422);
        }

        $data = $request->validate([
            'fecha'                => ['required', 'date'],
            'sucursal_id'          => ['required', 'integer'],
            'turno_hora'           => ['required', 'string', 'max:50'],
            'nombre_agente_bcp'    => ['nullable', 'string', 'max:100'],
            'cantidad_operaciones' => ['required', 'integer', 'min:0'],
            'queda_efectivo'       => ['required', 'numeric', 'min:0'],
            'queda_tarjeta'        => ['required', 'numeric', 'min:0'],
            'incidencias_sistema'  => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $agente = $this->userAgentResolver->resolveOrFail($user);
        $sucursalId = (int) $data['sucursal_id'];

        if (! $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            $sucursalId = (int) DB::table('tiendas')
                ->where('codigo', $user->tienda_id)
                ->value('id');

            if ($sucursalId <= 0) {
                return response()->json(['error' => 'No se pudo resolver la sucursal del usuario autenticado.'], 422);
            }
        }

        $turnoGuardado = $data['turno_hora'];
        if (!empty($data['nombre_agente_bcp'])) {
            $turnoGuardado .= '|' . $data['nombre_agente_bcp'];
        }

        $id = DB::table('reportes_bcp')->insertGetId([
            'fecha'                => $data['fecha'],
            'sucursal_id'          => $sucursalId,
            'agente_id'            => $agente->id,
            'turno_hora'           => $turnoGuardado,
            'cantidad_operaciones' => $data['cantidad_operaciones'],
            'queda_efectivo'       => $data['queda_efectivo'],
            'queda_tarjeta'        => $data['queda_tarjeta'],
            'incidencias_sistema'  => $data['incidencias_sistema'] ?? null,
        ]);

        return response()->json(['id' => $id, 'message' => 'Reporte BCP guardado.'], 201);
    }

    public function tiendas(): JsonResponse
    {
        if (!Schema::hasTable('tiendas')) {
            return response()->json([]);
        }
        $tiendas = DB::table('tiendas')->select('id', 'nombre', 'codigo')->orderBy('nombre')->get();
        return response()->json($tiendas);
    }
}
