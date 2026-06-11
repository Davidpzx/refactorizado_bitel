<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AsistenciaController extends Controller
{
    private function tablaExiste(): bool
    {
        return Schema::hasTable('asistencias');
    }

    // ── Haversine distance in meters ──────────────────────────────────────────
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    // ── Estado actual del agente ──────────────────────────────────────────────
    public function status(string $dni): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $agente = DB::table('agentes')->where('dni', $dni)->first();
        if (!$agente) {
            return response()->json(['error' => 'DNI no encontrado.'], 404);
        }

        $hoy        = now()->toDateString();
        $asistencia = DB::table('asistencias')
            ->where('agente_id', $agente->id)
            ->where('fecha', $hoy)
            ->first();

        $siguiente = 'entrada';
        if ($asistencia) {
            if (!$asistencia->hora_ingreso) {
                $siguiente = 'entrada';
            } elseif (empty($asistencia->omitio_refrigerio) && !$asistencia->inicio_refrigerio) {
                $siguiente = 'inicio_refrigerio';
            } elseif ($asistencia->inicio_refrigerio && !$asistencia->fin_refrigerio) {
                $siguiente = 'fin_refrigerio';
            } elseif (!$asistencia->hora_salida) {
                $siguiente = 'salida';
            } else {
                $siguiente = 'completado';
            }
        }

        return response()->json([
            'agente' => [
                'id'        => $agente->id,
                'nombre'    => $agente->nombres ?? $agente->nombre ?? '',
                'tienda_id' => $agente->tienda_id ?? $agente->tienda_base ?? null,
            ],
            'asistencia'           => $asistencia,
            'siguiente_marcacion'  => $siguiente,
            'fecha'                => $hoy,
        ]);
    }

    // ── Marcación por GPS ─────────────────────────────────────────────────────
    public function mark(Request $request): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $data = $request->validate([
            'dni'              => 'required|string',
            'tipo'             => ['required', Rule::in(['entrada', 'inicio_refrigerio', 'fin_refrigerio', 'salida'])],
            'lat'              => 'required|numeric',
            'lng'              => 'required|numeric',
            'accuracy'         => 'required|numeric|min:0',
            'device_id'        => 'nullable|string|max:64',
            'omitir_refrigerio'=> 'nullable|boolean',
        ]);

        $agente = DB::table('agentes')->where('dni', $data['dni'])->first();
        if (!$agente) return response()->json(['error' => 'DNI no encontrado.'], 404);

        // Verificar/registrar device fingerprint
        if (!empty($data['device_id'])) {
            $hashAlmacenado = $agente->hash_dispositivo ?? null;
            if ($hashAlmacenado && $hashAlmacenado !== $data['device_id']) {
                return response()->json([
                    'error' => 'Dispositivo no autorizado. Contacta a tu supervisor.',
                    'code'  => 'DEVICE_MISMATCH',
                ], 403);
            }
            if (!$hashAlmacenado) {
                DB::table('agentes')->where('id', $agente->id)
                    ->update(['hash_dispositivo' => $data['device_id']]);
            }
        }

        // Validar GPS vs ubicación de tienda
        $tiendaId = $agente->tienda_id ?? $agente->tienda_base ?? null;
        $tienda = $tiendaId ? DB::table('tiendas')
            ->where(function($q) use ($tiendaId) {
                $q->where('codigo', $tiendaId)->orWhere('id', $tiendaId);
            })->first() : null;

        $distancia = null;
        if ($tienda && !empty($tienda->lat_centro) && !empty($tienda->lng_centro)) {
            $distancia   = $this->haversine($data['lat'], $data['lng'], $tienda->lat_centro, $tienda->lng_centro);
            $radioPermitido = $tienda->radio_permitido ?? 60;
            $distEfectiva   = max(0, $distancia - $data['accuracy']);

            if ($distEfectiva > $radioPermitido) {
                return response()->json([
                    'error'     => "Estás a {$distancia}m de la tienda (máximo {$radioPermitido}m). Usa el QR del local.",
                    'code'      => 'OUT_OF_RANGE',
                    'distancia' => round($distancia),
                ], 422);
            }
        }

        return $this->procesarMarcacion($agente, $data['tipo'], 'GPS', [
            'lat_entrada'      => $data['lat'],
            'lng_entrada'      => $data['lng'],
            'accuracy_entrada' => $data['accuracy'],
            'distancia_entrada'=> $distancia ? round($distancia, 2) : null,
            'hora_intento_gps' => now()->toDateTimeString(),
            'omitio_refrigerio'=> $data['omitir_refrigerio'] ?? false,
        ]);
    }

    // ── Marcación por QR ──────────────────────────────────────────────────────
    public function markQr(Request $request): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $data = $request->validate([
            'dni'      => 'required|string',
            'tipo'     => ['required', Rule::in(['entrada', 'inicio_refrigerio', 'fin_refrigerio', 'salida'])],
            'qr_token' => 'required|string',
            'device_id'=> 'nullable|string|max:64',
        ]);

        $agente = DB::table('agentes')->where('dni', $data['dni'])->first();
        if (!$agente) return response()->json(['error' => 'DNI no encontrado.'], 404);

        // Parsear token: AST|TIENDA_ID|BLOQUE|HMAC
        $parts = explode('|', $data['qr_token']);
        if (count($parts) !== 4 || $parts[0] !== 'AST') {
            return response()->json(['error' => 'Token QR inválido.'], 422);
        }
        [, $tiendaToken, $bloqueToken, $hmacToken] = $parts;

        // Validar HMAC con ±2 bloques de 5 segundos
        $bloqueActual = (int) floor(time() / 5);
        $valido = false;
        for ($offset = -2; $offset <= 2; $offset++) {
            $bloque = $bloqueActual + $offset;
            $hmac = substr(hash_hmac('sha256', "AST|{$tiendaToken}|{$bloque}", config('app.key')), 0, 16);
            if (hash_equals($hmac, $hmacToken) && (string)$bloque === (string)$bloqueToken) {
                $valido = true;
                break;
            }
        }

        if (!$valido) {
            return response()->json(['error' => 'QR expirado o inválido. Escanea de nuevo.'], 422);
        }

        return $this->procesarMarcacion($agente, $data['tipo'], 'QR', [
            'tienda_ingreso' => $tiendaToken,
        ]);
    }

    // ── Marcación por Foto ────────────────────────────────────────────────────
    public function markPhoto(Request $request): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $data = $request->validate([
            'dni'       => 'required|string',
            'tipo'      => ['required', Rule::in(['entrada', 'inicio_refrigerio', 'fin_refrigerio', 'salida'])],
            'foto'      => 'required|string',
            'device_id' => 'nullable|string|max:64',
        ]);

        $agente = DB::table('agentes')->where('dni', $data['dni'])->first();
        if (!$agente) return response()->json(['error' => 'DNI no encontrado.'], 404);

        return $this->procesarMarcacion($agente, $data['tipo'], 'FOTO', [
            'foto_marcacion'   => $data['foto'],
            'requiere_revision'=> 1,
        ]);
    }

    // ── Core: crear/actualizar asistencia ─────────────────────────────────────
    private function procesarMarcacion(object $agente, string $tipo, string $metodo, array $extra = []): JsonResponse
    {
        $hoy        = now()->toDateString();
        $horaActual = now()->toTimeString();

        $asistencia = DB::table('asistencias')
            ->where('agente_id', $agente->id)
            ->where('fecha', $hoy)
            ->first();

        // Calcular tardanza (umbral: 09:00)
        $minutosTardanza = 0;
        if ($tipo === 'entrada') {
            $umbral = strtotime($hoy . ' 09:00:00');
            $ahora  = time();
            if ($ahora > $umbral) {
                $minutosTardanza = (int) round(($ahora - $umbral) / 60);
            }
        }

        $campos = array_merge([
            'metodo_marcacion' => $metodo,
        ], $extra);

        if ($tipo === 'entrada') {
            $campos['hora_ingreso']    = $horaActual;
            $campos['minutos_tardanza']= $minutosTardanza;
            $campos['estado_asistencia'] = $minutosTardanza > 0 ? 'TARDANZA' : 'REGULAR';
        } elseif ($tipo === 'inicio_refrigerio') {
            $campos['inicio_refrigerio'] = $horaActual;
        } elseif ($tipo === 'fin_refrigerio') {
            $campos['fin_refrigerio'] = $horaActual;
        } elseif ($tipo === 'salida') {
            $campos['hora_salida'] = $horaActual;
        }

        if (!$asistencia) {
            $id = DB::table('asistencias')->insertGetId(array_merge([
                'agente_id'  => $agente->id,
                'tienda_id'  => $agente->tienda_id ?? $agente->tienda_base ?? null,
                'fecha'      => $hoy,
                'created_at' => now(),
                'updated_at' => now(),
            ], $campos));
            $asistencia = DB::table('asistencias')->find($id);
        } else {
            DB::table('asistencias')
                ->where('id', $asistencia->id)
                ->update(array_merge($campos, ['updated_at' => now()]));
            $asistencia = DB::table('asistencias')->find($asistencia->id);
        }

        $siguiente = $this->siguienteMarcacion($asistencia);

        return response()->json([
            'message'             => 'Asistencia registrada correctamente.',
            'tipo'                => $tipo,
            'metodo'              => $metodo,
            'siguiente_marcacion' => $siguiente,
            'asistencia'          => $asistencia,
            'tardanza_minutos'    => $minutosTardanza,
        ], 200);
    }

    private function siguienteMarcacion(?object $a): string
    {
        if (!$a) return 'entrada';
        if (!$a->hora_ingreso) return 'entrada';
        if (empty($a->omitio_refrigerio) && !$a->inicio_refrigerio) return 'inicio_refrigerio';
        if ($a->inicio_refrigerio && !$a->fin_refrigerio) return 'fin_refrigerio';
        if (!$a->hora_salida) return 'salida';
        return 'completado';
    }

    // ── QR Stream para display en tienda ──────────────────────────────────────
    public function qrStream(string $tienda_id): JsonResponse
    {
        $bloque = (int) floor(time() / 5);
        $hmac   = substr(hash_hmac('sha256', "AST|{$tienda_id}|{$bloque}", config('app.key')), 0, 16);
        $token  = "AST|{$tienda_id}|{$bloque}|{$hmac}";
        $ttl    = 5 - (time() % 5);

        return response()->json([
            'token'       => $token,
            'tienda_id'   => $tienda_id,
            'expires_in'  => $ttl,
            'bloque'      => $bloque,
        ]);
    }

    // ── Fotos pendientes de revisión ──────────────────────────────────────────
    public function fotosPendientes(Request $request): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $rows = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->where('a.requiere_revision', 1)
            ->whereNotNull('a.foto_marcacion')
            ->select([
                'a.id', 'a.agente_id', 'a.fecha', 'a.hora_ingreso', 'a.metodo_marcacion',
                'a.foto_marcacion',
                'ag.nombres', 'ag.tienda_base',
            ])
            ->orderByDesc('a.fecha')
            ->paginate(20);

        return response()->json($rows);
    }

    // ── Aprobar/rechazar foto ──────────────────────────────────────────────────
    public function photoAction(Request $request, int $id): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        $data = $request->validate([
            'accion' => ['required', Rule::in(['aprobar', 'rechazar'])],
        ]);

        $asistencia = DB::table('asistencias')->where('id', $id)->first();
        if (!$asistencia) {
            return response()->json(['error' => 'Registro no encontrado.'], 404);
        }

        if ($data['accion'] === 'aprobar') {
            DB::table('asistencias')->where('id', $id)->update([
                'requiere_revision' => 0,
                'foto_marcacion'    => null,
                'updated_at'        => now(),
            ]);
            return response()->json(['message' => 'Foto aprobada y eliminada por política zero-retention.']);
        }

        DB::table('asistencias')->where('id', $id)->delete();
        return response()->json(['message' => 'Marcación rechazada y eliminada.']);
    }

    // ── Panel admin ───────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json([
                'warning' => 'La tabla asistencias no existe.',
                'data'    => [],
                'kpis'    => ['presentes' => 0, 'ausentes' => 0, 'tardanzas' => 0, 'pendientes_revision' => 0],
            ]);
        }

        $desde  = $request->get('fecha_desde', now()->toDateString());
        $hasta  = $request->get('fecha_hasta', now()->toDateString());
        $agente = $request->get('agente_id');

        $query = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->when($agente, fn($q) => $q->where('a.agente_id', $agente))
            ->select([
                'a.*',
                'ag.nombres',
                'ag.tienda_base',
                'ag.hora_salida as salida_oficial',
                'ag.dia_descanso',
            ])
            ->orderByDesc('a.fecha')
            ->orderByDesc('a.hora_ingreso');

        $kpis = DB::table('asistencias as a')
            ->join('agentes as ag2', 'ag2.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->when($agente, fn($q) => $q->where('a.agente_id', $agente))
            ->selectRaw("
                SUM(CASE WHEN a.hora_ingreso IS NOT NULL THEN 1 ELSE 0 END)  AS presentes,
                SUM(CASE WHEN a.hora_ingreso IS NULL THEN 1 ELSE 0 END)      AS ausentes,
                SUM(CASE WHEN a.minutos_tardanza > 0 THEN 1 ELSE 0 END)      AS tardanzas,
                SUM(CASE WHEN a.requiere_revision = 1 THEN 1 ELSE 0 END)     AS pendientes_revision
            ")
            ->first();

        $data = $query->paginate($request->integer('per_page', 30));

        return response()->json(['kpis' => $kpis, 'data' => $data]);
    }

    public function registrar(Request $request): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        $data = $request->validate([
            'agente_id'        => ['required', 'integer'],
            'fecha'            => ['required', 'date'],
            'hora_ingreso'     => ['nullable', 'string'],
            'hora_salida'      => ['nullable', 'string'],
            'tipo'             => ['nullable', 'string', Rule::in(['ENTRADA', 'SALIDA'])],
            'metodo_marcacion' => ['nullable', Rule::in(['MANUAL', 'FOTO', 'QR', 'GPS'])],
            'observacion'      => ['nullable', 'string', 'max:255'],
        ]);

        $existente = DB::table('asistencias')
            ->where('agente_id', $data['agente_id'])
            ->where('fecha', $data['fecha'])
            ->first();

        if ($existente) {
            if ($data['tipo'] === 'SALIDA' && !$existente->hora_salida) {
                DB::table('asistencias')
                    ->where('id', $existente->id)
                    ->update(['hora_salida' => $data['hora_salida'] ?? now()->toTimeString(), 'updated_at' => now()]);
                return response()->json(['message' => 'Salida registrada.', 'id' => $existente->id]);
            }
            return response()->json(['message' => 'Ya tiene asistencia registrada.', 'id' => $existente->id]);
        }

        $id = DB::table('asistencias')->insertGetId([
            'agente_id'        => $data['agente_id'],
            'fecha'            => $data['fecha'],
            'hora_ingreso'     => $data['hora_ingreso'] ?? now()->toTimeString(),
            'metodo_marcacion' => $data['metodo_marcacion'] ?? 'MANUAL',
            'observacion'      => $data['observacion'] ?? null,
            'requiere_revision'=> 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json(['message' => 'Asistencia registrada.', 'id' => $id], 201);
    }

    public function aprobar(Request $request, int $id): JsonResponse
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        DB::table('asistencias')->where('id', $id)->update(['requiere_revision' => 0, 'updated_at' => now()]);
        return response()->json(['message' => 'Asistencia aprobada.']);
    }

    public function exportar(Request $request)
    {
        if (!$this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        $desde  = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta  = $request->get('fecha_hasta', now()->toDateString());
        $agente = $request->get('agente_id');

        $rows = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->when($agente, fn($q) => $q->where('a.agente_id', $agente))
            ->select('a.fecha', 'ag.nombres', 'ag.tienda_base', 'a.hora_ingreso', 'a.hora_salida',
                'a.minutos_tardanza', 'a.metodo_marcacion', 'a.observacion')
            ->orderByDesc('a.fecha')
            ->get();

        $csv  = "\xEF\xBB\xBF";
        $csv .= "Fecha,Agente,Tienda,Ingreso,Salida,Min.Tardanza,Método,Observación\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row->fecha,
                '"' . str_replace('"', '""', $row->nombres ?? '') . '"',
                $row->tienda_base ?? '',
                $row->hora_ingreso ?? '',
                $row->hora_salida  ?? '',
                $row->minutos_tardanza ?? 0,
                $row->metodo_marcacion ?? 'MANUAL',
                '"' . str_replace('"', '""', $row->observacion ?? '') . '"',
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="asistencias_' . $desde . '_' . $hasta . '.csv"',
        ]);
    }

    // ── POST /attendance/salvavidas — Perdonar tardanza con descuento en refrigerio ──
    public function salvavidas(Request $request): JsonResponse
    {
        $asistenciaPasadaId = (int) $request->input('asistencia_id', 0);
        $minutos            = (int) $request->input('minutos', 0);

        if ($asistenciaPasadaId <= 0) {
            return response()->json(['success' => false, 'mensaje' => 'ID de asistencia inválido.']);
        }
        if ($minutos <= 0 || $minutos > 120) {
            return response()->json(['success' => false, 'mensaje' => "Los minutos deben estar entre 1 y 120. Valor recibido: {$minutos}."]);
        }

        // Identificar al agente por DNI
        $dni    = trim($request->input('dni', ''));
        $agente = DB::table('agentes')->where('dni', $dni)->first();
        if (!$agente) {
            return response()->json(['success' => false, 'mensaje' => 'Agente no encontrado.']);
        }

        $fechaHoy = now()->toDateString();

        try {
            DB::beginTransaction();

            $asistenciaPasada = DB::table('asistencias')
                ->where('id', $asistenciaPasadaId)
                ->lockForUpdate()
                ->first();

            if (!$asistenciaPasada) {
                throw new \Exception('El registro de asistencia no existe.');
            }

            // Solo tardanzas de esta semana
            $inicioSemana = now()->startOfWeek()->toDateString();
            $finSemana    = now()->endOfWeek()->toDateString();

            if ($asistenciaPasada->fecha < $inicioSemana || $asistenciaPasada->fecha > $finSemana) {
                throw new \Exception('Solo puedes recuperar tardanzas de la semana actual.');
            }

            // Verificar que no usó salvavidas esta semana
            $yaUso = DB::table('asistencias')
                ->where('agente_id', $asistenciaPasada->agente_id)
                ->whereBetween('fecha', [$inicioSemana, $finSemana])
                ->where('comodin_usado', 1)
                ->exists();

            if ($yaUso) {
                throw new \Exception('Ya utilizaste tu salvavidas esta semana. Solo se permite 1 uso por semana.');
            }

            // Asistencia de hoy
            $asistenciaHoy = DB::table('asistencias')
                ->where('agente_id', $asistenciaPasada->agente_id)
                ->where('fecha', $fechaHoy)
                ->lockForUpdate()
                ->first();

            if (!$asistenciaHoy) {
                throw new \Exception('Para usar el salvavidas, primero debes haber marcado tu INGRESO del día de HOY.');
            }
            if (!empty($asistenciaHoy->inicio_refrigerio)) {
                throw new \Exception('Ya saliste a refrigerio hoy. Usa el salvavidas ANTES de tu hora de almuerzo.');
            }

            // Perdonar tardanza del día pasado
            DB::table('asistencias')
                ->where('id', $asistenciaPasadaId)
                ->update(['minutos_tardanza' => 0]);

            // Aplicar descuento al día de hoy
            DB::table('asistencias')
                ->where('id', $asistenciaHoy->id)
                ->update(['comodin_usado' => 1, 'min_comodin' => $minutos]);

            DB::commit();

            $fechaFormateada = \Carbon\Carbon::parse($asistenciaPasada->fecha)->format('d/m/Y');
            return response()->json([
                'success' => true,
                'mensaje' => "¡Salvavidas aplicado! Se perdonó tu tardanza del {$fechaFormateada}. Hoy se descontarán {$minutos} minutos de tu refrigerio.",
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'mensaje' => $e->getMessage()]);
        }
    }

    // ── PATCH /asistencias/{id} — Edición admin de tiempos de una asistencia ──
    public function editar(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }

        $asistencia = DB::table('asistencias')->where('id', $id)->first();
        if (!$asistencia) {
            return response()->json(['success' => false, 'message' => 'Asistencia no encontrada.'], 404);
        }

        $update = [];
        foreach (['hora_ingreso', 'hora_salida', 'inicio_refrigerio', 'fin_refrigerio'] as $campo) {
            if ($request->has($campo)) {
                $update[$campo] = $request->input($campo) ?: null;
            }
        }
        if ($request->has('omitio_refrigerio')) {
            $update['omitio_refrigerio'] = $request->boolean('omitio_refrigerio') ? 1 : 0;
        }
        if ($request->has('observacion_admin')) {
            $update['observacion_admin'] = substr(trim($request->input('observacion_admin', '')), 0, 500);
        }

        if (!empty($update)) {
            $update['updated_at'] = now();
            DB::table('asistencias')->where('id', $id)->update($update);
        }

        return response()->json(['success' => true, 'message' => 'Asistencia actualizada correctamente.']);
    }
}
