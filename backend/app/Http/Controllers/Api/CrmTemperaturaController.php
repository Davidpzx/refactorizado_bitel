<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Crm\TemperaturaCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRM con temperatura calculada — paridad con gerencia/crm_dashboard.php del legacy sis_bipay.
 * Opera sobre crm_clientes/crm_interacciones (dominio propio, indexado por DNI), no sobre leads.estado.
 */
class CrmTemperaturaController extends Controller
{
    public function __construct(private readonly TemperaturaCalculator $calculador)
    {
    }

    /** GET /v1/crm/temperatura — feed de interacciones con la temperatura del cliente. */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('crm_interacciones as i')
            ->join('crm_clientes as c', 'c.id', '=', 'i.cliente_id')
            ->when($request->tienda_codigo, fn ($q, $v) => $q->where('i.tienda_codigo', $v))
            ->when($request->dni, fn ($q, $v) => $q->where('c.dni', $v))
            ->when($request->desde, fn ($q, $v) => $q->where('i.fecha_hora', '>=', $v))
            ->when($request->hasta, fn ($q, $v) => $q->where('i.fecha_hora', '<=', $v . ' 23:59:59'))
            ->orderByDesc('i.fecha_hora')
            ->select([
                'i.id', 'i.cliente_id', 'c.dni', 'c.nombres', 'c.apellidos', 'c.telefono',
                'i.tienda_codigo', 'i.agente_nombre', 'i.tipo_operacion',
                'i.producto_interes', 'i.motivo_rechazo', 'i.fecha_hora',
            ]);

        $perPage    = $request->integer('per_page', 50);
        $filtroTemp = $request->input('temperatura');

        // La temperatura es un campo calculado (no existe en la tabla), así que no se
        // puede filtrar en SQL antes de paginar. Si se pide ese filtro, resolvemos la
        // temperatura de TODOS los candidatos y paginamos la colección ya filtrada;
        // así total/current_page/per_page quedan consistentes con `data`. Sin el
        // filtro, seguimos paginando en SQL (más barato) como antes.
        if ($filtroTemp) {
            $page = $request->integer('page', 1);

            $filtrados = $this->conTemperatura(collect($query->get()))
                ->filter(fn ($row) => $row['temperatura']['etiqueta'] === $filtroTemp)
                ->values();

            $data = $filtrados->forPage($page, $perPage)->values();

            return response()->json([
                'data'         => $data,
                'total'        => $filtrados->count(),
                'current_page' => $page,
                'per_page'     => $perPage,
            ]);
        }

        $paginado = $query->paginate($perPage);
        $data     = $this->conTemperatura(collect($paginado->items()));

        return response()->json([
            'data'         => $data,
            'total'        => $paginado->total(),
            'current_page' => $paginado->currentPage(),
            'per_page'     => $paginado->perPage(),
        ]);
    }

    /** Anota cada fila con la temperatura de su DNI, calculando cada DNI único una sola vez. */
    private function conTemperatura(\Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
    {
        $temperaturas = [];

        return $rows->map(function ($row) use (&$temperaturas) {
            $row = (array) $row;
            if (! isset($temperaturas[$row['dni']])) {
                $temperaturas[$row['dni']] = $this->calculador->calcular($row['dni']);
            }
            $row['temperatura'] = $temperaturas[$row['dni']];
            return $row;
        });
    }

    /** GET /v1/crm/temperatura/{dni} — temperatura calculada de un solo cliente. */
    public function porDni(string $dni): JsonResponse
    {
        if (! preg_match('/^\d{8}$/', $dni)) {
            return response()->json(['error' => 'El DNI debe tener exactamente 8 dígitos numéricos.'], 422);
        }

        return response()->json($this->calculador->calcular($dni));
    }

    /**
     * GET /v1/crm/temperatura/exportar — paridad legacy gerencia/exportar_crm_excel.php
     * (mismos filtros que index(), sin paginación, workbook .xlsx en vez de CSV/JSON).
     */
    public function exportar(Request $request): StreamedResponse
    {
        $rows = DB::table('crm_interacciones as i')
            ->join('crm_clientes as c', 'c.id', '=', 'i.cliente_id')
            ->when($request->tienda_codigo, fn ($q, $v) => $q->where('i.tienda_codigo', $v))
            ->when($request->dni, fn ($q, $v) => $q->where('c.dni', $v))
            ->when($request->desde, fn ($q, $v) => $q->where('i.fecha_hora', '>=', $v))
            ->when($request->hasta, fn ($q, $v) => $q->where('i.fecha_hora', '<=', $v.' 23:59:59'))
            ->orderByDesc('i.fecha_hora')
            ->select([
                'i.id', 'i.cliente_id', 'c.dni', 'c.nombres', 'c.apellidos',
                'i.tienda_codigo', 'i.agente_nombre', 'i.tipo_operacion',
                'i.producto_interes', 'i.motivo_rechazo', 'i.fecha_hora',
            ])
            ->get();

        $filtroTemp = $request->input('temperatura');
        $anotadas   = $this->conTemperatura($rows);
        if ($filtroTemp) {
            $anotadas = $anotadas->filter(fn ($row) => $row['temperatura']['etiqueta'] === $filtroTemp)->values();
        }

        $clienteIds     = $anotadas->pluck('cliente_id')->unique()->values()->all();
        $ultimoContacto = empty($clienteIds) ? collect() : DB::table('crm_interacciones')
            ->whereIn('cliente_id', $clienteIds)
            ->groupBy('cliente_id')
            ->selectRaw('cliente_id, MAX(fecha_hora) as ultimo')
            ->pluck('ultimo', 'cliente_id');

        $spreadsheet = new Spreadsheet();
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('CRM');

        $hoja->setCellValue('A1', 'REGISTRO CRM — '.now()->format('d/m/Y H:i'));
        $hoja->mergeCells('A1:K1');
        $hoja->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $cabeceras = ['Fecha/Hora', 'Tienda', 'Agente', 'DNI', 'Nombres', 'Apellidos',
            'Temperatura', 'Producto', 'Rechazo', 'Operación', 'Último Contacto'];
        $hoja->fromArray($cabeceras, null, 'A2');
        $hoja->getStyle('A2:K2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $fila = 3;
        foreach ($anotadas as $r) {
            $uc = $ultimoContacto[$r['cliente_id']] ?? null;
            $hoja->fromArray([
                \Illuminate\Support\Carbon::parse($r['fecha_hora'])->format('d/m/Y H:i'),
                $r['tienda_codigo'],
                $r['agente_nombre'],
                $r['dni'],
                $r['nombres'],
                $r['apellidos'],
                $r['temperatura']['etiqueta'],
                $r['producto_interes'] ?? '',
                $r['motivo_rechazo'] ?? '',
                $r['tipo_operacion'],
                $uc ? $this->haceTiempo($uc) : '—',
            ], null, 'A'.$fila);
            $hoja->setCellValueExplicit('D'.$fila, (string) $r['dni'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $fila++;
        }

        foreach ($hoja->getColumnIterator() as $column) {
            $hoja->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }

        return response()->streamDownload(
            static function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'crm_export_'.now()->format('Y-m-d_His').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    private function haceTiempo(string $fecha): string
    {
        $segundos = now()->diffInSeconds(\Illuminate\Support\Carbon::parse($fecha));
        if ($segundos < 3600) return 'hace '.max(1, intdiv($segundos, 60)).' min';
        if ($segundos < 86400) return 'hace '.intdiv($segundos, 3600).'h';
        $dias = intdiv($segundos, 86400);
        return 'hace '.$dias.' día'.($dias === 1 ? '' : 's');
    }
}
