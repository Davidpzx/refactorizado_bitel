<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Puerto 1:1 de includes/cuadre_tesoreria_helper.php del legacy sis_bipay:
 * clasificarDescBitel, calcularCuadreTurno, calcularCuadreCategorial,
 * calcularCuadreRango, detectarAnomaliaApoyo y el cuadre global por recargas.
 */
class CuadreBitelService
{
    private const ETIQUETAS = [
        'postpago'       => 'Postpago',
        'paquetes'       => 'Paquetes',
        'pagos_recargas' => 'Pagos y recargas',
        'tickets_tusamy' => 'Ticket Tusamy',
        'pago_servicio'  => 'Pago de servicios',
        'recarga_bipay'  => 'Recarga BiPay',
    ];

    /** Clasifica la descripción scrapeada de Bitel a una categoría de tesorería. */
    public static function clasificarDescBitel(string $desc): ?string
    {
        $d = strtoupper(trim($desc));
        return match (true) {
            str_starts_with($d, 'ACTIVATION_POST'),
            str_starts_with($d, 'PORTABILITY_POST'),
            str_starts_with($d, 'MIGRATION_PRE_2_POST'),
            str_starts_with($d, 'MIGRATION_POST_2_POST'),
            str_starts_with($d, 'POSTPAID')              => 'postpago',
            str_starts_with($d, 'VAS_ADDON')             => 'paquetes',
            str_starts_with($d, 'RECHARGE'),
            str_starts_with($d, 'PREPAID'),
            str_starts_with($d, 'PAYMENT'),
            str_starts_with($d, 'RECARGA DE L')          => 'pagos_recargas',
            str_starts_with($d, 'VENTA_TICKET_TUSAM')    => 'tickets_tusamy',
            str_starts_with($d, 'PAGO DE RECIBO BITEL'),
            str_starts_with($d, 'ELECTRO '),
            str_starts_with($d, 'SERVICIO DE RECAUDACI') => 'pago_servicio',
            // TRANSFERENCIA = la tienda recargó su saldo BiPay desde Bitel
            str_starts_with($d, 'TRANSFERENCIA')         => 'recarga_bipay',
            default                                      => null,
        };
    }

    /**
     * Extrae el código del personal desde la descripción scrapeada.
     * Ej: "...el codigo del personal= DA_LIZBETHPP_PUN" → DA_LIZBETHPP_PUN
     */
    public static function extraerCodigoPersonal(?string $desc): ?string
    {
        if ($desc && preg_match('/c[oó]digo del personal\s*=\s*([A-Za-z0-9_]+)/u', $desc, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    /** Cuadre por turno: declarado atribuible (efecto digital NEG) vs scraping. */
    public function calcularCuadreTurno(string $tienda, string $fecha): array
    {
        $declarado = DB::select("
            SELECT rc.tipo AS codigo, c.etiqueta, COALESCE(SUM(rc.monto),0) AS monto
            FROM reporte_categorias rc
            JOIN reportes r ON r.id = rc.reporte_id
            JOIN tesoreria_clasificacion c ON c.codigo_operacion = rc.tipo COLLATE utf8mb4_unicode_ci
            WHERE r.tienda_id = ? AND r.fecha = ?
              AND c.activo = 1 AND c.incluir_en_cuadre = 1 AND c.efecto_digital = 'NEG'
              AND rc.tipo <> 'recarga_bipay'
            GROUP BY rc.tipo, c.etiqueta ORDER BY monto DESC
        ", [$tienda, $fecha]);

        $declaradoTotal = array_sum(array_map(fn ($d) => (float) $d->monto, $declarado));

        $recargaDeclarada = round((float) DB::table('reportes')
            ->where('tienda_id', $tienda)->where('fecha', $fecha)
            ->sum('recarga_bipay'), 2);

        $fechaSiguiente = Carbon::parse($fecha)->addDay()->toDateString();
        $movimientos = DB::select("
            SELECT COALESCE(NULLIF(SUBSTRING_INDEX(TRIM(descripcion), ' ', 1), ''), tipo_operacion, 'OTRO') AS op_tipo,
                   COUNT(*) AS cantidad, COALESCE(SUM(monto), 0) AS total
            FROM bitel_operaciones_detalle
            WHERE tienda_codigo = ? AND fecha_hora >= ? AND fecha_hora < ?
              AND UPPER(TRIM(descripcion)) NOT LIKE 'TRANSFERENCIA%'
            GROUP BY op_tipo ORDER BY total
        ", [$tienda, $fecha, $fechaSiguiente]);

        $scrapeadoTotal = round(array_sum(array_map(fn ($m) => abs((float) $m->total), $movimientos)), 2);
        $diferencia     = round($scrapeadoTotal - $declaradoTotal, 2);
        $ok             = abs($diferencia) < 0.01;

        return [
            'declarado_total'   => round($declaradoTotal, 2),
            'scrapeado_total'   => $scrapeadoTotal,
            'recarga_declarada' => $recargaDeclarada,
            'diferencia'        => $diferencia,
            'ok'                => $ok,
            'estado'            => $ok ? 'CUADRADO' : 'DESCUADRADO',
            'declarado'         => $declarado,
            'movimientos'       => $movimientos,
        ];
    }

    /** Cuadre por categoría ERP vs Bitel para una o varias tiendas (grupo de apoyo) en una fecha. */
    public function calcularCuadreCategorial(array $tiendas, string $fecha): array
    {
        if (empty($tiendas)) {
            return $this->cuadreVacio() + ['tiendas' => [], 'es_grupo' => false, 'bitel_raw' => []];
        }

        $erpByCat = $this->erpPorCategoria($tiendas, $fecha, $fecha);

        $bitelRows = DB::table('bitel_operaciones_detalle')
            ->whereIn('tienda_codigo', $tiendas)
            ->where('fecha_hora', '>=', $fecha)
            ->where('fecha_hora', '<', Carbon::parse($fecha)->addDay()->toDateString())
            ->groupBy('descripcion')
            ->selectRaw('descripcion, MAX(codigo_personal) AS codigo_personal, COUNT(*) AS cantidad, COALESCE(SUM(monto),0) AS total')
            ->get();

        [$categorias, $totales] = $this->armarCategorias($erpByCat, $bitelRows);

        return $categorias + $totales + [
            'tiendas'  => $tiendas,
            'es_grupo' => count($tiendas) > 1,
            'bitel_raw' => $bitelRows,
        ];
    }

    /** Cuadre por rango de fechas, incluyendo tiendas origen de apoyos hacia las tiendas dadas. */
    public function calcularCuadreRango(array $tiendas, string $fechaIni, string $fechaFin): array
    {
        if (empty($tiendas)) {
            return $this->cuadreVacio() + ['recarga_bipay_bitel' => 0];
        }

        $origenes = DB::table('bitel_apoyos')
            ->whereIn('tienda_destino', $tiendas)
            ->whereBetween('fecha', [$fechaIni, $fechaFin])
            ->distinct()->pluck('tienda_origen')->all();
        $tiendasBitel = array_values(array_unique(array_merge($tiendas, $origenes)));

        $erpByCat = $this->erpPorCategoria($tiendas, $fechaIni, $fechaFin);

        $bitelRows = DB::table('bitel_operaciones_detalle')
            ->whereIn('tienda_codigo', $tiendasBitel)
            ->whereRaw('DATE(fecha_hora) BETWEEN ? AND ?', [$fechaIni, $fechaFin])
            ->groupBy('descripcion')
            ->selectRaw('descripcion, MAX(codigo_personal) AS codigo_personal, COUNT(*) AS cantidad, COALESCE(SUM(monto),0) AS total')
            ->get();

        [$categorias, $totales, $bitelByCat] = $this->armarCategorias($erpByCat, $bitelRows, true);

        return $categorias + $totales + [
            'recarga_bipay_bitel' => round($bitelByCat['recarga_bipay']['monto'] ?? 0.0, 2),
        ];
    }

    /**
     * Detecta tiendas con mucha venta Bitel pero casi nada declarado en ERP:
     * probable apoyo (otra tienda vendió con su cuenta) sin confirmar.
     */
    public function detectarAnomaliaApoyo(string $fecha): array
    {
        $bitelPorTienda = DB::table('bitel_operaciones_detalle')
            ->where('fecha_hora', '>=', $fecha)
            ->where('fecha_hora', '<', Carbon::parse($fecha)->addDay()->toDateString())
            ->whereRaw("UPPER(descripcion) NOT LIKE 'TRANSFERENCIA%'")
            ->groupBy('tienda_codigo')
            ->selectRaw('tienda_codigo, ABS(COALESCE(SUM(monto),0)) AS total_bitel')
            ->pluck('total_bitel', 'tienda_codigo');

        $erpPorTienda = collect(DB::select("
            SELECT r.tienda_id,
                   COALESCE(SUM(rc.monto),0) + COALESCE(SUM(r.tickets_tusamy),0) + COALESCE(SUM(r.pago_servicio),0) AS total_erp
            FROM reportes r
            LEFT JOIN reporte_categorias rc ON rc.reporte_id = r.id
                AND rc.tipo IN ('postpago','paquetes','pagos_recargas')
            WHERE r.fecha = ? GROUP BY r.tienda_id
        ", [$fecha]))->pluck('total_erp', 'tienda_id');

        $yaConfirmados = DB::table('bitel_apoyos')->where('fecha', $fecha)->distinct()->pluck('tienda_origen')->all();

        $anomalias = [];
        foreach ($bitelPorTienda as $tienda => $totalBitel) {
            if (in_array($tienda, $yaConfirmados, true)) {
                continue;
            }
            $totalErp = (float) ($erpPorTienda[$tienda] ?? 0);
            if ((float) $totalBitel >= 500.0 && $totalErp < ((float) $totalBitel * 0.20)) {
                $anomalias[] = [
                    'tienda'      => $tienda,
                    'total_bitel' => round((float) $totalBitel, 2),
                    'total_erp'   => round($totalErp, 2),
                ];
            }
        }
        return $anomalias;
    }

    /**
     * Cuadre GLOBAL de recargas BiPay: declarado (reportes.recarga_bipay) vs
     * movimientos scrapeados SIN código de personal, por tienda. Aquí se
     * reconcilian las recargas cruzadas entre tiendas que comparten cuenta.
     */
    public function cuadreGlobal(string $fecha): array
    {
        $decl = DB::table('reportes')->where('fecha', $fecha)
            ->groupBy('tienda_id')
            ->selectRaw('tienda_id AS tienda, COALESCE(SUM(recarga_bipay),0) AS recarga')
            ->pluck('recarga', 'tienda');

        $scr = DB::table('bitel_operaciones_detalle')
            ->where('fecha_hora', '>=', $fecha)
            ->where('fecha_hora', '<', Carbon::parse($fecha)->addDay()->toDateString())
            ->whereNull('codigo_personal')
            ->groupBy('tienda_codigo')
            ->selectRaw('tienda_codigo AS tienda, COALESCE(SUM(ABS(monto)),0) AS total, COUNT(*) AS cant')
            ->get()->keyBy('tienda');

        $declTotal = round((float) $decl->sum(), 2);
        $scrTotal  = round((float) $scr->sum('total'), 2);
        $dif       = round($scrTotal - $declTotal, 2);

        $tiendas = collect($decl->keys())->merge($scr->keys())->unique()->sort()->values();
        $filas = $tiendas->map(function ($t) use ($decl, $scr) {
            $d = (float) ($decl[$t] ?? 0);
            $s = (float) ($scr[$t]->total ?? 0);
            return [
                'tienda'     => $t,
                'declarado'  => round($d, 2),
                'scrapeado'  => round($s, 2),
                'cantidad'   => (int) ($scr[$t]->cant ?? 0),
                'diferencia' => round($s - $d, 2),
            ];
        })->values();

        return [
            'fecha'           => $fecha,
            'declarado_total' => $declTotal,
            'scrapeado_total' => $scrTotal,
            'diferencia'      => $dif,
            'ok'              => abs($dif) < 0.01,
            'estado'          => abs($dif) < 0.01 ? 'CUADRADO' : 'DESCUADRADO',
            'tiendas'         => $filas,
        ];
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function erpPorCategoria(array $tiendas, string $fechaIni, string $fechaFin): array
    {
        $erpByCat = collect(DB::table('reporte_categorias as rc')
            ->join('reportes as r', 'r.id', '=', 'rc.reporte_id')
            ->whereIn('r.tienda_id', $tiendas)
            ->whereBetween('r.fecha', [$fechaIni, $fechaFin])
            ->whereIn('rc.tipo', ['postpago', 'paquetes', 'pagos_recargas'])
            ->groupBy('rc.tipo')
            ->selectRaw('rc.tipo AS codigo, COALESCE(SUM(rc.monto),0) AS monto')
            ->get())->pluck('monto', 'codigo')->map(fn ($m) => (float) $m)->all();

        $agg = DB::table('reportes')
            ->whereIn('tienda_id', $tiendas)
            ->whereBetween('fecha', [$fechaIni, $fechaFin])
            ->selectRaw('COALESCE(SUM(tickets_tusamy),0) AS tickets_tusamy,
                         COALESCE(SUM(pago_servicio),0)  AS pago_servicio,
                         COALESCE(SUM(recarga_bipay),0)  AS recarga_bipay')
            ->first();

        $erpByCat['tickets_tusamy'] = (float) ($agg->tickets_tusamy ?? 0);
        $erpByCat['pago_servicio']  = (float) ($agg->pago_servicio ?? 0);
        $erpByCat['recarga_bipay']  = round((float) ($agg->recarga_bipay ?? 0), 2);

        return $erpByCat;
    }

    private function armarCategorias(array $erpByCat, $bitelRows, bool $conByCat = false): array
    {
        $bitelByCat = [];
        foreach ($bitelRows as $row) {
            $cat = self::clasificarDescBitel((string) $row->descripcion);
            if ($cat !== null) {
                $bitelByCat[$cat] ??= ['monto' => 0.0, 'ops' => 0];
                $bitelByCat[$cat]['monto'] += abs((float) $row->total);
                $bitelByCat[$cat]['ops']   += (int) $row->cantidad;
            }
        }

        $categorias = [];
        $totalErp = 0.0;
        $totalBitel = 0.0;
        foreach (self::ETIQUETAS as $codigo => $etiqueta) {
            $erp   = $erpByCat[$codigo] ?? 0.0;
            $bitel = $bitelByCat[$codigo]['monto'] ?? 0.0;
            $categorias[$codigo] = [
                'etiqueta' => $etiqueta,
                'erp'      => round($erp, 2),
                'bitel'    => round($bitel, 2),
                'diff'     => round($bitel - $erp, 2),
                'ops'      => $bitelByCat[$codigo]['ops'] ?? 0,
            ];
            $totalErp   += $erp;
            $totalBitel += $bitel;
        }

        $totalErp   = round($totalErp, 2);
        $totalBitel = round($totalBitel, 2);
        $totalDiff  = round($totalBitel - $totalErp, 2);

        $result = [
            ['categorias' => $categorias],
            [
                'total_erp'         => $totalErp,
                'total_bitel'       => $totalBitel,
                'total_diff'        => $totalDiff,
                'ok'                => abs($totalDiff) < 0.01,
                'estado'            => abs($totalDiff) < 0.01 ? 'CUADRADO' : 'DESCUADRADO',
                'recarga_bipay_erp' => $erpByCat['recarga_bipay'] ?? 0.0,
            ],
        ];
        if ($conByCat) {
            $result[] = $bitelByCat;
        }
        return $result;
    }

    private function cuadreVacio(): array
    {
        return [
            'categorias' => [], 'total_erp' => 0, 'total_bitel' => 0, 'total_diff' => 0,
            'ok' => true, 'estado' => 'CUADRADO', 'recarga_bipay_erp' => 0,
        ];
    }
}
