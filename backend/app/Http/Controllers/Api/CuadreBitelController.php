<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CuadreBitelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cuadre Bitel ERP: conciliación de lo declarado en los cuadres del ERP contra
 * el detalle scrapeado del portal Bitel. Puerto de las secciones nuevas de
 * gerencia/panel_bipay.php, gerencia/cuadre_global.php y
 * gerencia/ajax_movimientos_dia.php del legacy sis_bipay.
 */
class CuadreBitelController extends Controller
{
    public function __construct(private readonly CuadreBitelService $cuadre)
    {
    }

    /**
     * GET /v1/cuadre-bitel/panel?fecha=YYYY-MM-DD
     * Panel de control diario: cuadre por tienda (agrupando apoyos confirmados),
     * anomalías de apoyo detectadas y estado de la cola de histórico.
     */
    public function panel(Request $request): JsonResponse
    {
        $fecha = $this->fecha($request);

        // Tiendas con actividad Bitel ese día (si no hay, todas)
        $codigosBitel = collect(DB::select("
            SELECT DISTINCT tienda_codigo FROM bitel_operaciones_detalle
            WHERE DATE(fecha_hora) = ? AND UPPER(descripcion) NOT LIKE 'TRANSFERENCIA%'
            ORDER BY tienda_codigo
        ", [$fecha]))->pluck('tienda_codigo');

        if ($codigosBitel->isNotEmpty()) {
            $nombres = DB::table('tiendas')->whereIn('codigo', $codigosBitel)->pluck('nombre', 'codigo');
            $tiendasActivas = $codigosBitel->map(fn ($c) => ['codigo' => $c, 'nombre' => $nombres[$c] ?? $c])->values();
        } else {
            $tiendasActivas = DB::table('tiendas')->orderBy('nombre')->get(['codigo', 'nombre'])
                ->map(fn ($t) => ['codigo' => $t->codigo, 'nombre' => $t->nombre])->values();
        }
        $codigosTiendas = $tiendasActivas->pluck('codigo')->all();

        $anomalias = $this->cuadre->detectarAnomaliaApoyo($fecha);

        // Apoyos confirmados del día → agrupar origen+destino en un solo cuadre
        $apoyosDia = DB::table('bitel_apoyos')->where('fecha', $fecha)->get(['tienda_origen', 'tienda_destino']);
        $apoyosOrigen     = $apoyosDia->pluck('tienda_origen')->all();
        $apoyosDestinoMap = $apoyosDia->pluck('tienda_destino', 'tienda_origen')->all();

        $cuadresTienda  = [];
        $tiendasEnGrupo = [];
        foreach ($tiendasActivas as $t) {
            $cod = $t['codigo'];
            if (in_array($cod, $tiendasEnGrupo, true)) {
                continue;
            }
            if (in_array($cod, $apoyosOrigen, true)) {
                $destino = $apoyosDestinoMap[$cod];
                $cuadresTienda[$destino] = $this->cuadre->calcularCuadreCategorial([$cod, $destino], $fecha)
                    + ['es_apoyo_destino' => true, 'apoyo_origen' => $cod];
                array_push($tiendasEnGrupo, $cod, $destino);
            } elseif (! in_array($cod, array_values($apoyosDestinoMap), true)) {
                $cuadresTienda[$cod] = $this->cuadre->calcularCuadreCategorial([$cod], $fecha);
            }
        }

        $reportesEnviados = empty($codigosTiendas) ? [] : DB::table('reportes')
            ->whereIn('tienda_id', $codigosTiendas)->where('fecha', $fecha)
            ->groupBy('tienda_id')->selectRaw('tienda_id, COUNT(*) AS tiene')
            ->pluck('tiene', 'tienda_id');

        return response()->json([
            'fecha'             => $fecha,
            'tiendas'           => $tiendasActivas,
            'cuadres'           => $cuadresTienda,
            'anomalias'         => $anomalias,
            'apoyos'            => $apoyosDia,
            'reportes_enviados' => $reportesEnviados,
            'queue'             => DB::table('bitel_historico_queue')->where('fecha', $fecha)
                ->first(['estado', 'movs_procesados', 'fecha_solicitud', 'fecha_procesado', 'mensaje']),
        ]);
    }

    /** GET /v1/cuadre-bitel/rango?fecha_ini=&fecha_fin=&tiendas[]= — cuadre por rango (historial). */
    public function rango(Request $request): JsonResponse
    {
        $fechaIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('fecha_ini'))
            ? $request->query('fecha_ini') : now()->startOfMonth()->toDateString();
        $fechaFin = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('fecha_fin'))
            ? $request->query('fecha_fin') : now()->toDateString();

        $tiendas = array_filter((array) $request->query('tiendas', []));
        if (empty($tiendas)) {
            $tiendas = DB::table('tiendas')->where('codigo', '<>', 'CENTRAL')->pluck('codigo')->all();
        }

        return response()->json(
            ['fecha_ini' => $fechaIni, 'fecha_fin' => $fechaFin]
            + $this->cuadre->calcularCuadreRango($tiendas, $fechaIni, $fechaFin)
        );
    }

    /** GET /v1/cuadre-bitel/global?fecha= — cuadre global de recargas BiPay (cuentas compartidas). */
    public function global(Request $request): JsonResponse
    {
        return response()->json($this->cuadre->cuadreGlobal($this->fecha($request)));
    }

    /** GET /v1/cuadre-bitel/turno?tienda=&fecha= — cuadre de turno de una tienda. */
    public function turno(Request $request): JsonResponse
    {
        $tienda = strtoupper(trim((string) $request->query('tienda', '')));
        if ($tienda === '') {
            return response()->json(['error' => 'tienda requerida'], 422);
        }
        return response()->json($this->cuadre->calcularCuadreTurno($tienda, $this->fecha($request)));
    }

    /**
     * GET /v1/cuadre-bitel/movimientos-dia?fecha=&agentes=A,B
     * Movimientos scrapeados del día agrupados por tienda, con subtotales por
     * agente (codigo_personal) y clasificación por categoría.
     */
    public function movimientosDia(Request $request): JsonResponse
    {
        $fecha   = $this->fecha($request);
        $incluir = array_filter(array_map('trim', explode(',', strtoupper((string) $request->query('agentes', '')))));

        $detalles = DB::table('bitel_operaciones_detalle')
            ->whereRaw('DATE(fecha_hora) = ?', [$fecha])
            ->orderBy('tienda_codigo')->orderByDesc('fecha_hora')
            ->get(['tienda_codigo', 'fecha_hora', 'tipo_operacion', 'descripcion', 'monto', 'codigo_personal']);

        $agente = function ($row): string {
            if (! empty($row->codigo_personal)) {
                return strtoupper($row->codigo_personal);
            }
            return CuadreBitelService::extraerCodigoPersonal($row->descripcion) ?? 'SIN AGENTE';
        };

        // Set completo de agentes por tienda (para los chips de filtro del front)
        $agentesPorTienda = [];
        foreach ($detalles as $d) {
            $agentesPorTienda[$d->tienda_codigo][$agente($d)] = true;
        }

        if (! empty($incluir)) {
            $detalles = $detalles->filter(fn ($d) => in_array($agente($d), $incluir, true))->values();
        }

        $grouped = [];
        foreach ($detalles as $d) {
            $tienda = $d->tienda_codigo;
            $grouped[$tienda] ??= [
                'resumen' => ['prepago' => 0, 'postpago' => 0, 'paquetes' => 0, 'recargas' => 0,
                              'recarga_bipay' => 0, 'otros' => 0, 'reembolsos' => 0, 'neto' => 0],
                'agentes' => [], 'registros' => [],
            ];

            $monto = (float) $d->monto;
            $desc  = strtoupper((string) $d->descripcion);
            $tipo  = strtoupper((string) $d->tipo_operacion);
            $ag    = $agente($d);

            $grouped[$tienda]['resumen']['neto'] += $monto;
            $slot = match (true) {
                str_contains($desc, 'TRANSFERENCIA') => 'recarga_bipay',
                str_contains($desc, 'RECHARGE') || str_contains($desc, 'PAYMENT') || str_contains($tipo, 'RECHARGE') => 'recargas',
                str_contains($desc, 'VAS') || str_contains($tipo, 'VAS') => 'paquetes',
                str_contains($desc, 'POSTPAID') || str_contains($desc, 'ACTIVATION_POST')
                    || str_contains($desc, 'PORTABILITY_POST') || str_contains($desc, 'MIGRATION_POST') => 'postpago',
                str_contains($desc, 'REFUND') || str_contains($tipo, 'REFUND') || str_contains($tipo, 'REEMBOLSO') => 'reembolsos',
                str_contains($desc, 'PREPAID') || str_contains($desc, 'ACTIVATION_PRE')
                    || str_contains($desc, 'PORTABILITY_PRE') || str_contains($desc, 'PREPAGO') => 'prepago',
                default => 'otros',
            };
            $grouped[$tienda]['resumen'][$slot] += $monto;

            $grouped[$tienda]['agentes'][$ag] ??= ['cantidad' => 0, 'total' => 0.0];
            $grouped[$tienda]['agentes'][$ag]['cantidad']++;
            $grouped[$tienda]['agentes'][$ag]['total'] += $monto;

            $grouped[$tienda]['registros'][] = [
                'fecha_hora'  => $d->fecha_hora,
                'agente'      => $ag,
                'tipo'        => $d->tipo_operacion,
                'descripcion' => $d->descripcion,
                'monto'       => $monto,
            ];
        }

        return response()->json([
            'fecha'              => $fecha,
            'tiendas'            => $grouped,
            'agentes_por_tienda' => array_map(fn ($set) => array_keys($set), $agentesPorTienda),
        ]);
    }

    /** POST /v1/cuadre-bitel/apoyos {fecha, tienda_origen, tienda_destino} — confirmar apoyo. */
    public function confirmarApoyo(Request $request): JsonResponse
    {
        $fecha   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('fecha'))
            ? $request->input('fecha') : now()->toDateString();
        $origen  = strtoupper(trim((string) $request->input('tienda_origen', '')));
        $destino = strtoupper(trim((string) $request->input('tienda_destino', '')));

        if ($origen === '' || $destino === '' || $origen === $destino) {
            return response()->json(['ok' => false, 'error' => 'Origen y destino inválidos.'], 422);
        }

        DB::table('bitel_apoyos')->insertOrIgnore([
            'fecha'          => $fecha,
            'tienda_origen'  => $origen,
            'tienda_destino' => $destino,
            'confirmado_por' => $request->user()->id,
        ]);

        return response()->json(['ok' => true]);
    }

    /** DELETE /v1/cuadre-bitel/apoyos {fecha, tienda_origen, tienda_destino} — deshacer apoyo. */
    public function eliminarApoyo(Request $request): JsonResponse
    {
        DB::table('bitel_apoyos')
            ->where('fecha', (string) $request->input('fecha'))
            ->where('tienda_origen', strtoupper((string) $request->input('tienda_origen')))
            ->where('tienda_destino', strtoupper((string) $request->input('tienda_destino')))
            ->delete();

        return response()->json(['ok' => true]);
    }

    private function fecha(Request $request): string
    {
        $f = (string) $request->query('fecha', '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) ? $f : now()->toDateString();
    }
}
