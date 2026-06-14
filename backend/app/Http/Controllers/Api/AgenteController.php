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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            ->select([
                'id', 'fecha', 'tienda_id', 'total_calculado',
                'efectivo_entregado', 'diferencia', 'estado',
            ])
            ->withCount('ventas')
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

    // ── GET /agentes/exportar-ficha — E2: ficha técnica multi-hoja (paridad exportar_excel_agentes_pro) ──
    // Hoja "Personal" (listado) + 1 hoja por agente con datos RRHH de postulantes_temp.
    public function exportarFichaTecnica(Request $request): StreamedResponse
    {
        $agentes = Agente::query()->orderBy('tienda_base')->orderBy('nombres')->get();
        $postulantes = Schema::hasTable('postulantes_temp')
            ? DB::table('postulantes_temp')->get()->keyBy(fn ($p) => trim((string) $p->dni))
            : collect();

        $ss = new Spreadsheet();
        $personal = $ss->getActiveSheet();
        $personal->setTitle('Personal');

        $cab = ['N°', 'DNI', 'NOMBRES', 'TIENDA', 'ESTADO', 'ROL', 'SUELDO BASE', 'INGRESO', 'SALIDA', 'DÍA LIBRE', 'FICHA'];
        foreach ($cab as $i => $h) {
            $personal->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $h);
        }
        $personal->getStyle('A1:K1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0F172A']],
        ]);

        $fila = 2;
        foreach ($agentes as $idx => $ag) {
            $dni = trim((string) ($ag->dni ?? ''));
            $hojaNombre = $this->nombreHojaAgente($ag);
            $vals = [
                $idx + 1, $dni, $ag->nombres ?? '', $ag->tienda_base ?? '', $ag->estado ?? '',
                ($ag->es_gerencia ?? false) ? 'JEFE DE TIENDA' : 'AGENTE',
                (float) ($ag->sueldo_base ?? 0), $ag->hora_ingreso ?? '', $ag->hora_salida ?? '', $ag->dia_descanso ?? '',
            ];
            foreach ($vals as $c => $v) {
                $personal->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $fila, $v);
            }
            $personal->setCellValue("K{$fila}", 'VER FICHA →');
            $personal->getCell("K{$fila}")->getHyperlink()->setUrl("sheet://'{$hojaNombre}'!A1");
            $personal->getStyle("K{$fila}")->getFont()->setUnderline(true)->getColor()->setARGB('FF2563EB');
            $fila++;

            $this->construirFichaAgente($ss, $ag, $postulantes->get($dni));
        }

        foreach (range('A', 'K') as $col) {
            $personal->getColumnDimension($col)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, 'ficha_tecnica_personal.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function nombreHojaAgente(object $ag): string
    {
        $base = preg_replace('/[^A-Za-z0-9 ]/', '', (string) ($ag->nombres ?? 'Agente'));

        return substr(substr(trim((string) $base), 0, 25) . '.' . ($ag->id ?? ''), 0, 31);
    }

    private function construirFichaAgente(Spreadsheet $ss, object $ag, ?object $p): void
    {
        $hoja = $ss->createSheet();
        $hoja->setTitle($this->nombreHojaAgente($ag));
        $r = 1;

        $seccion = function (string $titulo) use ($hoja, &$r) {
            $hoja->setCellValue("A{$r}", $titulo);
            $hoja->mergeCells("A{$r}:D{$r}");
            $hoja->getStyle("A{$r}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E293B']],
            ]);
            $r++;
        };
        $campo = function (string $k, $v) use ($hoja, &$r) {
            $hoja->setCellValue("A{$r}", $k);
            $hoja->setCellValue("B{$r}", (string) ($v ?? '—'));
            $hoja->getStyle("A{$r}")->getFont()->setBold(true);
            $r++;
        };

        $seccion('DATOS DEL EMPLEADO');
        $campo('DNI', $ag->dni ?? '');
        $campo('Nombres', $ag->nombres ?? '');
        $campo('Tienda', $ag->tienda_base ?? '');
        $campo('Estado', $ag->estado ?? '');
        $campo('Rol', ($ag->es_gerencia ?? false) ? 'Jefe de Tienda' : 'Agente');
        $campo('Sueldo Base', 'S/ ' . number_format((float) ($ag->sueldo_base ?? 0), 2));

        if ($p) {
            $seccion('DATOS DE CONTACTO');
            $campo('Teléfono', $p->telefono ?? '');
            $campo('Correo', $p->correo ?? '');
            $campo('Dirección', $p->direccion ?? '');
            $campo('Fecha Nacimiento', $p->fecha_nacimiento ?? '');
            $campo('Lugar Nacimiento', $p->lugar_nacimiento ?? '');
            $campo('Grupo Sanguíneo', $p->grupo_sanguineo ?? '');
            $campo('Alergias', $p->alergias ?? '');

            $seccion('SISTEMA PREVISIONAL');
            $campo('Sistema de Pensión', $p->sistema_pension ?? '');
            $campo('AFP', $p->nombre_afp ?? '');
            $campo('N° CUSPP', $p->numero_cuspp ?? '');

            $seccion('ANTECEDENTES');
            $campo('Penales', $p->antecedentes_penales ?? '');
            $campo('Policiales', $p->antecedentes_policial ?? '');
            $campo('Judiciales', $p->antecedentes_judicial ?? '');

            $this->seccionLista($hoja, $r, 'CARGA FAMILIAR', $p->carga_familiar ?? null, ['nombre', 'parentesco', 'edad']);
            $this->seccionLista($hoja, $r, 'FORMACIÓN ACADÉMICA', $p->formacion_academica ?? null, ['nivel', 'institucion', 'carrera', 'anio']);
            $this->seccionLista($hoja, $r, 'EXPERIENCIA LABORAL', $p->experiencia_laboral ?? null, ['empresa', 'cargo', 'desde', 'hasta']);
            $this->seccionLista($hoja, $r, 'CONTACTOS DE EMERGENCIA', $p->contactos_emergencia ?? null, ['nombre', 'parentesco', 'telefono']);

            $seccion('NOTAS ADMINISTRATIVAS');
            $campo('Notas', $p->notas_admin ?? '');
        }

        foreach (range('A', 'D') as $col) {
            $hoja->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function seccionLista(Worksheet $hoja, int &$r, string $titulo, mixed $json, array $keys): void
    {
        $hoja->setCellValue("A{$r}", $titulo);
        $hoja->mergeCells("A{$r}:D{$r}");
        $hoja->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF334155']],
        ]);
        $r++;

        $items = is_string($json) ? (json_decode($json, true) ?: []) : (is_array($json) ? $json : []);
        if (empty($items)) {
            $hoja->setCellValue("A{$r}", '— sin registros —');
            $r++;

            return;
        }
        foreach ($keys as $i => $k) {
            $hoja->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $r, strtoupper($k));
        }
        $hoja->getStyle('A' . $r . ':D' . $r)->getFont()->setBold(true);
        $r++;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach ($keys as $i => $k) {
                $hoja->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $r, (string) ($item[$k] ?? ''));
            }
            $r++;
        }
    }
}
