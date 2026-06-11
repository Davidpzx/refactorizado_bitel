<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgenteRequest;
use App\Http\Requests\UpdateAgenteRequest;
use App\Models\Agente;
use App\Models\Reporte;
use App\Services\AgenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgenteController extends Controller
{
    public function __construct(private readonly AgenteService $service) {}

    public function index(Request $request): JsonResponse
    {
        $agentes = Agente::query()
            ->when($request->q, fn($q, $t) => $q->buscar($t))
            ->when($request->tienda, fn($q, $t) => $q->porTienda($t))
            ->when($request->estado, fn($q, $e) => $q->where('estado', $e))
            ->orderBy('nombres')
            ->paginate($request->integer('per_page', 20));

        return response()->json($agentes);
    }

    public function show(Agente $agente): JsonResponse
    {
        return response()->json($agente);
    }

    public function store(StoreAgenteRequest $request): JsonResponse
    {
        $agente = $this->service->crear($request->validated());
        return response()->json($agente, 201);
    }

    public function update(UpdateAgenteRequest $request, Agente $agente): JsonResponse
    {
        $agente = $this->service->actualizar($agente, $request->validated());
        return response()->json($agente);
    }

    public function destroy(Agente $agente): JsonResponse
    {
        if ($agente->reportes()->exists()) {
            return response()->json(['error' => 'No se puede eliminar: el agente tiene reportes asociados.'], 422);
        }
        $agente->delete();
        return response()->json(null, 204);
    }

    public function ventas(Agente $agente, Request $request): JsonResponse
    {
        $reportes = Reporte::query()
            ->where('agente_id', $agente->id)
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereDate('fecha', '>=', $f))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereDate('fecha', '<=', $f))
            ->withCount('ventas')
            ->select([
                'id', 'fecha', 'tienda_id', 'total_calculado',
                'efectivo_entregado', 'diferencia', 'estado', 'ventas_count',
            ])
            ->orderByDesc('fecha')
            ->paginate($request->integer('per_page', 20));

        $stats = Reporte::query()
            ->where('agente_id', $agente->id)
            ->where('estado', '!=', 'borrador')
            ->selectRaw('
                COUNT(*) as total_reportes,
                COALESCE(SUM(total_calculado), 0) as total_vendido,
                COALESCE(SUM(diferencia), 0) as diferencia_acumulada
            ')
            ->first();

        return response()->json(['agente' => $agente, 'stats' => $stats, 'reportes' => $reportes]);
    }

    public function comisiones(Agente $agente, Request $request): JsonResponse
    {
        $comisiones = \App\Models\Venta::query()
            ->where('vendedor_id', $agente->id)
            ->where('comision_estado', 'ACTIVA')
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereHas('reporte', fn ($r) => $r->whereDate('fecha', '>=', $f)))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereHas('reporte', fn ($r) => $r->whereDate('fecha', '<=', $f)))
            ->selectRaw('COALESCE(SUM(comision_generada), 0) as total_comision, COUNT(*) as total_ventas')
            ->first();

        return response()->json(['agente' => $agente, 'comisiones' => $comisiones]);
    }

    // ── POST /agentes/{id}/token-seguridad — Generar/revocar token de emergencia ──
    public function tokenSeguridad(int $id, Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'mensaje' => 'No autorizado.'], 403);
        }

        $tipo = $request->input('tipo', 'diario');

        if ($tipo === 'revocar') {
            \Illuminate\Support\Facades\DB::table('agentes')->where('id', $id)
                ->update(['token_emergencia' => null, 'expiracion_token' => null]);
            return response()->json(['success' => true, 'accion' => 'revocado']);
        }

        $token      = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $expiracion = $tipo === 'permanente' ? '2099-12-31 23:59:59' : now()->timezone('America/Lima')->format('Y-m-d 23:59:59');

        \Illuminate\Support\Facades\DB::table('agentes')->where('id', $id)
            ->update(['token_emergencia' => $token, 'expiracion_token' => $expiracion]);

        return response()->json([
            'success'     => true,
            'token'       => $token,
            'expiracion'  => $expiracion,
            'tipo'        => $tipo,
        ]);
    }

    // ── PATCH /agentes/{id}/fechas-laborales — Actualizar fechas de ingreso/prueba
    public function editarFechasLaborales(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'msg' => 'Acceso denegado.'], 403);
        }

        $agente = \App\Models\Agente::find($id);
        if (!$agente) {
            return response()->json(['success' => false, 'msg' => 'Agente no encontrado.'], 404);
        }

        $data = array_filter([
            'fecha_ingreso'       => $request->input('fecha_ingreso')       ?: null,
            'fecha_prueba_inicio' => $request->input('fecha_prueba_inicio') ?: null,
            'fecha_prueba_fin'    => $request->input('fecha_prueba_fin')    ?: null,
        ], fn($v) => $v !== false);

        \Illuminate\Support\Facades\DB::table('agentes')->where('id', $id)->update($data);

        return response()->json(['success' => true, 'msg' => 'Fechas laborales actualizadas correctamente.']);
    }
}
