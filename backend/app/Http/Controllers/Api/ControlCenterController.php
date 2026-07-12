<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ControlCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'anomalias_caja' => $this->anomaliasCaja(),
            'precios_pendientes' => $this->preciosPendientes(),
            'traslados_pendientes' => $this->trasladosPendientes(),
            'postulantes_pendientes' => $this->postulantesPendientes(),
            'financieras_pendientes' => $this->financierasPendientes(),
            'alertas_bcp' => $this->alertasBcp(),
            'alertas_bipay' => $this->alertasBipay(),
            'notificaciones_sistema' => $this->notificacionesSistema(),
        ]);
    }

    public function marcarNotificacion(Request $request): JsonResponse
    {
        if (! $request->user()->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No autorizado',
            ], 403);
        }

        $id = (int) $request->input('id', 0);
        $accion = trim((string) $request->input('accion', 'leido'));

        if ($id <= 0) {
            return response()->json([
                'success' => false,
                'mensaje' => 'ID invalido',
            ], 400);
        }

        if (! in_array($accion, ['leido', 'borrar'], true)) {
            $accion = 'leido';
        }

        try {
            if ($accion === 'borrar') {
                DB::table('sys_notificaciones')->where('id', $id)->delete();
            } else {
                DB::table('sys_notificaciones')
                    ->where('id', $id)
                    ->where('estado', 'pendiente')
                    ->update(['estado' => 'leido']);
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'mensaje' => 'Error interno del servidor',
            ], 500);
        }
    }

    private function anomaliasCaja(): array
    {
        if (! Schema::hasTable('reportes')) {
            return $this->emptyIndicator();
        }

        $items = DB::table('reportes as r')
            ->leftJoin('agentes as ag', 'ag.id', '=', 'r.agente_id')
            ->leftJoin('tiendas as ti', 'ti.codigo', '=', 'r.tienda_id')
            ->whereDate('r.fecha', '>=', now()->subDays(7)->toDateString())
            ->whereRaw('ABS(r.diferencia) > ?', [5])
            ->groupBy('r.agente_id')
            ->havingRaw('COUNT(*) >= ?', [3])
            ->selectRaw('
                r.agente_id,
                MAX(ag.nombres) AS cajero,
                MAX(ti.nombre) AS tienda,
                COUNT(*) AS dias_desc,
                MAX(r.diferencia) AS mayor_diferencia
            ')
            ->orderByDesc('dias_desc')
            ->limit(10)
            ->get()
            ->map(fn ($item) => [
                'agente_id' => (int) $item->agente_id,
                'cajero' => $item->cajero,
                'tienda' => $item->tienda,
                'dias_desc' => (int) $item->dias_desc,
                'mayor_diferencia' => (float) $item->mayor_diferencia,
            ])
            ->values();

        return [
            'count' => $items->count(),
            'data' => $items,
        ];
    }

    private function preciosPendientes(): array
    {
        if (! Schema::hasTable('inventario_tiendas')) {
            return $this->emptyIndicator(false);
        }

        $count = DB::table('inventario_tiendas')
            ->where('estado', 'DISPONIBLE')
            ->where('tipo', '!=', 'CHIP')
            ->where(function ($query) {
                $query->whereNull('precio_minimo')
                    ->orWhere('precio_minimo', '<=', 0)
                    ->orWhereNull('precio_normal')
                    ->orWhere('precio_normal', '<=', 0)
                    ->orWhereNull('precio_costo')
                    ->orWhere('precio_costo', '<=', 0);
            })
            ->count();

        return ['count' => $count];
    }

    private function trasladosPendientes(): array
    {
        $equipos = $this->trasladosEquiposPendientes();
        $chips = $this->trasladosChipsPendientes();

        $items = $equipos['data']
            ->concat($chips['data'])
            ->sort(function (array $left, array $right) {
                $byDate = strcmp((string) $right['fecha_creacion'], (string) $left['fecha_creacion']);

                return $byDate !== 0 ? $byDate : $right['id'] <=> $left['id'];
            })
            ->values();

        return [
            'count' => $equipos['count'] + $chips['count'],
            'equipos_count' => $equipos['count'],
            'chips_count' => $chips['count'],
            'data' => $items,
        ];
    }

    private function trasladosEquiposPendientes(): array
    {
        if (! Schema::hasTable('traslados_stock')) {
            return ['count' => 0, 'data' => collect()];
        }

        $timestampColumn = $this->timestampColumn('traslados_stock');
        $query = DB::table('traslados_stock as ts')
            ->leftJoin('inventario_tiendas as it', 'it.id', '=', 'ts.producto_id')
            ->where('ts.estado', 'PENDIENTE_APROBACION')
            ->select([
                'ts.id',
                'ts.codigo_lote',
                'ts.tienda_origen',
                'ts.tienda_destino',
                'ts.cantidad',
                'it.producto_nombre',
            ]);

        $timestampColumn
            ? $query->addSelect(DB::raw("ts.{$timestampColumn} AS fecha_creacion"))
            : $query->addSelect(DB::raw('NULL AS fecha_creacion'));

        $rows = $query->get();

        $items = $rows
            ->groupBy(fn ($row) => $row->codigo_lote ?: "id:{$row->id}")
            ->map(function (Collection $group) {
                $first = $group->first();
                $lote = $first->codigo_lote;

                return [
                    'id' => (int) $group->min('id'),
                    'codigo_lote' => $lote,
                    'tienda_origen' => $first->tienda_origen,
                    'tienda_destino' => $first->tienda_destino,
                    'cantidad' => (int) $group->sum('cantidad'),
                    'fecha_creacion' => $group->max('fecha_creacion'),
                    'tipo_lote' => 'equipos',
                    'detalle' => $lote
                        ? "LOTE: {$lote} - {$group->count()} items"
                        : ($first->producto_nombre ?: 'Equipo / Accesorio'),
                ];
            })
            ->values();

        return ['count' => $rows->count(), 'data' => $items];
    }

    private function trasladosChipsPendientes(): array
    {
        if (! Schema::hasTable('traslados_chips')) {
            return ['count' => 0, 'data' => collect()];
        }

        $timestampColumn = $this->timestampColumn('traslados_chips');
        $query = DB::table('traslados_chips as tc')
            ->where('tc.estado', 'PENDIENTE_APROBACION')
            ->select([
                'tc.id',
                'tc.tienda_origen',
                'tc.tienda_destino',
                'tc.cantidad',
            ]);

        $timestampColumn
            ? $query->addSelect(DB::raw("tc.{$timestampColumn} AS fecha_creacion"))
            : $query->addSelect(DB::raw('NULL AS fecha_creacion'));

        $items = $query->get()->map(fn ($item) => [
            'id' => (int) $item->id,
            'codigo_lote' => null,
            'tienda_origen' => $item->tienda_origen,
            'tienda_destino' => $item->tienda_destino,
            'cantidad' => (int) $item->cantidad,
            'fecha_creacion' => $item->fecha_creacion,
            'tipo_lote' => 'chips',
            'detalle' => 'Chips Fisicos',
        ])->values();

        return ['count' => $items->count(), 'data' => $items];
    }

    private function postulantesPendientes(): array
    {
        if (! Schema::hasTable('postulantes_temp')) {
            return $this->emptyIndicator(false);
        }

        return [
            'count' => DB::table('postulantes_temp')
                ->where('estado', 'PENDIENTE')
                ->count(),
        ];
    }

    private function financierasPendientes(): array
    {
        // D1/F1: esquema normalizado. Solo equipos a cuotas quedan en PENDIENTE.
        if (! Schema::hasTable('ventas')) {
            return $this->emptyIndicator(false);
        }

        return [
            'count' => DB::table('ventas')
                ->where('comision_estado', 'PENDIENTE')
                ->count(),
        ];
    }

    private function alertasBcp(): array
    {
        if (! Schema::hasTable('reportes_bcp')) {
            return $this->emptyIndicator();
        }

        $query = DB::table('reportes_bcp as rb')
            ->whereDate('rb.fecha', now()->toDateString())
            ->where('rb.alerta_vista', 0)
            ->groupBy('rb.sucursal_id')
            ->havingRaw('SUM(rb.cantidad_operaciones) < ?', [200])
            ->selectRaw('
                rb.sucursal_id,
                SUM(rb.cantidad_operaciones) AS total_operaciones
            ')
            ->orderBy('total_operaciones');

        if (Schema::hasColumn('tiendas', 'id')) {
            $query
                ->leftJoin('tiendas as ti', 'ti.id', '=', 'rb.sucursal_id')
                ->addSelect(DB::raw('MAX(ti.nombre) AS nombre_tienda'));
        } else {
            $query->addSelect(DB::raw('NULL AS nombre_tienda'));
        }

        $items = $query->get()->map(fn ($item) => [
            'sucursal_id' => (int) $item->sucursal_id,
            'nombre_tienda' => $item->nombre_tienda,
            'total_operaciones' => (int) $item->total_operaciones,
        ])->values();

        return [
            'count' => $items->count(),
            'data' => $items,
        ];
    }

    private function alertasBipay(): array
    {
        if (! Schema::hasTable('bipay_saldos_dia') || ! Schema::hasTable('cuentas_bipay')) {
            return $this->emptyIndicator();
        }

        $items = DB::table('bipay_saldos_dia as bsd')
            ->join('cuentas_bipay as cb', 'cb.id', '=', 'bsd.cuenta_bipay_id')
            ->whereDate('bsd.fecha', now()->toDateString())
            ->where('bsd.alerta_enviada', 1)
            ->whereNull('bsd.saldo_cierre')
            ->where('cb.umbral_alerta', '>', 0)
            ->whereColumn('bsd.saldo_actual', '<=', 'cb.umbral_alerta')
            ->select([
                'bsd.id',
                'bsd.tienda_codigo',
                'bsd.cuenta_bipay_id',
                'bsd.saldo_actual',
                'cb.alias as cuenta_alias',
                'cb.umbral_alerta',
            ])
            ->orderBy('bsd.saldo_actual')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'tienda_codigo' => $item->tienda_codigo,
                'cuenta_bipay_id' => (int) $item->cuenta_bipay_id,
                'cuenta_alias' => $item->cuenta_alias,
                'saldo_actual' => (float) $item->saldo_actual,
                'umbral_alerta' => (float) $item->umbral_alerta,
            ])
            ->values();

        return [
            'count' => $items->count(),
            'data' => $items,
        ];
    }

    private function notificacionesSistema(): array
    {
        if (! Schema::hasTable('sys_notificaciones')) {
            return $this->emptyIndicator();
        }

        $items = DB::table('sys_notificaciones')
            ->where('estado', 'pendiente')
            ->select('id', 'tipo', 'mensaje', 'fecha_creacion')
            ->orderByDesc('fecha_creacion')
            ->limit(20)
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'tipo' => $item->tipo,
                'mensaje' => $item->mensaje,
                'fecha_creacion' => $item->fecha_creacion,
            ])
            ->values();

        return [
            'count' => $items->count(),
            'data' => $items,
        ];
    }

    private function timestampColumn(string $table): ?string
    {
        if (Schema::hasColumn($table, 'created_at')) {
            return 'created_at';
        }

        return Schema::hasColumn($table, 'fecha_creacion') ? 'fecha_creacion' : null;
    }

    private function emptyIndicator(bool $withData = true): array
    {
        return $withData ? ['count' => 0, 'data' => []] : ['count' => 0];
    }
}
