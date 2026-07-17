<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgenteRequest;
use App\Http\Requests\UpdateAgenteRequest;
use App\Models\Agente;
use App\Models\Reporte;
use App\Models\Usuario;
use App\Services\AgenteService;
use App\Services\HistorialAgenteService;
use App\Support\Paginacion;
use App\Support\Permisos;
use App\Support\TiendaGuard;
use App\Support\ResourceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function __construct(
        private readonly AgenteService $service,
        private readonly HistorialAgenteService $historial,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $agentes = Agente::query()
            ->when($request->q, fn($q, $t) => $q->buscar($t))
            ->when($request->tienda, fn($q, $t) => $q->porTienda($t))
            ->when($request->estado, fn($q, $e) => $q->where('estado', $e))
            ->orderBy('nombres')
            ->paginate(Paginacion::desde($request, 20));

        return response()->json($agentes);
    }

    public function show(Agente $agente): JsonResponse
    {
        $user = Auth::user();
        abort_if(
            TiendaGuard::bloqueaAcceso(
                $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE),
                $user->tienda_id,
                $agente->tienda_base
            ),
            403,
            'No tienes permisos sobre este agente.'
        );

        return response()->json($agente);
    }

    public function store(StoreAgenteRequest $request): JsonResponse
    {
        $agente = $this->service->crear($request->validated());
        ResourceCache::invalidate('reporte-vendedores');
        return response()->json($agente, 201);
    }

    public function update(UpdateAgenteRequest $request, Agente $agente): JsonResponse
    {
        $antes    = clone $agente; // snapshot de atributos previos para la auditoría
        $agente   = $this->service->actualizar($agente, $request->validated());
        $this->historial->auditarActualizacion($antes, $agente, $request->user()?->id);
        ResourceCache::invalidate('reporte-vendedores');

        return response()->json($agente);
    }

    // ── GET /agentes/{id}/historial — Auditoría de cambios del agente (admin) ────
    // Paridad legacy gerencia/ver_agente.php:1423-1436 (#modalHistorial), orden descendente.
    public function historial(int $id, Request $request): JsonResponse
    {
        if (! Agente::whereKey($id)->exists()) {
            return response()->json(['message' => 'Agente no encontrado.'], 404);
        }
        if (! Schema::hasTable('historial_agentes')) {
            return response()->json(['data' => []]);
        }

        $rows = DB::table('historial_agentes')
            ->where('id_agente', $id)
            ->orderByDesc('fecha_registro')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function destroy(Agente $agente): JsonResponse
    {
        if ($agente->reportes()->exists()) {
            return response()->json(['error' => 'No se puede eliminar: el agente tiene reportes asociados.'], 422);
        }
        $agente->delete();
        ResourceCache::invalidate('reporte-vendedores');
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
            ->paginate(Paginacion::desde($request, 20));

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
        // Anticorrupción (plan 16): token de emergencia/seguridad es SOLO admin/gerente.
        if (! Permisos::puede($user, 'modificar_asistencias')) {
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
        if (! Permisos::puede($user, 'gestionar_planilla')) {
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

    // ── GET /agentes/{id}/adelantos — Lista adelantos del agente (admin) ────────
    // Paridad legacy gerencia/ver_agente.php (lectura + total descontado en planilla).
    public function adelantos(int $id, Request $request): JsonResponse
    {
        if (! Schema::hasTable('adelantos')) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = DB::table('adelantos')->where('agente_id', $id);
        if ($request->filled('desde')) $query->whereDate('fecha', '>=', $request->input('desde'));
        if ($request->filled('hasta')) $query->whereDate('fecha', '<=', $request->input('hasta'));

        $rows  = $query->orderByDesc('fecha')->get();
        $total = (float) $rows->sum('monto');

        return response()->json(['data' => $rows, 'total' => round($total, 2)]);
    }

    // ── POST /agentes/{id}/adelantos — Registrar adelanto (admin) ───────────────
    public function registrarAdelanto(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! Permisos::puede($user, 'gestionar_planilla')) {
            return response()->json(['success' => false, 'msg' => 'Acceso denegado.'], 403);
        }

        $validated = $request->validate([
            'monto'  => 'required|numeric|gt:0',
            'fecha'  => 'nullable|date',
            'motivo' => 'nullable|string|max:255',
        ]);

        if (! Agente::whereKey($id)->exists()) {
            return response()->json(['success' => false, 'msg' => 'Agente no encontrado.'], 404);
        }
        if (! Schema::hasTable('adelantos')) {
            return response()->json(['success' => false, 'msg' => 'La tabla de adelantos no está disponible.'], 503);
        }

        $adelantoId = DB::table('adelantos')->insertGetId([
            'agente_id'  => $id,
            'fecha'      => $validated['fecha'] ?? now()->toDateString(),
            'monto'      => round((float) $validated['monto'], 2),
            'motivo'     => $validated['motivo'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'msg'     => 'Adelanto registrado correctamente.',
            'id'      => $adelantoId,
        ], 201);
    }

    // ── DELETE /agentes/{id}/adelantos/{adelantoId} — Eliminar adelanto (admin) ─
    public function eliminarAdelanto(int $id, int $adelantoId, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! Permisos::puede($user, 'gestionar_planilla')) {
            return response()->json(['success' => false, 'msg' => 'Acceso denegado.'], 403);
        }
        if (! Schema::hasTable('adelantos')) {
            return response()->json(['success' => false, 'msg' => 'No disponible.'], 503);
        }

        $deleted = DB::table('adelantos')->where('id', $adelantoId)->where('agente_id', $id)->delete();
        if (! $deleted) {
            return response()->json(['success' => false, 'msg' => 'Adelanto no encontrado.'], 404);
        }

        return response()->json(['success' => true, 'msg' => 'Adelanto eliminado.']);
    }

    public function perfilRrhh(int $id): JsonResponse
    {
        $agente = Agente::find($id);
        if (! $agente) {
            return response()->json(['message' => 'Agente no encontrado.'], 404);
        }

        $postulante = Schema::hasTable('postulantes_temp')
            ? DB::table('postulantes_temp')
                ->where('dni', $agente->dni)
                ->where('estado', 'APROBADO')
                ->orderByDesc('revisado_en')
                ->first()
            : null;

        $campos = [
            'telefono', 'correo', 'direccion', 'fecha_nacimiento', 'lugar_nacimiento',
            'grupo_sanguineo', 'alergias', 'sistema_pension', 'nombre_afp', 'numero_cuspp',
            'antecedentes_penales', 'antecedentes_policial', 'antecedentes_judicial',
            'contactos_emergencia', 'carga_familiar', 'formacion_academica', 'experiencia_laboral',
        ];

        $perfil = [
            'id' => $agente->id,
            'dni' => $agente->dni,
            'nombres' => $agente->nombres,
            'apellidos' => $agente->apellidos,
        ];
        foreach ($campos as $campo) {
            $valor = $agente->getAttribute($campo);
            if (($valor === null || $valor === '' || $valor === []) && $postulante) {
                $valor = $postulante->{$campo} ?? $valor;
            }
            if (in_array($campo, ['contactos_emergencia', 'carga_familiar', 'formacion_academica', 'experiencia_laboral'], true)) {
                $valor = $this->normalizarLista($valor);
            }
            $perfil[$campo] = $valor;
        }

        return response()->json(['data' => $perfil]);
    }

    public function actualizarPerfilRrhh(int $id, Request $request): JsonResponse
    {
        $agente = Agente::find($id);
        if (! $agente) {
            return response()->json(['message' => 'Agente no encontrado.'], 404);
        }

        $validated = $request->validate([
            'nombres' => 'sometimes|string|max:150',
            'apellidos' => 'nullable|string|max:150',
            'telefono' => 'nullable|string|max:15',
            'correo' => 'nullable|email|max:120',
            'direccion' => 'nullable|string|max:500',
            'fecha_nacimiento' => 'nullable|date',
            'lugar_nacimiento' => 'nullable|string|max:255',
            'grupo_sanguineo' => 'nullable|string|max:10',
            'alergias' => 'nullable|string|max:1000',
            'sistema_pension' => 'nullable|string|max:50',
            'nombre_afp' => 'nullable|string|max:50',
            'numero_cuspp' => 'nullable|string|max:50',
            'antecedentes_penales' => 'boolean',
            'antecedentes_policial' => 'boolean',
            'antecedentes_judicial' => 'boolean',
            'contactos_emergencia' => 'array|max:10',
            'carga_familiar' => 'array|max:20',
            'formacion_academica' => 'array|max:20',
            'experiencia_laboral' => 'array|max:20',
        ]);

        $antes = clone $agente; // snapshot para auditar cambios de ficha (FICHA)

        $columnasAgente = array_filter(
            $validated,
            fn ($value, $column) => Schema::hasColumn('agentes', $column),
            ARRAY_FILTER_USE_BOTH
        );
        $agente->update($columnasAgente);
        ResourceCache::invalidate('reporte-vendedores');

        $this->historial->auditarFicha($antes, $agente->fresh(), $request->user()?->id);

        if (Schema::hasTable('postulantes_temp')) {
            $columnasPostulante = array_filter(
                $validated,
                fn ($value, $column) => Schema::hasColumn('postulantes_temp', $column),
                ARRAY_FILTER_USE_BOTH
            );
            foreach (['contactos_emergencia', 'carga_familiar', 'formacion_academica', 'experiencia_laboral'] as $json) {
                if (array_key_exists($json, $columnasPostulante)) {
                    $columnasPostulante[$json] = json_encode($columnasPostulante[$json], JSON_UNESCAPED_UNICODE);
                }
            }
            if ($columnasPostulante) {
                DB::table('postulantes_temp')
                    ->where('dni', $agente->dni)
                    ->where('estado', 'APROBADO')
                    ->update($columnasPostulante);
            }
        }

        return response()->json(['message' => 'Ficha RRHH actualizada.', 'data' => $agente->fresh()]);
    }

    public function boletas(int $id, Request $request): JsonResponse
    {
        if (! Agente::whereKey($id)->exists()) {
            return response()->json(['message' => 'Agente no encontrado.'], 404);
        }
        if (! Schema::hasTable('pagos_planilla')) {
            return response()->json(['data' => []]);
        }

        $boletas = DB::table('pagos_planilla')
            ->where('agente_id', $id)
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha_inicio', '>=', $request->input('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha_fin', '<=', $request->input('hasta')))
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $boletas]);
    }

    public function estadoSeguridad(int $id): JsonResponse
    {
        $agente = Agente::find($id);
        if (! $agente) {
            return response()->json(['message' => 'Agente no encontrado.'], 404);
        }

        $expiracion = $agente->getAttribute('expiracion_token');
        $tieneToken = $agente->getAttribute('token_emergencia')
            && $expiracion
            && now()->lt(\Illuminate\Support\Carbon::parse($expiracion));

        return response()->json([
            'dispositivo_vinculado' => (bool) $agente->getAttribute('hash_dispositivo'),
            'fecha_registro_dispositivo' => $agente->getAttribute('fecha_registro_disp'),
            'tienda_registro_inicial' => $agente->getAttribute('tienda_registro_inicial'),
            'tiene_token' => (bool) $tieneToken,
            'token' => $tieneToken ? $agente->getAttribute('token_emergencia') : null,
            'tipo_token' => $tieneToken && str_starts_with((string) $expiracion, '2099') ? 'permanente' : ($tieneToken ? 'diario' : null),
            'expiracion_token' => $tieneToken ? $expiracion : null,
        ]);
    }

    public function resetDispositivo(int $id): JsonResponse
    {
        $agente = Agente::find($id);
        if (! $agente) {
            return response()->json(['message' => 'Agente no encontrado.'], 404);
        }

        $data = [];
        foreach (['hash_dispositivo', 'token_emergencia', 'expiracion_token', 'fecha_registro_disp', 'tienda_registro_inicial'] as $column) {
            if (Schema::hasColumn('agentes', $column)) {
                $data[$column] = null;
            }
        }
        DB::table('agentes')->where('id', $id)->update($data);

        return response()->json(['message' => 'Dispositivo desvinculado y token revocado.']);
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

        // Sección DATOS DE BAJA (paridad exportar_excel_agentes_pro.php:191-196)
        $fechaBaja = $ag->fecha_baja ?? null;
        if (($ag->clasificacion_baja ?? null) || ($ag->motivo_baja ?? null) || $fechaBaja) {
            $seccion('DATOS DE BAJA');
            $campo('Fecha Baja', $fechaBaja instanceof \DateTimeInterface ? $fechaBaja->format('Y-m-d') : ($fechaBaja ?? ''));
            $campo('Clasificación', $ag->clasificacion_baja ?? '');
            $campo('Motivo', $ag->motivo_baja ?? '');
            $campo('Observación', $ag->observacion ?? '');
        }

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

    private function normalizarLista(mixed $valor): array
    {
        if (is_array($valor)) {
            return $valor;
        }
        if (! is_string($valor) || trim($valor) === '') {
            return [];
        }

        $decoded = json_decode($valor, true);

        return is_array($decoded) ? $decoded : [];
    }
}
