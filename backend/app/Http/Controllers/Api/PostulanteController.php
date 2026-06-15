<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class PostulanteController extends Controller
{
    // ── POST /api/v1/postulaciones (público) ───────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $dni       = preg_replace('/\D/', '', trim($request->input('dni', '')));
        $nombres   = strtoupper(trim($request->input('nombres', '')));
        $apellidos = strtoupper(trim($request->input('apellidos', '')));

        if (strlen($dni) !== 8) {
            return response()->json(['success' => false, 'message' => 'El DNI debe tener exactamente 8 dígitos.'], 422);
        }
        if (!$nombres || !$apellidos) {
            return response()->json(['success' => false, 'message' => 'Nombres y apellidos son obligatorios.'], 422);
        }

        $yaExiste = DB::table('postulantes_temp')
            ->where('dni', $dni)
            ->where('estado', 'PENDIENTE')
            ->exists();

        if ($yaExiste) {
            return response()->json([
                'success' => false,
                'message' => 'Ya tienes un registro pendiente con ese DNI. Comunícate con la empresa.',
            ], 409);
        }

        $pension = in_array($request->input('sistema_pension'), ['AFP', 'ONP', 'NINGUNO'])
            ? $request->input('sistema_pension')
            : 'ONP';

        // Validar JSON de arrays dinámicos
        $cargaFamiliar    = $this->validarJson($request->input('carga_familiar', '[]'));
        $formacion        = $this->validarJson($request->input('formacion_academica', '[]'));
        $experiencia      = $this->validarJson($request->input('experiencia_laboral', '[]'));

        // Contactos de emergencia
        $contactos = [];
        for ($i = 1; $i <= 3; $i++) {
            $nombre = strtoupper(trim($request->input("contacto_nombre_{$i}", '')));
            $tel    = trim($request->input("contacto_telefono_{$i}", ''));
            $par    = trim($request->input("contacto_parentesco_{$i}", ''));
            if ($nombre || $tel) {
                $contactos[] = ['nombre' => $nombre, 'parentesco' => $par, 'telefono' => $tel];
            }
        }

        $id = DB::table('postulantes_temp')->insertGetId([
            'dni'                    => $dni,
            'nombres'                => $nombres,
            'apellidos'              => $apellidos,
            'telefono'               => $request->input('telefono') ?: null,
            'correo'                 => filter_var($request->input('correo', ''), FILTER_VALIDATE_EMAIL) ?: null,
            'fecha_nacimiento'       => $request->input('fecha_nacimiento') ?: null,
            'lugar_nacimiento'       => $request->input('lugar_nacimiento') ?: null,
            'direccion'              => $request->input('direccion') ?: null,
            'tienda_postulada'       => $request->input('tienda_postulada') ?: null,
            'carga_familiar'         => $cargaFamiliar,
            'formacion_academica'    => $formacion,
            'experiencia_laboral'    => $experiencia,
            'sistema_pension'        => $pension,
            'nombre_afp'             => $request->input('nombre_afp') ?: null,
            'numero_cuspp'           => $request->input('numero_cuspp') ?: null,
            'grupo_sanguineo'        => $request->input('grupo_sanguineo') ?: null,
            'alergias'               => $request->input('alergias') ?: null,
            'antecedentes_penales'   => (bool) $request->input('antecedentes_penales', false),
            'antecedentes_policial'  => (bool) $request->input('antecedentes_policial', false),
            'antecedentes_judicial'  => (bool) $request->input('antecedentes_judicial', false),
            'contactos_emergencia'   => json_encode($contactos, JSON_UNESCAPED_UNICODE),
            'estado'                 => 'PENDIENTE',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tu postulación fue enviada correctamente. Nos comunicaremos contigo pronto.',
            'id'      => $id,
        ]);
    }

    // ── GET /api/v1/postulaciones (admin) ──────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $query = DB::table('postulantes_temp')
            ->orderByDesc('created_at');

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('dni', 'like', "%{$s}%")
                  ->orWhere('nombres', 'like', "%{$s}%")
                  ->orWhere('apellidos', 'like', "%{$s}%");
            });
        }

        $perPage = min((int)($request->per_page ?? 30), 100);
        return response()->json($query->paginate($perPage));
    }

    // ── GET /api/v1/postulaciones/{id} (admin) ─────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $postulante = DB::table('postulantes_temp')->find($id);
        if (!$postulante) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json($postulante);
    }

    // ── PATCH /api/v1/postulaciones/{id} (admin) ───────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $allowed = ['PENDIENTE', 'APROBADO', 'RECHAZADO', 'ENTREVISTA'];
        $estado  = $request->input('estado');
        if ($estado && !in_array($estado, $allowed)) {
            return response()->json(['success' => false, 'message' => 'Estado inválido.'], 422);
        }

        DB::table('postulantes_temp')->where('id', $id)->update(array_filter([
            'estado'       => $estado,
            'notas_admin'  => $request->input('notas_admin'),
            'revisado_en'  => now(),
            'revisado_por' => $user->id,
            'updated_at'   => now(),
        ], fn($v) => $v !== null));

        return response()->json(['success' => true, 'message' => 'Postulante actualizado.']);
    }

    // ── POST /api/v1/postulaciones/{id}/aprobar (admin) ──────────────────────
    public function aprobar(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $validated = $request->validate([
            'pin_seguridad' => ['nullable', 'string', 'digits:4'],
            'tienda_base'   => ['nullable', 'string', 'max:20'],
            'sueldo_base'   => ['nullable', 'numeric', 'min:0'],
            'fecha_ingreso' => ['nullable', 'date'],
            'hora_ingreso'  => ['nullable', 'date_format:H:i'],
            'hora_salida'   => ['nullable', 'date_format:H:i'],
            'dia_descanso'  => ['nullable', 'string', 'max:20'],
            'dia_pago'      => ['nullable', 'integer', 'between:1,31'],
            'es_gerencia'   => ['nullable', 'boolean'],
        ]);

        $resultado = DB::transaction(function () use ($id, $user, $validated) {
            $postulante = DB::table('postulantes_temp')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$postulante) {
                return ['status' => 404, 'body' => ['message' => 'Postulante no encontrado.']];
            }

            if ($postulante->estado === 'APROBADO') {
                return ['status' => 409, 'body' => [
                    'success' => false,
                    'message' => 'El postulante ya fue aprobado.',
                ]];
            }

            if ($postulante->estado === 'RECHAZADO') {
                return ['status' => 422, 'body' => [
                    'success' => false,
                    'message' => 'No se puede aprobar un postulante rechazado.',
                ]];
            }

            if (DB::table('agentes')->where('dni', $postulante->dni)->exists()) {
                return ['status' => 409, 'body' => [
                    'success' => false,
                    'message' => 'Ya existe un agente registrado con ese DNI.',
                ]];
            }

            $columnasAgente = Schema::getColumnListing('agentes');
            $datosAgente = $this->datosAgenteDesdePostulante(
                $postulante,
                $validated,
                $columnasAgente
            );

            $agenteId = DB::table('agentes')->insertGetId($datosAgente);

            if (
                Schema::hasColumn('usuarios', 'agente_id')
                && (Schema::hasColumn('usuarios', 'dni') || ! empty($postulante->correo))
            ) {
                DB::table('usuarios')
                    ->whereNull('agente_id')
                    ->where(function ($query) use ($postulante) {
                        if (Schema::hasColumn('usuarios', 'dni')) {
                            $query->whereRaw('TRIM(dni) = ?', [trim((string) $postulante->dni)]);
                        }
                        if (! empty($postulante->correo)) {
                            $method = Schema::hasColumn('usuarios', 'dni') ? 'orWhereRaw' : 'whereRaw';
                            $query->{$method}(
                                'LOWER(TRIM(email)) = ?',
                                [mb_strtolower(trim((string) $postulante->correo))]
                            );
                        }
                    })
                    ->update(['agente_id' => $agenteId]);
            }

            DB::table('postulantes_temp')->where('id', $id)->update([
                'estado'       => 'APROBADO',
                'revisado_en'  => now(),
                'revisado_por' => $user->id,
                'updated_at'   => now(),
            ]);

            return ['status' => 201, 'body' => [
                'success'   => true,
                'message'   => 'Postulante aprobado y agente creado.',
                'agente_id' => $agenteId,
            ]];
        });

        return response()->json($resultado['body'], $resultado['status']);
    }

    // ── DELETE /api/v1/postulaciones/{id} (admin) ──────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        DB::table('postulantes_temp')->where('id', $id)->delete();
        return response()->json(['success' => true, 'message' => 'Registro eliminado.']);
    }

    // ── GET /api/v1/postulaciones/tiendas (público — para el formulario) ────────
    public function tiendas(): JsonResponse
    {
        $tiendas = DB::table('tiendas')
            ->orderBy('nombre')
            ->select('codigo', 'nombre')
            ->get();
        return response()->json(['data' => $tiendas]);
    }

    private function validarJson(mixed $val): string
    {
        if (is_array($val)) return json_encode($val, JSON_UNESCAPED_UNICODE);
        $decoded = json_decode((string)$val, true);
        return $decoded !== null ? $val : '[]';
    }

    private function datosAgenteDesdePostulante(
        object $postulante,
        array $validated,
        array $columnasAgente
    ): array {
        $columnas = array_flip($columnasAgente);
        $origen = get_object_vars($postulante);
        $excluidas = [
            'id', 'estado', 'notas_admin', 'revisado_en', 'revisado_por',
            'created_at', 'updated_at', 'tienda_postulada',
        ];

        $datos = [];
        foreach ($origen as $campo => $valor) {
            if (isset($columnas[$campo]) && !in_array($campo, $excluidas, true)) {
                $datos[$campo] = $valor;
            }
        }

        $nombreCompleto = trim($postulante->nombres . ' ' . $postulante->apellidos);
        $pin = $validated['pin_seguridad'] ?? substr((string) $postulante->dni, -4);

        $mapeos = [
            'dni'           => $postulante->dni,
            'nombres'       => $nombreCompleto,
            'tienda_base'   => $validated['tienda_base'] ?? $postulante->tienda_postulada,
            'pin_seguridad' => Hash::make($pin),
            'sueldo_base'   => $validated['sueldo_base'] ?? 0,
            'fecha_ingreso' => $validated['fecha_ingreso'] ?? now()->toDateString(),
            'hora_ingreso'  => $validated['hora_ingreso'] ?? null,
            'hora_salida'   => $validated['hora_salida'] ?? null,
            'dia_descanso'  => $validated['dia_descanso'] ?? null,
            'dia_pago'      => $validated['dia_pago'] ?? null,
            'es_gerencia'   => $validated['es_gerencia'] ?? false,
            'estado'        => 'ACTIVO',
            'creado_en'     => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        foreach ($mapeos as $campo => $valor) {
            if (isset($columnas[$campo])) {
                $datos[$campo] = $valor;
            }
        }

        return $datos;
    }
}
