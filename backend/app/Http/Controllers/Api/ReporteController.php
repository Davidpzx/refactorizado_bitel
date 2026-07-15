<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\HistorialReporte;
use App\Models\Reporte;
use App\Models\ReporteBorrador;
use App\Models\ReporteSalida;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\VentaEquipo;
use App\Models\VentaLinea;
use App\Services\ComisionService;
use App\Services\ComisionOperativaService;
use App\Services\UserAgentResolver;
use App\Support\Permisos;
use App\Support\PlanillaGuard;
use App\Support\ResourceCache;
use App\Support\TiendaGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteController extends Controller
{
    public function __construct(private readonly UserAgentResolver $userAgentResolver)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $reportes = Reporte::query()
            ->when($request->tienda,      fn($q, $t) => $q->where('tienda_id', $t))
            ->when($request->estado,      fn($q, $e) => $q->where('estado', $e))
            ->when($request->fecha_desde, fn($q, $f) => $q->whereDate('fecha', '>=', $f))
            ->when($request->fecha_hasta, fn($q, $f) => $q->whereDate('fecha', '<=', $f))
            ->withCount('ventas')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($reportes);
    }

    public function show(Request $request, Reporte $reporte): JsonResponse
    {
        $this->autorizarPropietarioOAdmin($request, $reporte);
        $reporte->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas']);
        return response()->json($reporte);
    }

    /**
     * Export XLSX de auditoría de UN reporte (paridad con gerencia/exportar_excel.php del legacy):
     * postpago/prepago/equipos/servicios/apoyo/salidas/dinero digital/cuadre financiero/historial de ediciones.
     */
    public function exportarExcel(Request $request, Reporte $reporte): StreamedResponse
    {
        $this->autorizarPropietarioOAdmin($request, $reporte);

        $reporte->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas']);

        $historialEdiciones = $reporte->historialReportes()
            ->whereIn('accion', ['edicion_reporte', 'edicion_critica', 'edicion_restaurada'])
            ->with('usuario')
            ->orderBy('created_at')
            ->get();

        $mapaAgentes = DB::table('agentes')->pluck('nombres', 'id');
        $nomAg = static function ($id) use ($mapaAgentes) {
            $nombre = $mapaAgentes[$id] ?? null;
            if (! $nombre) {
                return 'N/A';
            }
            $partes = array_values(array_filter(explode(' ', trim($nombre))));
            return ucwords(strtolower(implode(' ', array_slice($partes, 0, 2))));
        };

        $postpago = [];
        $prepago  = [];
        $equipos  = [];
        $apoyo    = [];
        $servicios = [];

        $nombresServicio = [
            'recarga_bipay'  => 'Recarga Bipay',
            'pago_servicio'  => 'Pago de Servicio',
            'pago_krece'     => 'Pago Krece',
            'pago_payjoy'    => 'Pago Payjoy',
            'tickets_tusamy' => 'Tickets Tusamy',
        ];

        foreach ($reporte->ventas as $venta) {
            if ($venta->comision_estado === 'ANULADA') {
                continue;
            }

            $dni = $venta->cliente->dni_ruc ?? 'N/A';
            $esApoyo = (bool) $venta->cross_selling || $venta->tipo_venta === 'APOYO';

            if (in_array($venta->tipo_venta, ['POSTPAGO', 'PREPAGO', 'APOYO'], true)) {
                $l = $venta->linea;
                $tipoAlta = ($l?->tipo_alta ?? '') === 'MNP' ? 'PORT.' : ($l?->tipo_alta ?? '');
                $flagMigracion = $venta->es_remate
                    ? 'Remate'
                    : ($l?->es_migracion ? 'Migración' : ($l?->es_upgrade ? 'Upgrade' : '—'));
                if ($venta->es_extranjero) {
                    $flagMigracion .= ' Extranjero';
                }

                $fila = [
                    'plan'      => $l?->plan_nombre_snap ?? 'Plan',
                    'tipo'      => $tipoAlta,
                    'cantidad'  => $l?->cantidad ?? 1,
                    'cobrado'   => (float) ($l ? $l->cobrado_unitario * max(1, $l->cantidad) : $venta->monto_total),
                    'vendedor'  => $nomAg($venta->vendedor_id),
                    'dni'       => $dni,
                    'destino'   => $venta->tienda_destino ?? '—',
                    'migracion' => $flagMigracion,
                ];

                if ($esApoyo) {
                    $apoyo[] = $fila;
                } elseif ($venta->tipo_venta === 'POSTPAGO') {
                    $postpago[] = $fila;
                } else {
                    $prepago[] = $fila;
                }
            } elseif (in_array($venta->tipo_venta, ['EQUIPO', 'ACCESORIO'], true)) {
                $e = $venta->equipo;
                $equipos[] = [
                    'producto'   => $e?->producto_nombre_snap ?? '—',
                    'imei'       => $e?->imei_serial_snap ?: '—',
                    'precio'     => (float) $venta->monto_total,
                    'tipo_pago'  => $e?->tipo_pago ?? 'CONTADO',
                    'financiera' => $e?->financiera ?: '—',
                    'vendedor'   => $nomAg($venta->vendedor_id),
                    'dni'        => $dni,
                ];
            } elseif ($venta->tipo_venta === 'OTROS_FLUJO') {
                $servicios[] = ['label' => 'Otros Ingresos: ' . ($venta->subtipo ?: '—'), 'monto' => (float) $venta->monto_total];
            }
        }

        foreach ($nombresServicio as $campo => $label) {
            $monto = (float) ($reporte->{$campo} ?? 0);
            if ($monto != 0) {
                $servicios[] = ['label' => $label, 'monto' => $monto];
            }
        }

        // ── Cálculos de cuadre (paridad exacta con exportar_excel.php) ──────────
        $totalSistema = (float) $reporte->total_calculado;
        $totalDigital = (float) $reporte->yape + (float) $reporte->bipay
            + (float) $reporte->transferencia + (float) $reporte->retiro_bipay;
        $gastos       = (float) $reporte->total_salidas;
        $efectivoNeto = $totalSistema - $totalDigital - $gastos;
        $base         = (float) $reporte->caja_inicial;
        $totalCajon   = $efectivoNeto + $base;
        $entregado    = (float) $reporte->efectivo_entregado;
        $diferencia   = (float) $reporte->diferencia;

        $destinoLabels = [
            'BANCO'     => 'Depositado en Banco',
            'GERENCIA'  => 'Entregado a Supervisor/Gerencia',
            'EN_CAJA'   => 'Guardado en Caja Fuerte Central',
            'AGENTE'    => 'Usado para Pagos/Gastos (Agente)',
            'TIENDA'    => 'Sigue en Tienda (No Entregado)',
            'ENTREGADO' => 'Entregado / Depositado',
        ];
        $destinoLabel = $destinoLabels[$reporte->destino_efectivo ?? ''] ?? ($reporte->destino_efectivo ?? '—');

        $estadoCuadre = abs($diferencia) < 0.01 ? 'CUADRE EXACTO' : ($diferencia < 0 ? 'FALTANTE' : 'SOBRANTE');

        $numReporte = str_pad((string) $reporte->id, 5, '0', STR_PAD_LEFT);
        $empresa = Schema::hasTable('configuracion_empresa')
            ? (DB::table('configuracion_empresa')->value('razon_social') ?: 'BITEL')
            : 'BITEL';

        // ── Construcción del XLSX ────────────────────────────────────────────────
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reporte ' . $numReporte);

        $fila = 1;
        $sheet->setCellValue("A{$fila}", "REPORTE DETALLADO DE VENTAS — {$empresa}");
        $sheet->mergeCells("A{$fila}:H{$fila}");
        $sheet->getStyle("A{$fila}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
        ]);
        $fila++;
        $sheet->setCellValue("A{$fila}", "Reporte #{$numReporte}  |  Fecha: " . ($reporte->fecha instanceof \DateTimeInterface ? $reporte->fecha->format('d/m/Y') : (string) $reporte->fecha) . "  |  Tienda: {$reporte->tienda_id}");
        $sheet->mergeCells("A{$fila}:H{$fila}");
        $fila += 2;

        $seccion = function (string $titulo, string $color) use ($sheet, &$fila) {
            $sheet->setCellValue("A{$fila}", $titulo);
            $sheet->mergeCells("A{$fila}:H{$fila}");
            $sheet->getStyle("A{$fila}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
            ]);
            $fila++;
        };

        $cabecera = function (array $cols) use ($sheet, &$fila) {
            $sheet->fromArray($cols, null, "A{$fila}");
            $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cols));
            $sheet->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']],
            ]);
            $fila++;
        };

        $totalRow = function (string $label, float $monto, int $colMonto = 4) use ($sheet, &$fila) {
            $colLetra = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colMonto);
            $sheet->setCellValue("A{$fila}", $label);
            $sheet->setCellValue("{$colLetra}{$fila}", round($monto, 2));
            $sheet->getStyle("A{$fila}:{$colLetra}{$fila}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D4EDDA']],
            ]);
            $fila++;
        };

        // 1. Postpago
        $seccion('1. VENTAS POSTPAGO (LÍNEAS)', '0D6EFD');
        $cabecera(['Plan', 'Tipo Alta', 'Cant.', 'Cobrado', 'Vendedor', 'DNI/Cel. Cliente', 'Migración/Upgrade']);
        if (empty($postpago)) {
            $sheet->setCellValue("A{$fila}", 'Sin ventas postpago registradas.');
            $fila++;
        } else {
            $tot = 0;
            foreach ($postpago as $d) {
                $tot += $d['cobrado'];
                $sheet->fromArray([$d['plan'], $d['tipo'], $d['cantidad'], round($d['cobrado'], 2), $d['vendedor'], $d['dni'], $d['migracion']], null, "A{$fila}");
                $fila++;
            }
            $totalRow('TOTAL POSTPAGO (' . count($postpago) . ')', $tot);
        }
        $fila++;

        // 2. Prepago
        $seccion('2. VENTAS PREPAGO (CHIPS)', '0DCAF0');
        $cabecera(['Plan/Chip', 'Tipo Alta', 'Cant.', 'Cobrado', 'Vendedor', 'DNI/Cel. Cliente']);
        if (empty($prepago)) {
            $sheet->setCellValue("A{$fila}", 'Sin ventas prepago registradas.');
            $fila++;
        } else {
            $tot = 0;
            foreach ($prepago as $d) {
                $tot += $d['cobrado'];
                $sheet->fromArray([$d['plan'], $d['tipo'], $d['cantidad'], round($d['cobrado'], 2), $d['vendedor'], $d['dni']], null, "A{$fila}");
                $fila++;
            }
            $totalRow('TOTAL PREPAGO (' . count($prepago) . ')', $tot);
        }
        $fila++;

        // 3. Equipos
        $seccion('3. EQUIPOS Y ACCESORIOS', '198754');
        $cabecera(['Producto', 'IMEI/Serial', 'Precio', 'Tipo Pago', 'Financiera', 'Vendedor', 'DNI Cliente']);
        if (empty($equipos)) {
            $sheet->setCellValue("A{$fila}", 'Sin ventas de equipos registradas.');
            $fila++;
        } else {
            $tot = 0;
            foreach ($equipos as $d) {
                $tot += $d['precio'];
                $sheet->fromArray([$d['producto'], $d['imei'], round($d['precio'], 2), $d['tipo_pago'], $d['financiera'], $d['vendedor'], $d['dni']], null, "A{$fila}");
                $fila++;
            }
            $totalRow('TOTAL EQUIPOS/ACCESORIOS (' . count($equipos) . ')', $tot, 3);
        }
        $fila++;

        // 4. Servicios
        $seccion('4. RECARGAS, PAGOS Y OTROS SERVICIOS', 'FD7E14');
        $cabecera(['Concepto', 'Monto']);
        if (empty($servicios)) {
            $sheet->setCellValue("A{$fila}", 'Sin pagos o recargas registrados.');
            $fila++;
        } else {
            $tot = 0;
            foreach ($servicios as $s) {
                $tot += $s['monto'];
                $sheet->fromArray([$s['label'], round($s['monto'], 2)], null, "A{$fila}");
                $fila++;
            }
            $totalRow('TOTAL SERVICIOS (' . count($servicios) . ')', $tot, 2);
        }
        $fila++;

        // 5. Apoyo
        $seccion('5. VENTAS PARA OTRAS TIENDAS (APOYO)', '6610F2');
        $cabecera(['Plan', 'Tipo', 'Cant.', 'Cobrado', 'Tienda Destino', 'Vendedor', 'DNI/Cel. Cliente']);
        if (empty($apoyo)) {
            $sheet->setCellValue("A{$fila}", 'Sin apoyos inter-tienda registrados.');
            $fila++;
        } else {
            $tot = 0;
            foreach ($apoyo as $d) {
                $tot += $d['cobrado'];
                $sheet->fromArray([$d['plan'], $d['tipo'], $d['cantidad'], round($d['cobrado'], 2), $d['destino'], $d['vendedor'], $d['dni']], null, "A{$fila}");
                $fila++;
            }
            $totalRow('TOTAL APOYO (' . count($apoyo) . ')', $tot);
        }
        $fila++;

        // 6. Salidas
        $seccion('6. SALIDAS Y GASTOS', 'DC3545');
        $cabecera(['Tipo', 'Monto', 'Observación']);
        if ($reporte->salidas->isEmpty()) {
            $sheet->setCellValue("A{$fila}", 'Sin salidas registradas.');
            $fila++;
        } else {
            $tot = 0;
            foreach ($reporte->salidas as $s) {
                $tot += (float) $s->monto;
                $sheet->fromArray([strtoupper($s->tipo), round((float) $s->monto, 2), $s->observacion ?? ''], null, "A{$fila}");
                $fila++;
            }
            $totalRow('TOTAL SALIDAS', $tot, 2);
        }
        $fila++;

        // 7. Dinero digital y retiros
        $seccion('7. DINERO DIGITAL Y RETIROS', '0DCAF0');
        $cabecera(['Concepto', 'Monto']);
        $huboDigital = false;
        foreach ([['Yape', $reporte->yape], ['Bipay', $reporte->bipay], ['Transferencia', $reporte->transferencia], ['Retiro Bipay', $reporte->retiro_bipay]] as [$label, $monto]) {
            if ((float) $monto > 0) {
                $huboDigital = true;
                $sheet->fromArray([$label, round((float) $monto, 2)], null, "A{$fila}");
                $fila++;
            }
        }
        if (! $huboDigital) {
            $sheet->setCellValue("A{$fila}", 'Sin ingresos digitales registrados.');
            $fila++;
        }
        $fila++;

        // 8. Cuadre financiero
        $seccion("8. CUADRE FINANCIERO — REPORTE #{$numReporte}", '495057');
        $sheet->fromArray(['(+) TOTAL VENTAS BRUTAS', round($totalSistema, 2)], null, "A{$fila}"); $fila++;
        $sheet->fromArray(['(-) GASTOS DE EFECTIVO', round($gastos, 2)], null, "A{$fila}"); $fila++;
        $sheet->fromArray(['(=) EFECTIVO NETO DE VENTAS', round($efectivoNeto, 2)], null, "A{$fila}"); $fila++;
        $sheet->fromArray(['(+) SENCILLO INICIAL (CAJA BASE)', round($base, 2)], null, "A{$fila}"); $fila++;
        $sheet->fromArray(['(=) TOTAL CONTADO EN CAJÓN', round($totalCajon, 2)], null, "A{$fila}"); $fila++;
        $sheet->fromArray(['DESTINO DECLARADO', $destinoLabel], null, "A{$fila}"); $fila++;
        $sheet->fromArray(['EFECTIVO ENTREGADO', round($entregado, 2)], null, "A{$fila}"); $fila++;
        $sheet->fromArray(['DIFERENCIA FINAL', round($diferencia, 2)], null, "A{$fila}"); $fila++;
        $sheet->fromArray(['ESTADO', $estadoCuadre], null, "A{$fila}"); $fila++;
        $fila++;

        // 9. Historial de ediciones
        if ($historialEdiciones->isNotEmpty()) {
            $seccion('HISTORIAL DE EDICIONES', '495057');
            $cabecera(['Fecha / Editor', 'Tipo', 'Detalle']);
            $tipoLabel = [
                'edicion_critica'    => 'Edición con cambio de comisión',
                'edicion_restaurada' => 'Comisión restaurada',
            ];
            foreach ($historialEdiciones as $h) {
                $sheet->fromArray([
                    $h->created_at->format('d/m/Y H:i') . ' — ' . ($h->usuario->nombre ?? 'N/A'),
                    $tipoLabel[$h->accion] ?? 'Edición de datos',
                    $h->detalle ?? '',
                ], null, "A{$fila}");
                $fila++;
            }
        }

        foreach (range(1, 8) as $col) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        return response()->streamDownload(
            static function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            "Reporte_Detallado_{$numReporte}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function vendedores(Request $request): JsonResponse
    {
        $tiendaId = trim((string) ($request->input('tienda_id') ?: $request->user()->tienda_id));

        $vendedores = Cache::remember(
            ResourceCache::key('reporte-vendedores-v2', sha1($tiendaId)),
            ResourceCache::TTL_SECONDS,
            fn () => DB::table('agentes')
                ->select(['id', 'dni', 'nombres', 'tienda_base'])
                ->where('estado', 'ACTIVO')
                ->where(function ($query) {
                    $query->whereNull('es_gerencia')
                        ->orWhereNotIn('es_gerencia', ['1', 'true', 'TRUE', 'si', 'SI', 'Si']);
                })
                ->orderByRaw('CASE WHEN tienda_base = ? THEN 0 ELSE 1 END', [$tiendaId])
                ->orderBy('nombres')
                ->get()
        );

        return response()->json($vendedores);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agente_id'                      => 'nullable|integer',
            'tienda_id'                      => 'required|string|max:50',
            '_modo_dios'                     => 'nullable|boolean',
            'usuario_id'                     => 'nullable|integer',
            'fecha'                          => 'required|date',
            'caja_inicial'                   => 'nullable|numeric|min:0',
            'yape'                           => 'nullable|numeric|min:0',
            'bipay'                          => 'nullable|numeric|min:0',
            'transferencia'                  => 'nullable|numeric|min:0',
            'recarga_bipay'                  => 'nullable|numeric|min:0',
            'pago_servicio'                  => 'nullable|numeric|min:0',
            'pago_krece'                     => 'nullable|numeric|min:0',
            'pago_payjoy'                    => 'nullable|numeric|min:0',
            'tickets_tusamy'                 => 'nullable|numeric|min:0',
            'retiro_bipay'                   => 'nullable|numeric|min:0',
            'efectivo_entregado'             => 'nullable|numeric|min:0',
            'total_salidas'                  => 'nullable|numeric|min:0',
            'salidas'                        => 'nullable|array',
            'salidas.*.tipo'                 => 'required_with:salidas|in:adelanto,gasto,pasaje,otro',
            'salidas.*.monto'                => 'required_with:salidas|numeric|gt:0',
            'salidas.*.observacion'          => 'nullable|string|max:1000',
            'nombre_cubre'                   => 'nullable|string|max:100',
            'observaciones'                  => 'nullable|string',
            'obs_dia'                        => 'nullable|string',
            'destino_efectivo'               => 'nullable|string|max:50',
            'ventas'                         => 'nullable|array',
            'ventas.*.venta_id'              => 'nullable|integer',
            'ventas.*.vendedor_id'           => 'required|integer|exists:agentes,id',
            'ventas.*.tipo_venta'            => 'required_with:ventas|in:EQUIPO,ACCESORIO,POSTPAGO,PREPAGO,OTROS_FLUJO,APOYO',
            'ventas.*.subtipo'               => 'nullable|string|max:50',
            'ventas.*.monto_total'           => 'required_with:ventas|numeric|min:0',
            'ventas.*.efectivo_inicial'      => 'nullable|numeric|min:0',
            'ventas.*.cross_selling'         => 'nullable|boolean',
            'ventas.*.tienda_destino'        => 'nullable|string|max:10|required_if:ventas.*.tipo_venta,APOYO',
            'ventas.*.es_remate'             => 'nullable|boolean',
            'ventas.*.es_extranjero'         => 'nullable|boolean',
            'ventas.*.es_migracion'          => 'nullable|boolean',
            'ventas.*.es_upgrade'            => 'nullable|boolean',
            'ventas.*.es_esim'               => 'nullable|boolean',
            'ventas.*.plan_anterior'         => 'nullable|numeric|min:0',
            'ventas.*.cliente_dni'           => 'nullable|string|max:11',
            'ventas.*.inventario_tienda_id'  => 'nullable|integer',
            'ventas.*.producto_nombre'       => 'nullable|string|max:150',
            'ventas.*.imei_serial'           => 'nullable|string|max:50',
            'ventas.*.tipo_pago'             => 'nullable|in:CONTADO,CUOTAS',
            'ventas.*.financiera'            => 'nullable|string|max:50',
            'ventas.*.precio_venta'          => 'nullable|numeric|min:0',
            'ventas.*.costo_snap'            => 'nullable|numeric|min:0',
            'ventas.*.por_cobrar_financiera' => 'nullable|numeric|min:0',
            'ventas.*.plan_nombre'           => 'nullable|string|max:150',
            'ventas.*.tipo_alta'             => 'nullable|string|max:30',
            'ventas.*.cantidad'              => 'nullable|integer|min:1',
            'ventas.*.cobrado_unitario'      => 'nullable|numeric|min:0',
            'ventas.*.comision_unitaria'     => 'nullable|numeric|min:0',
        ], [
            'agente_id.integer' => 'El agente seleccionado no es valido.',
            'ventas.*.vendedor_id.required' => 'Debe seleccionar un agente para cada venta.',
        ]);

        $user = $request->user();
        // R3: agente sin agente_id vinculado no puede crear reportes (ni a nombre de otros).
        if ($user->esRol(Usuario::ROL_AGENTE) && ! $user->agente_id) {
            abort(403, 'Tu usuario no está vinculado a un agente. Un administrador debe asignarlo.');
        }
        $esAdmin = $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE);
        $tiendaSolicitada = trim((string) $validated['tienda_id']);

        if (! $esAdmin && ($validated['_modo_dios'] ?? false) && $tiendaSolicitada !== trim((string) $user->tienda_id)) {
            abort(403, 'Solo admin o gerente puede cuadrar por otra tienda.');
        }

        $agenteId = $esAdmin
            ? (int) ($validated['agente_id'] ?? 0)
            : $this->userAgentResolver->resolveOrFail($user)->id;
        $tiendaId = $esAdmin
            ? $tiendaSolicitada
            : trim((string) $user->tienda_id);

        if ($agenteId <= 0) {
            return response()->json(['error' => 'Debe seleccionar un agente.'], 422);
        }
        if (! DB::table('agentes')->where('id', $agenteId)->exists()) {
            return response()->json(['error' => 'El agente seleccionado no existe.'], 422);
        }
        if ($tiendaId === '') {
            return response()->json(['error' => 'El usuario autenticado no tiene una tienda asignada.'], 422);
        }

        // Guard de duplicados eliminado: se permiten múltiples cuadres por día (cerrar caja y abrir nueva).

        DB::beginTransaction();
        try {
            $ventas_data        = $validated['ventas'] ?? [];
            $total_calculado    = collect($ventas_data)->sum('monto_total');
            $comisionService    = new ComisionService();
            $salidasData        = $validated['salidas'] ?? [];
            $total_salidas      = $request->has('salidas')
                ? round((float) collect($salidasData)->sum('monto'), 2)
                : (float) ($validated['total_salidas'] ?? 0);
            $yape               = (float) ($validated['yape'] ?? 0);
            $bipay              = (float) ($validated['bipay'] ?? 0);
            $transferencia      = (float) ($validated['transferencia'] ?? 0);
            $recarga_bipay      = (float) ($validated['recarga_bipay'] ?? 0);
            $efectivo_entregado = (float) $validated['efectivo_entregado'];
            $caja_inicial       = (float) $validated['caja_inicial'];

            // B3 — Fórmula del cuadre 1:1 con el legacy y lib/cuadre.ts:
            //   total_sistema     = Σ ventas + ingresos fijos (recargas/servicios/krece/tickets)
            //   total_no_fisico   = yape + bipay + transferencia + retiro_bipay
            //   efectivo_esperado = total_sistema − total_no_fisico − total_salidas  (NO incluye caja_inicial)
            $retiro_bipay      = (float) ($validated['retiro_bipay'] ?? 0);
            $total_sistema     = (float) collect($ventas_data)->sum('monto_total')
                + (float) ($validated['recarga_bipay'] ?? 0)
                + (float) ($validated['pago_servicio'] ?? 0)
                + (float) ($validated['pago_krece'] ?? 0)
                + (float) ($validated['pago_payjoy'] ?? 0)
                + (float) ($validated['tickets_tusamy'] ?? 0);
            $total_no_fisico   = $yape + $bipay + $transferencia + $retiro_bipay;
            $efectivo_esperado = round($total_sistema - $total_no_fisico - $total_salidas, 2);
            $diferencia        = round($efectivo_entregado - $efectivo_esperado, 2);
            $reporte = Reporte::create([
                'agente_id'           => $agenteId,
                'tienda_id'           => $tiendaId,
                'usuario_id'          => $user->id,
                'fecha'               => $validated['fecha'],
                'total_dia'           => $efectivo_entregado,
                'total_calculado'     => $total_calculado,
                'yape'                => $yape,
                'bipay'               => $bipay,
                'recarga_bipay'       => $recarga_bipay,
                'pago_servicio'       => (float) ($validated['pago_servicio'] ?? 0),
                'pago_krece'          => (float) ($validated['pago_krece'] ?? 0),
                'pago_payjoy'         => (float) ($validated['pago_payjoy'] ?? 0),
                'tickets_tusamy'      => (float) ($validated['tickets_tusamy'] ?? 0),
                'retiro_bipay'        => (float) ($validated['retiro_bipay'] ?? 0),
                'transferencia'       => $transferencia,
                'caja_inicial'        => $caja_inicial,
                'efectivo_entregado'  => $efectivo_entregado,
                'total_salidas'       => $total_salidas,
                'total_restantes'     => 0,
                'efectivo_esperado'   => $efectivo_esperado,
                'diferencia'          => $diferencia,
                'estado'              => 'borrador',
                'requiere_aprobacion' => abs($diferencia) > 10,
                'nombre_cubre'        => $validated['nombre_cubre'] ?? null,
                'observaciones'       => $validated['observaciones'] ?? null,
                'obs_dia'             => $validated['obs_dia'] ?? null,
                'destino_efectivo'    => $validated['destino_efectivo'] ?? 'TIENDA',
            ]);

            $this->procesarVentas($reporte, $ventas_data, $tiendaId, $agenteId, $comisionService);
            $this->guardarSalidas($reporte, $salidasData);
            (new ComisionOperativaService())->recalcularReporte($reporte);

            // Auditoría del evento de creación del cuadre, paridad legacy procesar_reporte.php:434.
            HistorialReporte::create([
                'reporte_id' => $reporte->id,
                'usuario_id' => $user->id,
                'accion'     => 'crear',
                'detalle'    => 'Cuadre creado.',
            ]);

            DB::commit();

            if ($user && $user->tienda_id) {
                try {
                    ReporteBorrador::query()
                        ->where('agente_id', $agenteId)
                        ->where('tienda_id', $user->tienda_id)
                        ->whereDate('fecha', now(config('reportes.timezone'))->toDateString())
                        ->delete();
                } catch (\Throwable $cleanupError) {
                    Log::warning('No se pudo limpiar el borrador tras guardar el reporte.', [
                        'reporte_id' => $reporte->id,
                        'usuario_id' => $user->id,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            $reporte->refresh()->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas']);
            return response()->json($reporte, 201);

        } catch (\RuntimeException $e) {
            DB::rollBack();
            // Violación de regla de negocio (p.ej. guardia de stock) → 422, no 500.
            return response()->json(['error' => $e->getMessage(), 'code' => 'STOCK_GUARD'], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al guardar: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, Reporte $reporte): JsonResponse
    {
        $this->autorizarPropietarioOAdmin($request, $reporte);
        if ($request->has('destino_efectivo')
            && ! $request->user()->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            abort(403, 'Solo administración puede cambiar el destino del efectivo.');
        }

        if ($reporte->estado === 'aprobado') {
            return response()->json(['error' => 'No se puede editar un reporte aprobado.'], 422);
        }

        $validated = $request->validate([
            'observaciones'      => 'nullable|string',
            'obs_dia'            => 'nullable|string',
            'efectivo_entregado' => 'sometimes|numeric|min:0',
            'destino_efectivo'   => 'sometimes|string|max:50',
            'estado'             => 'sometimes|in:borrador,enviado',
            'motivo_edicion'     => 'sometimes|nullable|string|max:500',
        ]);

        // Al corregir el efectivo entregado, recalcular diferencia/total_dia (el esperado no cambia
        // porque las ventas no se tocan en la edición ligera).
        if (array_key_exists('efectivo_entregado', $validated)) {
            $entregado = (float) $validated['efectivo_entregado'];
            $esperado  = (float) $reporte->efectivo_esperado;
            $validated['total_dia']           = $entregado;
            $validated['diferencia']          = round($entregado - $esperado, 2);
            $validated['requiere_aprobacion'] = abs($validated['diferencia']) > 10;
        }

        // Si la corrección venía de una edición autorizada, registrarla y cerrarla.
        if ($reporte->estado_edicion === 'APROBADO') {
            $validated['estado_edicion'] = 'CERRADO';
            HistorialReporte::create([
                'reporte_id' => $reporte->id,
                'usuario_id' => $request->user()?->id,
                'accion'     => 'edicion_reporte',
                'detalle'    => $request->input('motivo_edicion') ?: 'Corrección de campos autorizados.',
            ]);
        }

        unset($validated['motivo_edicion']);

        $reporte->update($validated);
        return response()->json($reporte->fresh());
    }

    public function destroy(Request $request, Reporte $reporte): JsonResponse
    {
        $this->autorizarPropietarioOAdmin($request, $reporte);

        // Paridad legacy (sis_bipay/gerencia/eliminar_reporte.php): se puede eliminar
        // cualquier reporte, incluido uno aprobado. El bloqueo por estado==='aprobado'
        // era una regla nueva del refactor que negocio decidió revertir.
        //
        // Pero destruir un reporte revierte stock y borra ventas: si la comisión de
        // alguna de esas ventas ya fue PAGADA en una planilla cerrada (mismo criterio
        // que InventarioController::restaurar), eliminar dejaría cobrada una comisión
        // sin la venta que la originó. Se bloquea con 422 antes de tocar nada.
        $fecha = (string) $reporte->fecha;
        foreach (Venta::where('reporte_id', $reporte->id)->pluck('vendedor_id')->unique() as $vendedorId) {
            $boletaPagada = PlanillaGuard::boletaPagada((int) $vendedorId, $fecha);
            if ($boletaPagada) {
                return response()->json([
                    'error' => "No se puede eliminar: la comisión de una venta de este reporte ya fue pagada en la planilla del "
                        . "{$boletaPagada->fecha_inicio} al {$boletaPagada->fecha_fin} (boleta #{$boletaPagada->id}).",
                ], 422);
            }
        }

        DB::transaction(function () use ($reporte) {
            $this->revertirVentas($reporte);
            $reporte->delete();
        });

        return response()->json(null, 204);
    }

    public function misReportes(Request $request): JsonResponse
    {
        $user = $request->user();

        // R3: el agente ve SIEMPRE lo suyo por agente_id (no por usuario_id — quien
        // registró el reporte puede ser otro usuario de la misma tienda).
        if ($user->esRol(Usuario::ROL_AGENTE) && ! $user->agente_id) {
            return response()->json(['message' => 'Tu usuario no está vinculado a un agente.'], 403);
        }

        $reportes = Reporte::query()
            ->when(
                $user->esRol(Usuario::ROL_AGENTE),
                fn ($q) => $q->where('agente_id', $user->agente_id),
                fn ($q) => $q->where('usuario_id', $user->id)
            )
            ->when($request->fecha_desde, fn ($q, $f) => $q->whereDate('fecha', '>=', $f))
            ->when($request->fecha_hasta, fn ($q, $f) => $q->whereDate('fecha', '<=', $f))
            ->when($request->estado,      fn ($q, $e) => $q->where('estado', $e))
            ->withCount('ventas')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($reportes);
    }

    public function actualizarDestino(Request $request, Reporte $reporte): JsonResponse
    {
        $user = $request->user();
        abort_if(
            TiendaGuard::bloqueaAcceso(
                $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE),
                $user->tienda_id,
                $reporte->tienda_id
            ),
            403,
            'No tienes permisos sobre este reporte.'
        );

        $validated = $request->validate([
            'destino_efectivo' => 'required|string|max:50',
            'observacion'      => 'nullable|string|max:255',
        ]);

        $reporte->update(['destino_efectivo' => $validated['destino_efectivo']]);

        // Auditoría (paridad gerencia/marcar_entregado.php): registrar el movimiento + obs.
        HistorialReporte::create([
            'reporte_id' => $reporte->id,
            'usuario_id' => auth()->id(),
            'accion'     => 'cambio_destino',
            'detalle'    => 'Destino efectivo: ' . $validated['destino_efectivo']
                . (! empty($validated['observacion']) ? ' | Obs: ' . $validated['observacion'] : ''),
        ]);

        return response()->json($reporte->fresh());
    }

    public function solicitarEdicion(Request $request, Reporte $reporte): JsonResponse
    {
        $this->autorizarPropietarioOAdmin($request, $reporte);

        $validated = $request->validate([
            'motivo_edicion' => 'required|string|max:500',
        ]);

        if ($reporte->estado === 'borrador') {
            return response()->json(['error' => 'El reporte aún está en borrador y puede editarse directamente.'], 422);
        }

        if ($reporte->estado_edicion === 'SOLICITADO') {
            return response()->json(['error' => 'Ya existe una solicitud de edición pendiente.'], 422);
        }

        $reporte->update([
            'estado_edicion' => 'SOLICITADO',
            'motivo_edicion' => $validated['motivo_edicion'],
        ]);

        HistorialReporte::create([
            'reporte_id' => $reporte->id,
            'usuario_id' => auth()->id(),
            'accion'     => 'solicito_edicion',
            'detalle'    => $validated['motivo_edicion'],
        ]);

        return response()->json($reporte->fresh());
    }

    public function aprobarEdicion(Request $request, Reporte $reporte): JsonResponse
    {
        abort_unless($request->user()->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE), 403);

        if ($reporte->estado_edicion !== 'SOLICITADO') {
            return response()->json(['error' => 'No hay solicitud de edición pendiente.'], 422);
        }

        $reporte->update([
            'estado_edicion' => 'APROBADO',
            'estado'         => 'borrador',
        ]);

        HistorialReporte::create([
            'reporte_id' => $reporte->id,
            'usuario_id' => auth()->id(),
            'accion'     => 'edicion_aprobada',
            'detalle'    => 'Edición aprobada por administración.',
        ]);

        return response()->json($reporte->fresh());
    }

    public function denegarEdicion(Request $request, Reporte $reporte): JsonResponse
    {
        abort_unless($request->user()->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE), 403);

        if ($reporte->estado_edicion !== 'SOLICITADO') {
            return response()->json(['error' => 'No hay solicitud de edición pendiente.'], 422);
        }

        $validated = $request->validate([
            'motivo' => 'nullable|string|max:500',
        ]);

        $reporte->update([
            'estado_edicion' => 'CERRADO',
            'motivo_edicion' => null,
        ]);

        HistorialReporte::create([
            'reporte_id' => $reporte->id,
            'usuario_id' => auth()->id(),
            'accion'     => 'edicion_rechazada',
            'detalle'    => 'Solicitud de edición denegada por administración.'
                . (! empty($validated['motivo']) ? ' | Motivo: ' . $validated['motivo'] : ''),
        ]);

        return response()->json($reporte->fresh());
    }

    public function historial(Request $request, Reporte $reporte): JsonResponse
    {
        $this->autorizarPropietarioOAdmin($request, $reporte);

        $historial = $reporte->historialReportes()
            ->with('usuario:id,nombre')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($historial);
    }

    /**
     * B4 helper — crea las ventas normalizadas del reporte (línea/equipo), con comisión
     * server-side (B2) y descuento de stock con guardia (B1). Reutilizado por store() y reprocesar().
     */
    private function procesarVentas(Reporte $reporte, array $ventasData, string $tiendaId, int $agenteId, ComisionService $comisionService): void
    {
        // ID interno (numérico) de la tienda para inventario_chips.tienda_id (paridad legacy procesar_reporte).
        $idTiendaInterna = $this->idTiendaInterna($tiendaId);

        foreach ($ventasData as $vd) {
            $vendedorId = (int) ($vd['vendedor_id'] ?? $agenteId);
            $vendedorActivo = DB::table('agentes')
                ->where('id', $vendedorId)
                ->where('estado', 'ACTIVO')
                ->exists();
            if (! $vendedorActivo) {
                throw new \RuntimeException('Debes seleccionar un vendedor activo para cada venta.');
            }

            $cliente_id = null;
            if (! empty($vd['cliente_dni'])) {
                $dni = $vd['cliente_dni'];
                $cliente = Cliente::firstOrCreate(
                    ['dni_ruc' => $dni],
                    ['nombre' => 'Por identificar', 'tipo_documento' => strlen($dni) === 8 ? 'DNI' : 'RUC']
                );
                $cliente_id = $cliente->id;
            }

            // B2 — Comisión SERVER-AUTHORITATIVE (no se confía en el cliente).
            $comisionTotal = $comisionService->calcularComisionVenta($vd);

            // El ENUM de ventas.tipo_venta NO incluye 'APOYO' en la BD actual.
            // Lo guardamos como OTROS_FLUJO + subtipo='APOYO' y el modelo Venta
            // tiene un accessor que lo revierte al leer, por lo que el frontend
            // siempre recibe 'APOYO' como tipo_venta.
            $tipoOriginal = $vd['tipo_venta'];
            $tipoVentaBD  = $tipoOriginal === 'APOYO' ? 'OTROS_FLUJO' : $tipoOriginal;
            $subtipoBD    = $tipoOriginal === 'APOYO'
                ? 'APOYO'
                : ($vd['subtipo'] ?? null);

            // Calcular monto_total server-side para garantizar consistencia
            // independientemente del valor que envíe el cliente.
            $montoCalculado = match ($tipoOriginal) {
                'POSTPAGO', 'PREPAGO', 'APOYO' =>
                    round((float) ($vd['cobrado_unitario'] ?? 0) * max(1, (int) ($vd['cantidad'] ?? 1)), 2),
                'EQUIPO', 'ACCESORIO' =>
                    round((float) ($vd['precio_venta'] ?? 0), 2),
                default =>
                    round((float) ($vd['monto_total'] ?? 0), 2),
            };
            // Si el cliente envió un monto válido y el calculado es 0, usar el del cliente como fallback
            if ($montoCalculado <= 0 && (float) ($vd['monto_total'] ?? 0) > 0) {
                $montoCalculado = round((float) $vd['monto_total'], 2);
            }
            $efectivoCalculado = ($tipoOriginal === 'EQUIPO' && strtoupper((string) ($vd['tipo_pago'] ?? 'CONTADO')) === 'CUOTAS')
                ? round((float) ($vd['por_cobrar_financiera'] ?? 0), 2)
                : $montoCalculado;

            $venta = Venta::create([
                'reporte_id'        => $reporte->id,
                'vendedor_id'       => $vendedorId,
                'cliente_id'        => $cliente_id,
                'tipo_venta'        => $tipoVentaBD,
                'subtipo'           => $subtipoBD,
                'cross_selling'     => (bool) ($vd['cross_selling'] ?? false),
                'tienda_destino'    => (($vd['cross_selling'] ?? false) || $tipoOriginal === 'APOYO')
                    ? ($vd['tienda_destino'] ?? null) : null,
                'monto_total'       => $montoCalculado,
                'efectivo_inicial'  => $efectivoCalculado,
                'comision_generada' => $comisionTotal,
                'comision_estado'   => ($tipoOriginal === 'EQUIPO'
                    && strtoupper((string) ($vd['tipo_pago'] ?? 'CONTADO')) === 'CUOTAS') ? 'PENDIENTE' : 'ACTIVA',
                'es_remate'         => (bool) ($vd['es_remate'] ?? false),
                'es_extranjero'     => (bool) ($vd['es_extranjero'] ?? false),
            ]);

            if (in_array($vd['tipo_venta'], ['EQUIPO', 'ACCESORIO']) && ! empty($vd['producto_nombre'])) {
                VentaEquipo::create([
                    'venta_id'              => $venta->id,
                    'inventario_tienda_id'  => $vd['inventario_tienda_id'] ?? 0,
                    'producto_nombre_snap'  => $vd['producto_nombre'],
                    'imei_serial_snap'      => $vd['imei_serial'] ?? null,
                    'tipo_item'             => $vd['tipo_venta'],
                    'tipo_pago'             => $vd['tipo_pago'] ?? 'CONTADO',
                    'financiera'            => $vd['financiera'] ?? null,
                    'precio_venta'          => $vd['precio_venta'] ?? $vd['monto_total'],
                    'costo_snap'            => $vd['costo_snap'] ?? 0,
                    'ganancia_snap'         => isset($vd['precio_venta'], $vd['costo_snap'])
                        ? ((float) $vd['precio_venta'] - (float) $vd['costo_snap']) : null,
                    'por_cobrar_financiera' => $vd['por_cobrar_financiera'] ?? 0,
                ]);

                // B1 — Descuento de stock con guardia (rowCount !== 1 ⇒ throw ⇒ rollBack).
                $invId = (int) ($vd['inventario_tienda_id'] ?? 0);
                if ($invId > 0) {
                    // Validar precio mínimo (server-side anti-bypass), paridad legacy
                    // procesar_reporte.php:96-101 — se ejecuta ANTES del descuento de stock.
                    $precioMinimoDb = (float) (DB::table('inventario_tiendas')
                        ->where('id', $invId)
                        ->value('precio_minimo') ?? 0);
                    $precioVenta = (float) ($vd['precio_venta'] ?? $vd['monto_total'] ?? 0);
                    if ($precioMinimoDb > 0 && $precioVenta < $precioMinimoDb) {
                        throw new \RuntimeException(
                            'Precio de venta (S/ ' . number_format($precioVenta, 2) . ') es menor al mínimo permitido '
                            . '(S/ ' . number_format($precioMinimoDb, 2) . ') para "' . $vd['producto_nombre']
                            . '". Comuníquese con su encargado/a.'
                        );
                    }

                    // El WHERE cantidad>0 garantiza cantidad-1>=0 (sin GREATEST, portable a sqlite/mysql).
                    $update = ['cantidad' => DB::raw('cantidad - 1')];
                    if (Schema::hasColumn('inventario_tiendas', 'estado')) {
                        $update['estado'] = DB::raw("CASE WHEN cantidad - 1 <= 0 THEN 'VENDIDO' ELSE estado END");
                    }
                    if (Schema::hasColumn('inventario_tiendas', 'fecha_venta')) {
                        $update['fecha_venta'] = now();
                    }
                    if (Schema::hasColumn('inventario_tiendas', 'vendido_por_id')) {
                        $update['vendido_por_id'] = $vendedorId;
                    }
                    if (Schema::hasColumn('inventario_tiendas', 'reporte_venta_id')) {
                        $update['reporte_venta_id'] = $reporte->id;
                    }

                    $query = DB::table('inventario_tiendas')
                        ->where('id', $invId)
                        ->where('tienda_id', $tiendaId)
                        ->where('cantidad', '>', 0);
                    if (Schema::hasColumn('inventario_tiendas', 'estado')) {
                        $query->where('estado', 'DISPONIBLE');
                    }
                    $afectadas = $query->update($update);

                    if ($afectadas !== 1) {
                        throw new \RuntimeException(
                            "El producto \"{$vd['producto_nombre']}\" ya fue vendido, trasladado o no pertenece a esta tienda."
                        );
                    }
                }
            }

            if (in_array($vd['tipo_venta'], ['POSTPAGO', 'PREPAGO', 'APOYO'], true) && ! empty($vd['plan_nombre'])) {
                VentaLinea::create([
                    'venta_id'          => $venta->id,
                    'plan_nombre_snap'  => $vd['plan_nombre'],
                    'tipo_alta'         => $vd['tipo_alta'] ?? 'LN',
                    'cantidad'          => $vd['cantidad'] ?? 1,
                    'cobrado_unitario'  => $vd['cobrado_unitario'] ?? $vd['monto_total'],
                    'comision_unitaria' => round($comisionTotal / max(1, (int) ($vd['cantidad'] ?? 1)), 2),
                    'es_migracion'      => (bool) ($vd['es_migracion'] ?? false),
                    'es_upgrade'        => (bool) ($vd['es_upgrade'] ?? false),
                    'es_esim'           => (bool) ($vd['es_esim'] ?? false),
                    'plan_anterior'     => $vd['plan_anterior'] ?? null,
                ]);

                // Descuento de inventario_chips (paridad legacy procesar_reporte.php:227-301).
                // Migración/upgrade/eSIM no consumen chip físico.
                $consumeChip = ! ((bool) ($vd['es_migracion'] ?? false))
                    && ! ((bool) ($vd['es_upgrade'] ?? false))
                    && ! ((bool) ($vd['es_esim'] ?? false));

                if ($consumeChip && $idTiendaInterna) {
                    $cantidad = max(1, (int) ($vd['cantidad'] ?? 1));
                    if ($vd['tipo_venta'] === 'APOYO') {
                        // Apoyo: descuenta del lote del store destino que esta tienda tiene físicamente.
                        $origenCode  = (string) ($vd['tienda_destino'] ?? '');
                        $incluirNull = false;
                    } else {
                        // Postpago/Prepago: lote propio (tienda_origen = código propio o NULL).
                        $origenCode  = $tiendaId;
                        $incluirNull = true;
                    }

                    if ($origenCode !== '') {
                        $descontados = $this->descontarChips(
                            $venta->id,
                            (int) $idTiendaInterna,
                            $origenCode,
                            $cantidad,
                            $incluirNull
                        );
                        if (Schema::hasColumn('ventas', 'chips_descontados')) {
                            $venta->update(['chips_descontados' => $descontados]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Descuenta `cantidad` chips y registra exactamente de qué lotes salieron.
     * Si el stock no alcanza, aborta el cuadre completo para no confirmar una venta inconsistente.
     */
    private function descontarChips(
        int $ventaId,
        int $idTiendaInterna,
        string $origenCode,
        int $cantidad,
        bool $incluirNull
    ): int
    {
        $lotes = DB::table('inventario_chips')
            ->where('tienda_id', $idTiendaInterna)
            ->where('stock_actual', '>', 0)
            ->where(function ($q) use ($origenCode, $incluirNull) {
                $q->where('tienda_origen', $origenCode);
                if ($incluirNull) {
                    $q->orWhereNull('tienda_origen');
                }
            })
            ->orderByRaw('tienda_origen = ? DESC', [$origenCode]) // primero el lote exacto
            ->orderByDesc('stock_actual')
            ->lockForUpdate()
            ->get();

        if ($lotes->sum(fn ($lote) => (int) $lote->stock_actual) < $cantidad) {
            throw new \RuntimeException(
                "Stock de chips insuficiente para {$origenCode}: se requieren {$cantidad} unidades."
            );
        }

        $restante = $cantidad;
        foreach ($lotes as $lote) {
            if ($restante <= 0) break;
            $quita = min($restante, (int) $lote->stock_actual);
            DB::table('inventario_chips')->where('id', $lote->id)->decrement('stock_actual', $quita);
            if (Schema::hasTable('venta_chip_movimientos')) {
                DB::table('venta_chip_movimientos')->insert([
                    'venta_id' => $ventaId,
                    'inventario_chip_id' => $lote->id,
                    'cantidad' => $quita,
                    'created_at' => now(),
                ]);
            }
            $restante -= $quita;
        }

        return $cantidad - $restante;
    }

    /**
     * Repone `cantidad` chips al lote canónico (tienda_id, tienda_origen=origen); lo crea si no existe.
     * Usado al revertir un reporte (edición) para no dejar el stock de chips descuadrado.
     */
    private function reponerChips(int $idTiendaInterna, string $origenCode, int $cantidad): void
    {
        if ($cantidad <= 0) {
            return;
        }

        $afectadas = DB::table('inventario_chips')
            ->where('tienda_id', $idTiendaInterna)
            ->where('tienda_origen', $origenCode)
            ->increment('stock_actual', $cantidad);

        if ($afectadas === 0) {
            DB::table('inventario_chips')->insert([
                'tienda_id'     => $idTiendaInterna,
                'tienda_origen' => $origenCode,
                'tipo_chip'     => 'FÍSICO',
                'stock_actual'  => $cantidad,
            ]);
        }
    }

    /**
     * B4 helper — revierte el stock de los equipos vendidos del reporte (lo devuelve a inventario)
     * y borra sus ventas/lineas/equipos. Usado por reprocesar() (edición 1:1).
     */
    private function revertirVentas(Reporte $reporte): void
    {
        $ventas = Venta::where('reporte_id', $reporte->id)->with('equipo')->get();

        // ID interno de la tienda para reponer inventario_chips al revertir.
        $idTiendaInterna = $this->idTiendaInterna((string) $reporte->tienda_id);
        $tieneChipsCol   = Schema::hasColumn('ventas', 'chips_descontados');
        $tieneMovimientosTabla = Schema::hasTable('venta_chip_movimientos');

        // Ventas que efectivamente descontaron chips (únicas que necesitan revisar movimientos).
        $ventaIdsConChips = ($tieneChipsCol && $idTiendaInterna)
            ? $ventas->filter(fn ($v) => (int) ($v->chips_descontados ?? 0) > 0)->pluck('id')
            : collect();

        // OPT-01: precarga TODOS los movimientos de chips de todas las ventas en UNA sola
        // consulta (antes: un SELECT+lock por venta) y agrupa los incrementos por chip para
        // hacer un único UPDATE por chip (antes: un UPDATE por movimiento) sin cambiar la
        // semántica: si el chip ya no existe el increment sigue afectando 0 filas y esa
        // cantidad se sigue contando como "faltante" para reponerChips().
        $movimientosPorVenta = collect();
        $chipIncrementoOk = [];
        if ($tieneMovimientosTabla && $ventaIdsConChips->isNotEmpty()) {
            $movimientos = DB::table('venta_chip_movimientos')
                ->whereIn('venta_id', $ventaIdsConChips)
                ->lockForUpdate()
                ->get();
            $movimientosPorVenta = $movimientos->groupBy('venta_id');

            $incrementosPorChip = $movimientos
                ->groupBy('inventario_chip_id')
                ->map(fn ($grupo) => (int) $grupo->sum('cantidad'));

            foreach ($incrementosPorChip as $chipId => $suma) {
                $afectadas = DB::table('inventario_chips')
                    ->where('id', $chipId)
                    ->increment('stock_actual', $suma);
                $chipIncrementoOk[$chipId] = $afectadas > 0;
            }

            DB::table('venta_chip_movimientos')->whereIn('venta_id', $ventaIdsConChips)->delete();
        }

        foreach ($ventas as $venta) {
            // Reponer chips descontados al guardar (paridad: la edición revierte el stock previo).
            $chipsPrevios = $tieneChipsCol ? (int) ($venta->chips_descontados ?? 0) : 0;
            if ($chipsPrevios > 0 && $idTiendaInterna) {
                $repuestos = 0;
                if ($tieneMovimientosTabla) {
                    foreach ($movimientosPorVenta->get($venta->id, collect()) as $movimiento) {
                        if ($chipIncrementoOk[$movimiento->inventario_chip_id] ?? false) {
                            $repuestos += (int) $movimiento->cantidad;
                        }
                    }
                }

                $faltante = max(0, $chipsPrevios - $repuestos);
                $origenCode = $venta->tipo_venta === 'APOYO'
                    ? (string) ($venta->tienda_destino ?? '')
                    : (string) $reporte->tienda_id;
                if ($faltante > 0 && $origenCode !== '') {
                    $this->reponerChips((int) $idTiendaInterna, $origenCode, $faltante);
                }
            }

            $invId = (int) ($venta->equipo->inventario_tienda_id ?? 0);
            if ($venta->equipo && $invId > 0) {
                $update = ['cantidad' => DB::raw('cantidad + 1')];
                if (Schema::hasColumn('inventario_tiendas', 'estado')) {
                    $update['estado'] = 'DISPONIBLE';
                }
                if (Schema::hasColumn('inventario_tiendas', 'fecha_venta')) {
                    $update['fecha_venta'] = null;
                }
                if (Schema::hasColumn('inventario_tiendas', 'reporte_venta_id')) {
                    $update['reporte_venta_id'] = null;
                }
                DB::table('inventario_tiendas')->where('id', $invId)->update($update);
            }
        }

        $ids = $ventas->pluck('id');
        VentaEquipo::whereIn('venta_id', $ids)->delete();
        VentaLinea::whereIn('venta_id', $ids)->delete();
        Venta::whereIn('id', $ids)->delete();
    }

    // ── PUT /reportes/{reporte}/reprocesar — Edición 1:1 (B4, paridad procesar_edicion) ──
    // Revierte stock → borra ventas → re-procesa todo. Requiere edición autorizada (estado_edicion=APROBADO).
    public function reprocesar(Request $request, Reporte $reporte): JsonResponse
    {
        $user = $request->user();

        // Paridad legacy (procesar_edicion.php:49-54): admin/gerente siempre pasa; el resto
        // solo puede reprocesar SU PROPIO reporte y únicamente cuando la edición ya fue aprobada.
        if (! $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            abort_unless(
                (string) $reporte->tienda_id === (string) $user->tienda_id
                    && $reporte->estado_edicion === 'APROBADO',
                403,
                'No tienes permisos para reprocesar este reporte.'
            );
        }

        // Borradores: el admin puede editar directamente sin aprobación previa
        if ($reporte->estado !== 'borrador' && $reporte->estado_edicion !== 'APROBADO') {
            return response()->json(['error' => 'La edición no fue autorizada por administración.'], 422);
        }

        $validated = $request->validate([
            'agente_id'                      => 'required|integer',
            'tienda_id'                      => 'required|string|max:50',
            'fecha'                          => 'required|date',
            'caja_inicial'                   => 'required|numeric|min:0',
            'yape'                           => 'nullable|numeric|min:0',
            'bipay'                          => 'nullable|numeric|min:0',
            'transferencia'                  => 'nullable|numeric|min:0',
            'recarga_bipay'                  => 'nullable|numeric|min:0',
            'pago_servicio'                  => 'nullable|numeric|min:0',
            'pago_krece'                     => 'nullable|numeric|min:0',
            'pago_payjoy'                    => 'nullable|numeric|min:0',
            'tickets_tusamy'                 => 'nullable|numeric|min:0',
            'retiro_bipay'                   => 'nullable|numeric|min:0',
            'efectivo_entregado'             => 'required|numeric|min:0',
            'total_salidas'                  => 'nullable|numeric|min:0',
            'salidas'                        => 'nullable|array',
            'salidas.*.tipo'                 => 'required_with:salidas|in:adelanto,gasto,pasaje,otro',
            'salidas.*.monto'                => 'required_with:salidas|numeric|gt:0',
            'salidas.*.observacion'          => 'nullable|string|max:1000',
            'nombre_cubre'                   => 'nullable|string|max:100',
            'observaciones'                  => 'nullable|string',
            'obs_dia'                        => 'nullable|string',
            'destino_efectivo'               => 'nullable|string|max:50',
            'ventas'                         => 'nullable|array',
            'ventas.*.venta_id'              => 'nullable|integer',
            'ventas.*.vendedor_id'           => 'required|integer|exists:agentes,id',
            'ventas.*.tipo_venta'            => 'required_with:ventas|in:EQUIPO,ACCESORIO,POSTPAGO,PREPAGO,OTROS_FLUJO,APOYO',
            'ventas.*.subtipo'               => 'nullable|string|max:50',
            'ventas.*.monto_total'           => 'required_with:ventas|numeric|min:0',
            'ventas.*.efectivo_inicial'      => 'nullable|numeric|min:0',
            'ventas.*.cross_selling'         => 'nullable|boolean',
            'ventas.*.tienda_destino'        => 'nullable|string|max:10',
            'ventas.*.es_remate'             => 'nullable|boolean',
            'ventas.*.es_extranjero'         => 'nullable|boolean',
            'ventas.*.es_migracion'          => 'nullable|boolean',
            'ventas.*.es_upgrade'            => 'nullable|boolean',
            'ventas.*.es_esim'               => 'nullable|boolean',
            'ventas.*.plan_anterior'         => 'nullable|numeric|min:0',
            'ventas.*.cliente_dni'           => 'nullable|string|max:11',
            'ventas.*.inventario_tienda_id'  => 'nullable|integer',
            'ventas.*.producto_nombre'       => 'nullable|string|max:150',
            'ventas.*.imei_serial'           => 'nullable|string|max:50',
            'ventas.*.tipo_pago'             => 'nullable|in:CONTADO,CUOTAS',
            'ventas.*.financiera'            => 'nullable|string|max:50',
            'ventas.*.precio_venta'          => 'nullable|numeric|min:0',
            'ventas.*.costo_snap'            => 'nullable|numeric|min:0',
            'ventas.*.por_cobrar_financiera' => 'nullable|numeric|min:0',
            'ventas.*.plan_nombre'           => 'nullable|string|max:150',
            'ventas.*.tipo_alta'             => 'nullable|string|max:30',
            'ventas.*.cantidad'              => 'nullable|integer|min:1',
            'ventas.*.cobrado_unitario'      => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $ventas_data        = $validated['ventas'] ?? [];
            $svc                = new ComisionService();
            $salidasData        = $validated['salidas'] ?? [];
            $total_salidas      = $request->has('salidas')
                ? round((float) collect($salidasData)->sum('monto'), 2)
                : (float) ($validated['total_salidas'] ?? 0);
            $yape               = (float) ($validated['yape'] ?? 0);
            $bipay              = (float) ($validated['bipay'] ?? 0);
            $transferencia      = (float) ($validated['transferencia'] ?? 0);
            $retiro_bipay       = (float) ($validated['retiro_bipay'] ?? 0);
            $efectivo_entregado = (float) $validated['efectivo_entregado'];

            $total_calculado   = (float) collect($ventas_data)->sum('monto_total');
            $total_sistema     = $total_calculado
                + (float) ($validated['recarga_bipay'] ?? 0)
                + (float) ($validated['pago_servicio'] ?? 0)
                + (float) ($validated['pago_krece'] ?? 0)
                + (float) ($validated['pago_payjoy'] ?? 0)
                + (float) ($validated['tickets_tusamy'] ?? 0);
            $total_no_fisico   = $yape + $bipay + $transferencia + $retiro_bipay;
            $efectivo_esperado = round($total_sistema - $total_no_fisico - $total_salidas, 2);
            $diferencia        = round($efectivo_entregado - $efectivo_esperado, 2);
            $reporte->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas']);
            $snapshotAntes = $this->snapshotReporte($reporte);
            $ventasAntes = $this->resumirVentasGuardadas($reporte->ventas);
            $ventasDespues = $this->resumirVentasPayload($ventas_data, (int) $validated['agente_id']);
            $cambiosVendedor = $this->detectarCambiosVendedor($ventasAntes, $ventasDespues);

            // 1) Revertir stock + borrar ventas previas (paridad legacy: anulación completa).
            $this->revertirVentas($reporte);

            // 2) Re-escribir cabecera con los nuevos totales.
            $reporte->update([
                'total_dia'           => $efectivo_entregado,
                'total_calculado'     => $total_calculado,
                'yape'                => $yape,
                'bipay'               => $bipay,
                'recarga_bipay'       => (float) ($validated['recarga_bipay'] ?? 0),
                'pago_servicio'       => (float) ($validated['pago_servicio'] ?? 0),
                'pago_krece'          => (float) ($validated['pago_krece'] ?? 0),
                'pago_payjoy'         => (float) ($validated['pago_payjoy'] ?? 0),
                'tickets_tusamy'      => (float) ($validated['tickets_tusamy'] ?? 0),
                'retiro_bipay'        => $retiro_bipay,
                'transferencia'       => $transferencia,
                'caja_inicial'        => (float) $validated['caja_inicial'],
                'efectivo_entregado'  => $efectivo_entregado,
                'total_salidas'       => $total_salidas,
                'efectivo_esperado'   => $efectivo_esperado,
                'diferencia'          => $diferencia,
                'requiere_aprobacion' => abs($diferencia) > 10,
                'observaciones'       => $validated['observaciones'] ?? $reporte->observaciones,
                'obs_dia'             => $validated['obs_dia'] ?? $reporte->obs_dia,
                'destino_efectivo'    => $validated['destino_efectivo'] ?? $reporte->destino_efectivo,
                'estado_edicion'      => 'CERRADO',
            ]);

            // 3) Re-procesar las nuevas ventas (stock + comisión server-side).
            $this->procesarVentas($reporte, $ventas_data, (string) $validated['tienda_id'], (int) $validated['agente_id'], $svc);
            if ($request->has('salidas')) {
                $this->guardarSalidas($reporte, $salidasData);
            }
            (new ComisionOperativaService())->recalcularReporte($reporte);

            $reporte->refresh()->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas']);

            // Paridad legacy (procesar_edicion.php): si el cambio de vendedor revierte
            // una alerta previa, se audita como 'edicion_restaurada' en vez de 'edicion_critica'.
            $esRestauracion = ! empty($cambiosVendedor)
                && $this->esRestauracionComision($cambiosVendedor, (int) $reporte->id);

            if (empty($cambiosVendedor)) {
                $accionHistorial  = 'edicion_reporte';
                $detalleHistorial = 'Reporte reprocesado tras edicion autorizada.';
            } elseif ($esRestauracion) {
                $accionHistorial  = 'edicion_restaurada';
                $detalleHistorial = 'Comision restaurada: ' . implode(' // ', $cambiosVendedor);
            } else {
                $accionHistorial  = 'edicion_critica';
                $detalleHistorial = 'Cambio de vendedor/comision: ' . implode(' // ', $cambiosVendedor);
            }

            HistorialReporte::create([
                'reporte_id' => $reporte->id,
                'usuario_id' => $request->user()?->id,
                'accion'     => $accionHistorial,
                'detalle'    => $detalleHistorial,
                'snapshot_antes' => $snapshotAntes,
                'snapshot_despues' => $this->snapshotReporte($reporte),
            ]);

            DB::commit();

            return response()->json($reporte->fresh()->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas']));
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage(), 'code' => 'STOCK_GUARD'], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al reprocesar: ' . $e->getMessage()], 500);
        }
    }

    // ── POST /reporte-categorias/{id}/fijar-costo — Campana: fijar costo rápido
    private function snapshotReporte(Reporte $reporte): array
    {
        return [
            'reporte' => $reporte->only([
                'agente_id', 'tienda_id', 'fecha', 'total_calculado', 'efectivo_entregado',
                'efectivo_esperado', 'diferencia', 'total_salidas', 'destino_efectivo',
            ]),
            'ventas' => $this->resumirVentasGuardadas($reporte->ventas),
            'salidas' => $reporte->salidas->map(fn (ReporteSalida $salida) => [
                'tipo' => $salida->tipo,
                'monto' => (float) $salida->monto,
                'observacion' => $salida->observacion,
            ])->values()->all(),
        ];
    }

    private function guardarSalidas(Reporte $reporte, array $salidas): void
    {
        ReporteSalida::where('reporte_id', $reporte->id)->delete();

        foreach ($salidas as $salida) {
            ReporteSalida::create([
                'reporte_id' => $reporte->id,
                'tipo' => strtolower((string) $salida['tipo']),
                'monto' => round((float) $salida['monto'], 2),
                'observacion' => trim((string) ($salida['observacion'] ?? '')) ?: null,
            ]);
        }
    }

    private function resumirVentasGuardadas(iterable $ventas): array
    {
        $resumen = [];

        foreach ($ventas as $venta) {
            $descripcion = $venta->equipo?->producto_nombre_snap
                ?? $venta->linea?->plan_nombre_snap
                ?? $venta->subtipo
                ?? $venta->tipo_venta;
            $dni = $venta->cliente?->dni_ruc ?? '';

            $resumen[] = [
                'venta_id' => (int) $venta->id,
                'clave' => $this->claveAuditoriaVenta((string) $venta->tipo_venta, (string) $descripcion, (string) $dni),
                'descripcion' => (string) $descripcion,
                'cliente' => (string) $dni,
                'vendedor_id' => (int) $venta->vendedor_id,
            ];
        }

        return $resumen;
    }

    private function resumirVentasPayload(array $ventas, int $agenteId): array
    {
        return collect($ventas)->map(function (array $venta) use ($agenteId) {
            $descripcion = $venta['producto_nombre']
                ?? $venta['plan_nombre']
                ?? $venta['subtipo']
                ?? $venta['tipo_venta'];
            $dni = (string) ($venta['cliente_dni'] ?? '');

            return [
                'venta_id' => isset($venta['venta_id']) ? (int) $venta['venta_id'] : null,
                'clave' => $this->claveAuditoriaVenta((string) $venta['tipo_venta'], (string) $descripcion, $dni),
                'descripcion' => (string) $descripcion,
                'cliente' => $dni,
                'vendedor_id' => (int) ($venta['vendedor_id'] ?? $agenteId),
            ];
        })->all();
    }

    private function detectarCambiosVendedor(array $antes, array $despues): array
    {
        $antesPorId = collect($antes)->keyBy('venta_id');
        $antesPorClave = collect($antes)->groupBy('clave')->map(fn ($items) => $items->values()->all())->all();
        $idsUsados = [];
        $cambios = [];
        $nombres = DB::table('agentes')->pluck('nombres', 'id');

        foreach ($despues as $ventaNueva) {
            $ventaAnterior = null;
            $ventaId = $ventaNueva['venta_id'] ?? null;

            if ($ventaId && $antesPorId->has($ventaId)) {
                $ventaAnterior = $antesPorId->get($ventaId);
                $idsUsados[(int) $ventaId] = true;
            } else {
                foreach ($antesPorClave[$ventaNueva['clave']] ?? [] as $candidata) {
                    if (! isset($idsUsados[(int) $candidata['venta_id']])) {
                        $ventaAnterior = $candidata;
                        $idsUsados[(int) $candidata['venta_id']] = true;
                        break;
                    }
                }
            }

            if (! $ventaAnterior || (int) $ventaAnterior['vendedor_id'] === (int) $ventaNueva['vendedor_id']) {
                continue;
            }

            $anterior = $nombres[(int) $ventaAnterior['vendedor_id']] ?? "Agente #{$ventaAnterior['vendedor_id']}";
            $nuevo = $nombres[(int) $ventaNueva['vendedor_id']] ?? "Agente #{$ventaNueva['vendedor_id']}";
            $cliente = $ventaNueva['cliente'] !== '' ? " | Cliente: {$ventaNueva['cliente']}" : '';
            $cambios[] = "[{$ventaNueva['descripcion']}{$cliente}] de {$anterior} a {$nuevo}";
        }

        return $cambios;
    }

    private function claveAuditoriaVenta(string $tipo, string $descripcion, string $dni): string
    {
        return mb_strtoupper(trim($tipo) . '|' . trim($descripcion) . '|' . trim($dni));
    }

    /**
     * ¿La edición actual restaura una comisión previamente robada? (paridad legacy
     * reportes/procesar_edicion.php → flag es_restauracion / accion edicion_restaurada).
     *
     * Se compara cada cambio de vendedor actual "[desc] de A a B" contra la PRIMERA
     * alerta 'edicion_critica' del reporte: si esa alerta contiene el movimiento inverso
     * "[desc] de B a A", significa que este cambio devuelve la venta a su vendedor original.
     *
     * @param  array<int, string>  $cambiosVendedor
     */
    private function esRestauracionComision(array $cambiosVendedor, int $reporteId): bool
    {
        $primeraAlerta = HistorialReporte::where('reporte_id', $reporteId)
            ->where('accion', 'edicion_critica')
            ->orderBy('id')
            ->value('detalle');

        if (! $primeraAlerta) {
            return false;
        }

        foreach ($cambiosVendedor as $cambio) {
            if (! preg_match('/\[(.*?)\] de (.*?) a (.*?)$/', $cambio, $m)) {
                continue;
            }
            [$inside, $anterior, $nuevo] = [$m[1], $m[2], $m[3]];
            $inverso = "[{$inside}] de {$nuevo} a {$anterior}";
            if (strpos($primeraAlerta, $inverso) !== false) {
                return true;
            }
        }

        return false;
    }

    private function idTiendaInterna(string $codigo): ?int
    {
        if (Schema::hasColumn('tiendas', 'id')) {
            $id = DB::table('tiendas')->where('codigo', $codigo)->value('id');

            return $id !== null ? (int) $id : null;
        }

        if (DB::getDriverName() === 'sqlite') {
            $tienda = DB::table('tiendas')
                ->where('codigo', $codigo)
                ->selectRaw('rowid as internal_id')
                ->first();

            return $tienda ? (int) $tienda->internal_id : null;
        }

        return null;
    }

    // ── POST /reportes/{reporte}/agregar-venta ────────────────────────────────
    // Guarda una venta individual en el reporte (stock + comisión + recálculo).
    public function agregarVenta(Request $request, Reporte $reporte): JsonResponse
    {
        $this->autorizarPropietarioOAdmin($request, $reporte);
        if ($reporte->estado === 'aprobado') {
            return response()->json(['error' => 'No se puede modificar un reporte aprobado.'], 422);
        }

        $validated = $request->validate([
            'vendedor_id'           => 'required|integer|exists:agentes,id',
            'tipo_venta'            => 'required|in:EQUIPO,ACCESORIO,POSTPAGO,PREPAGO,OTROS_FLUJO,APOYO',
            'subtipo'               => 'nullable|string|max:50',
            'monto_total'           => 'required|numeric|min:0',
            'efectivo_inicial'      => 'nullable|numeric|min:0',
            'cross_selling'         => 'nullable|boolean',
            'tienda_destino'        => 'nullable|string|max:10',
            'es_remate'             => 'nullable|boolean',
            'es_extranjero'         => 'nullable|boolean',
            'es_migracion'          => 'nullable|boolean',
            'es_upgrade'            => 'nullable|boolean',
            'es_esim'               => 'nullable|boolean',
            'plan_anterior'         => 'nullable|numeric|min:0',
            'cliente_dni'           => 'nullable|string|max:11',
            'inventario_tienda_id'  => 'nullable|integer',
            'producto_nombre'       => 'nullable|string|max:150',
            'imei_serial'           => 'nullable|string|max:50',
            'tipo_pago'             => 'nullable|in:CONTADO,CUOTAS',
            'financiera'            => 'nullable|string|max:50',
            'precio_venta'          => 'nullable|numeric|min:0',
            'costo_snap'            => 'nullable|numeric|min:0',
            'por_cobrar_financiera' => 'nullable|numeric|min:0',
            'plan_nombre'           => 'nullable|string|max:150',
            'tipo_alta'             => 'nullable|string|max:30',
            'cantidad'              => 'nullable|integer|min:1',
            'cobrado_unitario'      => 'nullable|numeric|min:0',
            'comision_unitaria'     => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $comisionService = new ComisionService();
            $this->procesarVentas($reporte, [$validated], (string) $reporte->tienda_id, (int) $reporte->agente_id, $comisionService);
            $this->recalcularTotalesReporte($reporte);
            (new ComisionOperativaService())->recalcularReporte($reporte);
            DB::commit();
            $reporte->refresh()->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas']);
            return response()->json($reporte);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage(), 'code' => 'STOCK_GUARD'], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al agregar la venta: ' . $e->getMessage()], 500);
        }
    }

    // ── DELETE /reportes/{reporte}/ventas/{venta} ─────────────────────────────
    // Elimina una venta individual, repone su stock y recalcula totales.
    public function eliminarVenta(Request $request, Reporte $reporte, Venta $venta): JsonResponse
    {
        $this->autorizarPropietarioOAdmin($request, $reporte);
        if ($reporte->estado === 'aprobado') {
            return response()->json(['error' => 'No se puede modificar un reporte aprobado.'], 422);
        }
        if ((int) $venta->reporte_id !== $reporte->id) {
            return response()->json(['error' => 'Esta venta no pertenece al reporte.'], 422);
        }

        DB::beginTransaction();
        try {
            $idTiendaInterna = $this->idTiendaInterna((string) $reporte->tienda_id);
            $tieneChipsCol   = Schema::hasColumn('ventas', 'chips_descontados');
            $chipsPrevios    = $tieneChipsCol ? (int) ($venta->chips_descontados ?? 0) : 0;

            // Reponer chips
            if ($chipsPrevios > 0 && $idTiendaInterna) {
                $repuestos = 0;
                if (Schema::hasTable('venta_chip_movimientos')) {
                    $movimientos = DB::table('venta_chip_movimientos')->where('venta_id', $venta->id)->lockForUpdate()->get();
                    foreach ($movimientos as $m) {
                        DB::table('inventario_chips')->where('id', $m->inventario_chip_id)->increment('stock_actual', (int) $m->cantidad);
                        $repuestos += (int) $m->cantidad;
                    }
                    DB::table('venta_chip_movimientos')->where('venta_id', $venta->id)->delete();
                }
                $faltante = max(0, $chipsPrevios - $repuestos);
                $origenCode = $venta->tipo_venta === 'APOYO' ? (string) ($venta->tienda_destino ?? '') : (string) $reporte->tienda_id;
                if ($faltante > 0 && $origenCode !== '') {
                    $this->reponerChips((int) $idTiendaInterna, $origenCode, $faltante);
                }
            }

            // Reponer inventario equipo
            $venta->load('equipo');
            $invId = (int) ($venta->equipo->inventario_tienda_id ?? 0);
            if ($venta->equipo && $invId > 0) {
                $upd = ['cantidad' => DB::raw('cantidad + 1')];
                if (Schema::hasColumn('inventario_tiendas', 'estado'))        $upd['estado']           = 'DISPONIBLE';
                if (Schema::hasColumn('inventario_tiendas', 'fecha_venta'))   $upd['fecha_venta']      = null;
                if (Schema::hasColumn('inventario_tiendas', 'reporte_venta_id')) $upd['reporte_venta_id'] = null;
                DB::table('inventario_tiendas')->where('id', $invId)->update($upd);
            }

            VentaEquipo::where('venta_id', $venta->id)->delete();
            VentaLinea::where('venta_id', $venta->id)->delete();
            $venta->delete();

            $this->recalcularTotalesReporte($reporte);
            (new ComisionOperativaService())->recalcularReporte($reporte);
            DB::commit();
            $reporte->refresh()->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas']);
            return response()->json($reporte);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al eliminar la venta: ' . $e->getMessage()], 500);
        }
    }

    // ── PATCH /reportes/{reporte}/cabecera ────────────────────────────────────
    // Actualiza los campos de cabecera (yape, bipay, caja_inicial, etc.) sin tocar las ventas.
    // Lo llama "Guardar y Cerrar Caja" al final del turno.
    public function actualizarCabecera(Request $request, Reporte $reporte): JsonResponse
    {
        $this->autorizarPropietarioOAdmin($request, $reporte);
        if ($reporte->estado === 'aprobado') {
            return response()->json(['error' => 'No se puede modificar un reporte aprobado.'], 422);
        }

        $validated = $request->validate([
            'caja_inicial'       => 'nullable|numeric|min:0',
            'yape'               => 'nullable|numeric|min:0',
            'bipay'              => 'nullable|numeric|min:0',
            'transferencia'      => 'nullable|numeric|min:0',
            'retiro_bipay'       => 'nullable|numeric|min:0',
            'recarga_bipay'      => 'nullable|numeric|min:0',
            'pago_servicio'      => 'nullable|numeric|min:0',
            'pago_krece'         => 'nullable|numeric|min:0',
            'pago_payjoy'        => 'nullable|numeric|min:0',
            'tickets_tusamy'     => 'nullable|numeric|min:0',
            'efectivo_entregado' => 'nullable|numeric|min:0',
            'nombre_cubre'       => 'nullable|string|max:100',
            'observaciones'      => 'nullable|string',
            'obs_dia'            => 'nullable|string',
            'destino_efectivo'   => 'nullable|string|max:50',
            'salidas'            => 'nullable|array',
            'salidas.*.tipo'     => 'required_with:salidas|in:adelanto,gasto,pasaje,otro',
            'salidas.*.monto'    => 'required_with:salidas|numeric|gt:0',
            'salidas.*.observacion' => 'nullable|string|max:1000',
            'cerrar'             => 'nullable|boolean',
        ]);

        $campos = ['caja_inicial','yape','bipay','transferencia','retiro_bipay','recarga_bipay',
                   'pago_servicio','pago_krece','pago_payjoy','tickets_tusamy','efectivo_entregado',
                   'nombre_cubre','observaciones','obs_dia','destino_efectivo'];
        $update = [];
        foreach ($campos as $c) {
            if (array_key_exists($c, $validated)) {
                $update[$c] = $validated[$c] ?? 0;
            }
        }
        if (!empty($update)) $reporte->update($update);

        if ($request->has('salidas')) {
            $this->guardarSalidas($reporte, $validated['salidas'] ?? []);
        }

        $this->recalcularTotalesReporte($reporte);

        if ($validated['cerrar'] ?? false) {
            $reporte->update(['estado' => 'enviado']);
        }

        (new ComisionOperativaService())->recalcularReporte($reporte);
        $reporte->refresh()->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas']);
        return response()->json($reporte);
    }

    // ── Helper: recalcular totales del reporte a partir de sus ventas actuales ─
    private function recalcularTotalesReporte(Reporte $reporte): void
    {
        $reporte->refresh();
        $totalCalculado = (float) Venta::where('reporte_id', $reporte->id)->sum('monto_total');
        $totalSistema   = $totalCalculado
            + (float) $reporte->recarga_bipay
            + (float) $reporte->pago_servicio
            + (float) $reporte->pago_krece
            + (float) $reporte->pago_payjoy
            + (float) $reporte->tickets_tusamy;
        $totalNoFisico  = (float) $reporte->yape + (float) $reporte->bipay
            + (float) $reporte->transferencia + (float) $reporte->retiro_bipay;
        $totalSalidas   = (float) ReporteSalida::where('reporte_id', $reporte->id)->sum('monto');
        $efectivoEsperado = round($totalSistema - $totalNoFisico - $totalSalidas, 2);
        $diferencia       = round((float) $reporte->efectivo_entregado - $efectivoEsperado, 2);

        $reporte->update([
            'total_calculado'     => $totalCalculado,
            'total_salidas'       => $totalSalidas,
            'efectivo_esperado'   => $efectivoEsperado,
            'diferencia'          => $diferencia,
            'requiere_aprobacion' => abs($diferencia) > 10,
        ]);
    }

    private function autorizarPropietarioOAdmin(Request $request, Reporte $reporte): void
    {
        $user = $request->user();

        abort_unless(
            $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)
                || (int) $reporte->usuario_id === (int) $user->id,
            403,
            'No tienes permisos sobre este reporte.'
        );
    }

    public function fijarCosto(Request $request, int $ventaEquipoId): JsonResponse
    {
        $user = $request->user();
        if (! Permisos::puede($user, 'fijar_precios')) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        $validated = $request->validate([
            'precio_costo' => 'required|numeric|gt:0',
            'precio_venta' => 'nullable|numeric|min:0',
        ]);

        if ($ventaEquipoId <= 0) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos.'], 422);
        }

        $ventaEquipo = VentaEquipo::find($ventaEquipoId);

        if (! $ventaEquipo) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado.'], 404);
        }

        $precioCosto = round((float) $validated['precio_costo'], 2);
        $precioVenta = array_key_exists('precio_venta', $validated) && $validated['precio_venta'] !== null
            ? round((float) $validated['precio_venta'], 2)
            : (float) $ventaEquipo->precio_venta;
        $ganancia = round($precioVenta - $precioCosto, 2);

        $ventaEquipo->update([
            'precio_venta' => $precioVenta,
            'costo_snap' => $precioCosto,
            'ganancia_snap' => $ganancia,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'Costo actualizado correctamente.',
            'ganancia' => $ganancia,
            'costo'    => $precioCosto,
        ]);
    }
}
