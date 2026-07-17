<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agente;
use App\Models\Usuario;
use App\Services\HistorialAgenteService;
use App\Services\UserAgentResolver;
use App\Support\Paginacion;
use App\Support\Permisos;
use Carbon\Carbon;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AsistenciaController extends Controller
{
    public function __construct(
        private readonly UserAgentResolver $userAgentResolver,
        private readonly HistorialAgenteService $historial,
    ) {
    }

    private function tablaExiste(): bool
    {
        return Schema::hasTable('asistencias');
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return \App\Services\GeoService::haversine($lat1, $lng1, $lat2, $lng2);
    }

    public function status(Request $request, string $dni): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $agente = DB::table('agentes')->where('dni', $dni)->first();
        if (! $agente) {
            return response()->json(['error' => 'DNI no encontrado.'], 404);
        }

        $hoy = $this->ahora()->toDateString();
        $asistencia = DB::table('asistencias')
            ->where('agente_id', $agente->id)
            ->where('fecha', $hoy)
            ->first();

        return response()->json([
            'agente' => [
                'nombre' => $agente->nombres ?? $agente->nombre ?? '',
            ],
            'entrada' => ! empty($asistencia?->hora_ingreso),
        ]);
    }

    public function mark(Request $request): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $data = $request->validate([
            'dni' => ['required', 'string'],
            'tipo' => ['nullable', Rule::in($this->tiposAceptados())],
            'tienda_id' => ['nullable'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'device_hash' => ['nullable', 'string', 'max:128'],
            'token' => ['nullable', 'digits:6'],
            'omitir_refrigerio' => ['nullable', 'boolean'],
            'omitir_ref' => ['nullable', 'boolean'],
            'turno_extendido' => ['nullable', 'boolean'],
            'minutos_refrigerio_asignado' => ['nullable', 'integer', 'between:0,240'],
            'hora_intento_gps' => ['nullable', 'integer'],
            // APP-03: la app nativa reporta si detectó GPS falso (mock location). El
            // plugin web/oficial no puede detectarlo hoy — siempre llega false desde ahí;
            // solo la app Android (plugin propio, APP-02) puede mandar true de verdad.
            'mock_gps' => ['nullable', 'boolean'],
        ]);

        $agente = $this->buscarAgente($data['dni']);
        if (! $agente) {
            return response()->json(['error' => 'DNI no encontrado.'], 404);
        }

        if ($error = $this->validarAgenteActivo($agente)) {
            return $error;
        }

        $tienda = $this->resolverTienda($data['tienda_id'] ?? null, $agente);
        if (! $tienda) {
            return response()->json(['error' => 'Tienda no encontrada o no seleccionada.', 'code' => 'STORE_REQUIRED'], 422);
        }

        if ($error = $this->validarSeguridad(
            $agente,
            $data['device_id'] ?? $data['device_hash'] ?? '',
            $data['token'] ?? '',
            $this->identificadorTienda($tienda)
        )) {
            return $error;
        }
        $usaTokenEmergencia = trim((string) ($data['token'] ?? '')) !== '';

        // APP-03: GPS falso detectado por la app — no se acepta como marcación válida
        // aunque "caiga" dentro del rango (una posición falseada puede simular cualquier
        // coordenada). Se registra como intento fallido para el antifraude y se ofrecen
        // las alternativas (QR/foto/token), igual que ante GPS débil o fuera de rango.
        if (! $usaTokenEmergencia && ($data['mock_gps'] ?? false)) {
            $this->registrarIntentoFallido($agente, $data, 'MOCK_GPS');

            return response()->json([
                'error' => 'Se detectó una ubicación GPS simulada. Escanea el QR de la tienda.',
                'code' => 'MOCK_GPS',
                'qr_disponible' => true,
            ], 422);
        }

        $distancia = null;
        $latTienda = $this->valor($tienda, 'lat_centro', $this->valor($tienda, 'latitud'));
        $lngTienda = $this->valor($tienda, 'lng_centro', $this->valor($tienda, 'longitud'));
        $radioPermitido = max(10, (int) ($this->valor($tienda, 'radio_permitido', 60) ?: 60));

        if ($latTienda !== null && $lngTienda !== null) {
            if (! $usaTokenEmergencia && (float) $data['accuracy'] > $radioPermitido) {
                $this->registrarIntentoFallido($agente, $data, 'ACCURACY_DEBIL');

                return response()->json([
                    'error' => 'Tu GPS tiene muy mala señal. Escanea el QR de la tienda.',
                    'code' => 'WEAK_GPS',
                    'qr_disponible' => true,
                ], 422);
            }

            $distancia = $this->haversine(
                (float) $data['lat'],
                (float) $data['lng'],
                (float) $latTienda,
                (float) $lngTienda
            );
            $radioEfectivo = (float) $data['accuracy'] < 20 ? $radioPermitido * 0.8 : $radioPermitido;
            $distanciaEfectiva = max(0, $distancia - (float) $data['accuracy']);

            if (! $usaTokenEmergencia && $distanciaEfectiva > $radioEfectivo) {
                $this->registrarIntentoFallido($agente, $data, 'FUERA_RANGO', $distancia);

                return response()->json([
                    'error' => 'Estás fuera del rango de la sede. Asegúrate de estar dentro del local.',
                    'code' => 'OUT_OF_RANGE',
                    'distancia' => round($distancia),
                    'qr_disponible' => false,
                ], 422);
            }
        }

        return $this->procesarMarcacion($agente, $data['tipo'] ?? null, 'GPS', [
            'tienda_id' => $this->identificadorTienda($tienda),
            'lat' => (float) $data['lat'],
            'lng' => (float) $data['lng'],
            'accuracy' => (float) $data['accuracy'],
            'distancia' => $distancia !== null ? round($distancia, 2) : null,
            'hora_intento_gps' => $this->fechaDesdeMilisegundos($data['hora_intento_gps'] ?? null)?->toDateTimeString() ?? $this->ahora()->toDateTimeString(),
            'omitir_refrigerio' => (bool) ($data['omitir_refrigerio'] ?? $data['omitir_ref'] ?? false),
            'turno_extendido' => (bool) ($data['turno_extendido'] ?? false),
            'minutos_refrigerio_asignado' => $data['minutos_refrigerio_asignado'] ?? null,
        ]);
    }

    public function markQr(Request $request): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $data = $request->validate([
            'dni' => ['required', 'string'],
            'tipo' => ['nullable', Rule::in($this->tiposAceptados())],
            'qr_token' => ['required_without:qr_data', 'nullable', 'string'],
            'qr_data' => ['required_without:qr_token', 'nullable', 'string'],
            'tienda_id' => ['nullable'],
            'tienda_id_seleccionado' => ['nullable'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'device_hash' => ['nullable', 'string', 'max:128'],
            'token' => ['nullable', 'digits:6'],
            'omitir_refrigerio' => ['nullable', 'boolean'],
            'omitir_ref' => ['nullable', 'boolean'],
            'turno_extendido' => ['nullable', 'boolean'],
            'minutos_refrigerio_asignado' => ['nullable', 'integer', 'between:0,240'],
            'hora_intento_gps' => ['nullable', 'integer'],
            'hora_apertura_camera' => ['nullable', 'integer'],
        ]);

        $qrToken = trim((string) ($data['qr_token'] ?? $data['qr_data']));
        $parts = explode('|', $qrToken);
        if (count($parts) !== 4 || $parts[0] !== 'AST') {
            return response()->json(['error' => 'Token QR inválido.'], 422);
        }
        [, $tiendaToken, $bloqueToken, $hmacToken] = $parts;

        $bloqueActual = (int) floor($this->ahora()->timestamp / 5);
        $bloqueQr = filter_var($bloqueToken, FILTER_VALIDATE_INT);
        // SEC-14: 32 hex (128 bits) en vez de 16 (64 bits) — desplegar generación y
        // validación juntas (fuera de horario de marcado): un QR en vuelo con el largo
        // viejo deja de validar en el instante del deploy.
        $hmacEsperado = substr(hash_hmac('sha256', "AST|{$tiendaToken}|{$bloqueToken}", $this->qrSecret()), 0, 32);
        if ($bloqueQr === false || abs($bloqueActual - $bloqueQr) > 2 || ! hash_equals($hmacEsperado, strtolower($hmacToken))) {
            return response()->json(['error' => 'QR expirado o inválido. Escanea de nuevo.'], 422);
        }

        $agente = $this->buscarAgente($data['dni']);
        if (! $agente) {
            return response()->json(['error' => 'DNI no encontrado.'], 404);
        }
        if ($error = $this->validarAgenteActivo($agente)) {
            return $error;
        }

        $tiendaSeleccionada = $data['tienda_id'] ?? $data['tienda_id_seleccionado'] ?? $tiendaToken;
        $tienda = $this->buscarTienda($tiendaSeleccionada);
        if (! $tienda) {
            return response()->json(['error' => 'Sede seleccionada no encontrada.'], 422);
        }
        if (strtoupper($this->identificadorTienda($tienda)) !== strtoupper($tiendaToken)) {
            return response()->json([
                'error' => "El QR corresponde a la sede '{$tiendaToken}', pero seleccionaste otra sede.",
                'code' => 'QR_STORE_MISMATCH',
            ], 422);
        }

        if ($error = $this->validarSeguridad(
            $agente,
            $data['device_id'] ?? $data['device_hash'] ?? '',
            $data['token'] ?? '',
            $this->identificadorTienda($tienda)
        )) {
            return $error;
        }

        $momento = $this->ahora();
        $intento = $this->fechaDesdeMilisegundos($data['hora_intento_gps'] ?? null);
        $apertura = $this->fechaDesdeMilisegundos($data['hora_apertura_camera'] ?? $data['hora_intento_gps'] ?? null);
        if ($intento && $apertura && $apertura->diffInSeconds($this->ahora(), false) <= 60 && $apertura->isSameDay($this->ahora())) {
            $momento = $intento;
        }

        return DB::transaction(function () use ($agente, $data, $intento, $momento, $tienda) {
            $lock = DB::table('tiendas');
            if (isset($tienda->id)) {
                $lock->where('id', $tienda->id);
            } else {
                $lock->where('codigo', $this->identificadorTienda($tienda));
            }
            $lock->lockForUpdate()->first();

            if ($error = $this->validarCapacidadQr($tienda, $momento)) {
                return $error;
            }

            return $this->procesarMarcacion($agente, $data['tipo'] ?? null, 'QR', [
                'tienda_id' => $this->identificadorTienda($tienda),
                'hora_intento_gps' => $intento?->toDateTimeString(),
                'omitir_refrigerio' => (bool) ($data['omitir_refrigerio'] ?? $data['omitir_ref'] ?? false),
                'turno_extendido' => (bool) ($data['turno_extendido'] ?? false),
                'minutos_refrigerio_asignado' => $data['minutos_refrigerio_asignado'] ?? null,
            ], $momento);
        });
    }

    public function markPhoto(Request $request): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $data = $request->validate([
            'dni' => ['required', 'string'],
            'tipo' => ['nullable', Rule::in($this->tiposAceptados())],
            // SEC-13: base64 de una imagen; ~2048 KB en binario ≈ 2.73M chars en base64 (×4/3 + margen).
            'foto' => ['required', 'string', 'max:2796000'],
            'tienda_id' => ['nullable'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'device_hash' => ['nullable', 'string', 'max:128'],
            'token' => ['nullable', 'digits:6'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'omitir_refrigerio' => ['nullable', 'boolean'],
            'omitir_ref' => ['nullable', 'boolean'],
            'turno_extendido' => ['nullable', 'boolean'],
            'minutos_refrigerio_asignado' => ['nullable', 'integer', 'between:0,240'],
            'hora_intento_gps' => ['nullable', 'integer'],
        ]);

        $agente = $this->buscarAgente($data['dni']);
        if (! $agente) {
            return response()->json(['error' => 'DNI no encontrado.'], 404);
        }
        if ($error = $this->validarAgenteActivo($agente)) {
            return $error;
        }

        $tienda = $this->resolverTienda($data['tienda_id'] ?? null, $agente);
        if (! $tienda) {
            return response()->json(['error' => 'Tienda no encontrada o no seleccionada.', 'code' => 'STORE_REQUIRED'], 422);
        }

        if ($error = $this->validarSeguridad(
            $agente,
            $data['device_id'] ?? $data['device_hash'] ?? '',
            $data['token'] ?? '',
            $this->identificadorTienda($tienda)
        )) {
            return $error;
        }

        $foto = $this->normalizarFoto($data['foto']);
        if (! $foto) {
            return response()->json(['error' => 'La foto no es una imagen JPG o PNG válida de hasta 8 MB.'], 422);
        }

        return $this->procesarMarcacion($agente, $data['tipo'] ?? null, 'FOTO', [
            'tienda_id' => $this->identificadorTienda($tienda),
            'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
            'lng' => isset($data['lng']) ? (float) $data['lng'] : null,
            'accuracy' => isset($data['accuracy']) ? (float) $data['accuracy'] : null,
            'hora_intento_gps' => $this->fechaDesdeMilisegundos($data['hora_intento_gps'] ?? null)?->toDateTimeString(),
            'omitir_refrigerio' => (bool) ($data['omitir_refrigerio'] ?? $data['omitir_ref'] ?? false),
            'turno_extendido' => (bool) ($data['turno_extendido'] ?? false),
            'minutos_refrigerio_asignado' => $data['minutos_refrigerio_asignado'] ?? null,
            'foto_marcacion' => $foto,
            'requiere_revision' => 1,
        ]);
    }

    public function turnoCorrido(Request $request): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $data = $request->validate([
            'dni' => ['required', 'string'],
            'huella' => ['nullable', 'string'],
        ]);

        $agente = $this->buscarAgente($data['dni']);
        if (! $agente) {
            return response()->json(['error' => 'DNI no encontrado.'], 404);
        }
        if ($error = $this->validarAgenteActivo($agente)) {
            return $error;
        }

        $huella = trim((string) ($data['huella'] ?? ''));
        $columnaHash = str_starts_with($huella, 'dasam-face-') ? 'hash_facial' : 'hash_dispositivo';
        $hashEnrolado = trim((string) $this->valor($agente, $columnaHash, ''));
        if ($huella === '' || $hashEnrolado === '' || ! hash_equals($hashEnrolado, $huella)) {
            return response()->json([
                'error' => 'Dispositivo no autorizado.',
                'code' => 'DEVICE_MISMATCH',
            ], 403);
        }

        $hoy = $this->ahora()->toDateString();
        $asistencia = DB::table('asistencias')
            ->where('agente_id', $agente->id)
            ->whereDate('fecha', $hoy)
            ->first();

        if (! $asistencia || empty($asistencia->hora_ingreso)) {
            return response()->json(['error' => 'Sin entrada registrada hoy.'], 422);
        }
        if (! empty($asistencia->hora_salida)) {
            return response()->json(['error' => 'Ya registro salida.'], 422);
        }
        if (! empty($asistencia->inicio_refrigerio) || ! empty($asistencia->fin_refrigerio)) {
            return response()->json(['error' => 'Ya inicio el refrigerio.'], 422);
        }

        DB::table('asistencias')
            ->where('id', $asistencia->id)
            ->update([
                'omitio_refrigerio' => true,
                'updated_at' => $this->ahora(),
            ]);

        return response()->json([
            'message' => 'Turno corrido activado. Su proxima marcacion es la salida.',
            'omitio_refrigerio' => true,
            'siguiente_marcacion' => 'salida',
        ]);
    }

    public function liquidacionAsistencias(Request $request, int $id): JsonResponse
    {
        abort_if(! Permisos::puede($request->user(), 'gestionar_planilla'), 403);

        $agente = DB::table('agentes')->where('id', $id)->first();
        if (! $agente) {
            abort(404);
        }

        $data = $request->validate([
            'mes' => ['nullable', 'date_format:Y-m'],
        ]);
        $mes = $data['mes'] ?? $this->ahora()->format('Y-m');
        [$year, $month] = array_map('intval', explode('-', $mes));

        $asistencias = DB::table('asistencias')
            ->where('agente_id', $id)
            ->whereYear('fecha', $year)
            ->whereMonth('fecha', $month)
            ->orderBy('fecha')
            ->get();

        $valorMinuto = (float) config('attendance.valor_minuto_tardanza', 0.10);
        $dias = $asistencias->map(function (object $asistencia) use ($valorMinuto) {
            $tardanza = (int) ($asistencia->minutos_tardanza ?? 0);
            $deuda = (int) ($asistencia->minutos_deuda ?? 0);
            $comodin = (bool) ($asistencia->comodin_usado ?? false);
            $descuento = $comodin ? 0 : $deuda * $valorMinuto;

            return [
                'fecha' => $asistencia->fecha,
                'estado' => $asistencia->estado_asistencia ?? 'REGULAR',
                'hora_entrada' => $asistencia->hora_ingreso,
                'hora_salida' => $asistencia->hora_salida,
                'minutos_tardanza' => $tardanza,
                'minutos_deuda' => $deuda,
                'uso_comodin' => $comodin,
                'omitio_refrigerio' => (bool) ($asistencia->omitio_refrigerio ?? false),
                'descuento_soles' => round($descuento, 2),
            ];
        });

        return response()->json([
            'agente' => [
                'id' => (int) $agente->id,
                'nombre' => $agente->nombres ?? $agente->nombre ?? '',
                'dni' => $agente->dni,
            ],
            'mes' => $mes,
            'dias' => $dias->values(),
            'resumen' => [
                'total_tardanzas_min' => $dias->sum('minutos_tardanza'),
                'deuda_acumulada_min' => $dias->sum('minutos_deuda'),
                'comodines_usados' => $dias->where('uso_comodin', true)->count(),
                'total_descuento_soles' => round($dias->sum('descuento_soles'), 2),
            ],
        ]);
    }

    private function procesarMarcacion(
        object $agente,
        ?string $tipoSolicitado,
        string $metodo,
        array $contexto = [],
        ?Carbon $momento = null
    ): JsonResponse {
        $momento ??= $this->ahora();
        $momento = $momento->copy()->timezone($this->zonaHoraria());
        $hoy = $momento->toDateString();
        $horaActual = $momento->toTimeString();
        $tipoSolicitado = $this->normalizarTipo($tipoSolicitado);

        try {
            return DB::transaction(function () use (
                $agente,
                $metodo,
                $contexto,
                $momento,
                $hoy,
                $horaActual,
                $tipoSolicitado
            ) {
                $asistencia = DB::table('asistencias')
                    ->where('agente_id', $agente->id)
                    ->where('fecha', $hoy)
                    ->lockForUpdate()
                    ->first();

                $tipo = $this->siguienteMarcacion($asistencia, $agente);
                if ($tipo === 'completado') {
                    return response()->json([
                        'error' => 'Ya completaste todas tus marcaciones del día.',
                        'code' => 'DAY_COMPLETED',
                    ], 409);
                }
                if ($tipoSolicitado && $tipoSolicitado !== $tipo) {
                    return response()->json([
                        'error' => 'La marcación solicitada no corresponde al estado actual de la jornada.',
                        'code' => 'INVALID_SEQUENCE',
                        'siguiente_marcacion' => $tipo,
                    ], 409);
                }

                if ($mensaje = $this->validarIntervaloMinimo($tipo, $asistencia, $momento, $hoy)) {
                    return response()->json([
                        'error' => $mensaje,
                        'code' => 'TOO_SOON',
                        'siguiente_marcacion' => $tipo,
                    ], 422);
                }

                $tiendaId = (string) ($contexto['tienda_id'] ?? $agente->tienda_id ?? $agente->tienda_base ?? '');
                $campos = $this->camposAuditoria($tipo, $metodo, $contexto, $tiendaId);
                $minutosTardanzaMarcacion = 0;

                if ($tipo === 'entrada') {
                    $minutosTardanzaMarcacion = $this->calcularTardanzaEntrada($agente, $momento);
                    $turnoExtendido = (bool) ($contexto['turno_extendido'] ?? false) && ! $this->agenteTieneRefrigerio($agente);
                    $omiteRefrigerio = ! $turnoExtendido && (bool) ($contexto['omitir_refrigerio'] ?? false);

                    $campos = array_merge($campos, [
                        'hora_ingreso' => $horaActual,
                        'minutos_tardanza' => $minutosTardanzaMarcacion,
                        'estado_asistencia' => $minutosTardanzaMarcacion > 0 ? 'TARDANZA' : 'REGULAR',
                        'omitio_refrigerio' => $omiteRefrigerio ? 1 : 0,
                        'turno_extendido' => $turnoExtendido ? 1 : 0,
                        'minutos_refrigerio_asignado' => $turnoExtendido
                            ? (int) ($contexto['minutos_refrigerio_asignado'] ?? 60)
                            : null,
                        'metodo_marcacion' => $metodo,
                        'tienda_ingreso' => $tiendaId,
                    ]);

                    $id = DB::table('asistencias')->insertGetId(array_merge([
                        'agente_id' => $agente->id,
                        'tienda_id' => $tiendaId,
                        'fecha' => $hoy,
                        'created_at' => $momento,
                        'updated_at' => $momento,
                    ], $campos));
                } else {
                    if (! $asistencia) {
                        return response()->json(['error' => 'Primero debes registrar tu entrada.', 'code' => 'ENTRY_REQUIRED'], 409);
                    }

                    if ($tipo === 'inicio_refrigerio') {
                        $campos['inicio_refrigerio'] = $horaActual;
                    } elseif ($tipo === 'fin_refrigerio') {
                        $minutosTardanzaMarcacion = $this->calcularTardanzaRefrigerio($asistencia, $agente, $momento);
                        $campos['fin_refrigerio'] = $horaActual;
                        $campos['minutos_tardanza_refrigerio'] = (int) $this->valor($asistencia, 'minutos_tardanza_refrigerio', 0)
                            + $minutosTardanzaMarcacion;
                        $campos['minutos_tardanza'] = (int) $this->valor($asistencia, 'minutos_tardanza', 0)
                            + $minutosTardanzaMarcacion;
                        $campos['estado_asistencia'] = $campos['minutos_tardanza'] > 0 ? 'TARDANZA' : 'REGULAR';
                    } elseif ($tipo === 'salida') {
                        [$minutosDeuda, $minutosExtra] = $this->calcularDeudaYExtra($asistencia, $agente, $momento);
                        $campos['hora_salida'] = $horaActual;
                        $campos['minutos_deuda'] = $minutosDeuda;
                        $campos['minutos_extra'] = $minutosExtra;
                        $campos['estado_extra'] = 'PENDIENTE';
                        $this->recuperarDeudaDias($agente, $asistencia, $momento); // A2
                    }

                    // A1 — Anti-spoofing: salto imposible (>200 km/h desde la entrada) ⇒ a revisión.
                    if (Schema::hasColumn('asistencias', 'requiere_revision')
                        && $this->detectarSaltoImposible($asistencia, $contexto, $momento)) {
                        $campos['requiere_revision'] = 1;
                    }

                    $campos['updated_at'] = $momento;
                    $updated = DB::table('asistencias')
                        ->where('id', $asistencia->id)
                        ->whereNull($this->campoHoraPorTipo($tipo))
                        ->update($campos);

                    if ($updated === 0) {
                        return response()->json([
                            'error' => 'Esta marcación ya fue registrada previamente.',
                            'code' => 'DUPLICATE_MARK',
                        ], 409);
                    }
                    $id = $asistencia->id;

                    // APP-04 — al cerrar el turno se termina el rastreo de presencia:
                    // se borra la fila de estado (las incidencias NO se borran, son la evidencia).
                    if ($tipo === 'salida' && Schema::hasTable('asistencia_presencia')) {
                        DB::table('asistencia_presencia')->where('agente_id', $agente->id)->delete();
                    }
                }

                $asistenciaActualizada = DB::table('asistencias')->find($id);
                $siguiente = $this->siguienteMarcacion($asistenciaActualizada, $agente);

                return response()->json([
                    'message' => 'Asistencia registrada correctamente.',
                    'mensaje' => 'Asistencia registrada correctamente.',
                    'success' => true,
                    'status' => 'ok',
                    'tipo' => $tipo,
                    'tipo_legacy' => $this->tipoLegacy($tipo),
                    'metodo' => $metodo,
                    'siguiente_marcacion' => $siguiente,
                    'siguiente' => $this->tipoLegacy($siguiente),
                    'asistencia' => $asistenciaActualizada,
                    'tardanza_minutos' => $minutosTardanzaMarcacion,
                ]);
            }, 3);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                return response()->json([
                    'error' => 'La marcación ya fue registrada por otra solicitud.',
                    'code' => 'DUPLICATE_MARK',
                ], 409);
            }
            throw $e;
        }
    }

    private function siguienteMarcacion(?object $asistencia, object $agente): string
    {
        if (! $asistencia || empty($asistencia->hora_ingreso)) {
            return 'entrada';
        }
        if (! empty($asistencia->hora_salida)) {
            return 'completado';
        }

        $omiteRefrigerio = (bool) $this->valor($asistencia, 'omitio_refrigerio', false);
        $turnoExtendido = (bool) $this->valor($asistencia, 'turno_extendido', false);
        $sinRefrigerio = ! $this->agenteTieneRefrigerio($agente);

        if ($turnoExtendido) {
            $omiteRefrigerio = false;
            $sinRefrigerio = false;
        }
        if ($omiteRefrigerio || $sinRefrigerio) {
            return 'salida';
        }
        if (empty($asistencia->inicio_refrigerio)) {
            return 'inicio_refrigerio';
        }
        if (empty($asistencia->fin_refrigerio)) {
            return 'fin_refrigerio';
        }
        if (empty($asistencia->hora_salida)) {
            return 'salida';
        }

        return 'completado';
    }

    public function qrStream(Request $request, string $tienda_id): JsonResponse
    {
        $tienda = $this->buscarTienda($tienda_id);
        if (! $tienda) {
            return response()->json(['error' => 'Tienda no encontrada.'], 404);
        }

        $tiendaId = $this->identificadorTienda($tienda);
        $user = $request->user();
        if (! $user?->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)
            && strtoupper((string) $user?->tienda_id) !== strtoupper($tiendaId)) {
            abort(403, 'No puedes generar el QR de otra tienda.');
        }

        $ahora = $this->ahora();
        $bloque = (int) floor($ahora->timestamp / 5);
        // SEC-14: 32 hex (128 bits), en línea con la validación de arriba.
        $hmac = substr(hash_hmac('sha256', "AST|{$tiendaId}|{$bloque}", $this->qrSecret()), 0, 32);
        $token = "AST|{$tiendaId}|{$bloque}|{$hmac}";
        $ttl = 5 - ($ahora->timestamp % 5);
        $qr = new QrCode(
            data: $token,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
        );

        return response()->json([
            'token' => $token,
            'image_data_uri' => (new SvgWriter())->write($qr)->getDataUri(),
            'tienda_id' => $tiendaId,
            'expires_in' => $ttl,
            'bloque' => $bloque,
        ]);
    }

    private function qrSecret(): string
    {
        $secret = (string) config('attendance.qr_secret');
        if ($secret === '') {
            throw new \RuntimeException('QR_SECRET_KEY no está configurado.');
        }

        return $secret;
    }

    private function validarCapacidadQr(object $tienda, Carbon $momento): ?JsonResponse
    {
        $tiendaId = $this->identificadorTienda($tienda);
        $desde = $momento->copy()->subSeconds(10)->toTimeString();
        $totalAgentes = max(1, DB::table('agentes')
            ->where('tienda_base', $tiendaId)
            ->where('estado', 'ACTIVO')
            ->count());

        $escaneosRecientes = DB::table('asistencias')
            ->where('fecha', $momento->toDateString())
            ->where('tienda_id', $tiendaId)
            ->where(function ($query) use ($desde) {
                $query
                    ->where(fn ($q) => $q->where('metodo_marcacion', 'QR')->where('hora_ingreso', '>=', $desde))
                    ->orWhere(fn ($q) => $q->where('metodo_salida_refrigerio', 'QR')->where('inicio_refrigerio', '>=', $desde))
                    ->orWhere(fn ($q) => $q->where('metodo_entrada_refrigerio', 'QR')->where('fin_refrigerio', '>=', $desde))
                    ->orWhere(fn ($q) => $q->where('metodo_salida', 'QR')->where('hora_salida', '>=', $desde));
            })
            ->count();

        if ($escaneosRecientes < $totalAgentes) {
            return null;
        }

        return response()->json([
            'error' => 'Demasiados escaneos simultáneos en esta tienda. Espera unos segundos.',
            'code' => 'QR_COLLISION',
        ], 429);
    }

    private function buscarAgente(string $dni): ?object
    {
        return DB::table('agentes')->where('dni', trim($dni))->first();
    }

    private function validarAgenteActivo(object $agente): ?JsonResponse
    {
        $estado = strtoupper((string) $this->valor($agente, 'estado', 'ACTIVO'));
        $permisoLargo = (bool) $this->valor($agente, 'permiso_largo', false);
        $fechaRetorno = $this->valor($agente, 'fecha_retorno');

        if (
            $estado === 'INACTIVO'
            && $permisoLargo
            && $fechaRetorno
            && Carbon::parse($fechaRetorno, $this->zonaHoraria())->startOfDay()->lte($this->ahora()->startOfDay())
        ) {
            $this->reactivarPorPermisoLargoVencido($agente);

            return null;
        }

        if ($estado !== 'ACTIVO') {
            return response()->json([
                'error' => 'Tu usuario necesita revisión antes de marcar. Consulta con el encargado.',
                'code' => 'AGENT_INACTIVE',
            ], 403);
        }

        return null;
    }

    // Reactivación automática al marcar asistencia (paridad legacy procesar_asistencia.php,
    // api/registrar_marcacion.php y api/registrar_asistencia.php): solo agentes con permiso_largo
    // vencido, nunca un cese definitivo (permiso_largo=0 no entra por esta rama).
    private function reactivarPorPermisoLargoVencido(object $agente): void
    {
        $modelo = Agente::find($agente->id);
        if (! $modelo) {
            return;
        }

        $antes = clone $modelo;
        $modelo->update([
            'estado' => 'ACTIVO',
            'permiso_largo' => 0,
            'fecha_retorno' => null,
            'clasificacion_baja' => null,
            'motivo_baja' => null,
            'fecha_baja' => null,
            'observacion' => null,
        ]);
        $this->historial->auditarActualizacion($antes, $modelo, null);

        $agente->estado = 'ACTIVO';
    }

    private function validarSeguridad(object $agente, string $deviceId, string $token, string $tienda): ?JsonResponse
    {
        $deviceId = trim($deviceId);
        $token = trim($token);
        $usaToken = false;

        if ($token !== '') {
            $tokenDb = trim((string) $this->valor($agente, 'token_emergencia', ''));
            $expiracion = $this->valor($agente, 'expiracion_token');
            if (
                $tokenDb === ''
                || ! hash_equals($tokenDb, $token)
                || ! $expiracion
                || Carbon::parse($expiracion, $this->zonaHoraria())->lte($this->ahora())
            ) {
                return response()->json(['error' => 'Token inválido o expirado.', 'code' => 'INVALID_TOKEN'], 403);
            }
            $usaToken = true;
        }

        if ($deviceId === '') {
            return response()->json([
                'error' => 'Identificador de dispositivo requerido.',
                'code' => 'DEVICE_REQUIRED',
            ], 403);
        }

        // El sentinel dasam-sf- (bypass manual de fraude) solo puede originarse por edición directa
        // en BD por un admin — nunca aceptado como device_id enviado por el cliente, o un agente podría
        // autoplantarlo en su primer registro y desactivar el antifraude de dispositivo para siempre.
        if (str_starts_with($deviceId, 'dasam-sf-')) {
            return response()->json([
                'error' => 'Identificador de dispositivo inválido.',
                'code' => 'INVALID_DEVICE_ID',
            ], 422);
        }

        // Prefijo dasam-face-: hash de reconocimiento facial (dispositivo/app externa), vive en su
        // propia columna independiente de hash_dispositivo (paridad con verificarHashDispositivo() legacy).
        $esFacial = str_starts_with($deviceId, 'dasam-face-');
        $columnaHash = $esFacial ? 'hash_facial' : 'hash_dispositivo';
        $hashDb = trim((string) $this->valor($agente, $columnaHash, ''));

        if (! $usaToken && $hashDb !== '' && ! hash_equals($hashDb, $deviceId)) {
            if (str_starts_with($hashDb, 'dasam-sf-')) {
                // Sentinel dasam-sf-: bypass manual de fraude para este agente/columna. No bloquea,
                // no registra fraude y no re-vincula (el sentinel se mantiene tal cual en BD).
                return null;
            }

            $this->registrarFraudeDispositivo($agente, 'HASH_DISTINTO', $tienda);

            return response()->json([
                'error' => 'Dispositivo no autorizado. Usa tu celular registrado o solicita un token.',
                'code' => 'DEVICE_MISMATCH',
            ], 403);
        }

        // Una huella de dispositivo normal debe haber sido enrolada previamente mediante el flujo
        // autorizado por PIN. El hash facial, en cambio, llega verificado por el terminal facial
        // externo y su primer uso es precisamente el flujo de vinculacion a hash_facial.
        if (! $usaToken && $hashDb === '' && ! $esFacial) {
            $this->registrarFraudeDispositivo($agente, 'HASH_DISTINTO', $tienda);

            return response()->json([
                'error' => 'Dispositivo no autorizado. Usa tu celular registrado o solicita un token.',
                'code' => 'DEVICE_MISMATCH',
            ], 403);
        }

        // El re-vínculo tras token de emergencia siempre opera sobre hash_dispositivo, nunca sobre
        // hash_facial, aunque el device_hash recibido sea facial (asimetría legacy preservada).
        $columnaRelink = $usaToken ? 'hash_dispositivo' : $columnaHash;

        $duplicado = DB::table('agentes')
            ->where($columnaRelink, $deviceId)
            ->where('id', '!=', $agente->id)
            ->first();

        if ($duplicado && ! $usaToken) {
            $this->registrarFraudeDispositivo($agente, $duplicado->nombres ?? 'DISPOSITIVO_DUPLICADO', $tienda);

            return response()->json([
                'error' => 'Este dispositivo ya está registrado a nombre de otro agente.',
                'code' => 'DEVICE_DUPLICATE',
            ], 403);
        }

        DB::transaction(function () use ($agente, $deviceId, $tienda, $duplicado, $usaToken, $columnaRelink) {
            if ($duplicado && $usaToken) {
                DB::table('agentes')->where('id', $duplicado->id)->update([$columnaRelink => null]);
            }

            $update = [$columnaRelink => $deviceId];
            if (Schema::hasColumn('agentes', 'fecha_registro_disp')) {
                $update['fecha_registro_disp'] = $this->ahora();
            }
            if (Schema::hasColumn('agentes', 'tienda_registro_inicial')) {
                $update['tienda_registro_inicial'] = $tienda;
            }
            DB::table('agentes')->where('id', $agente->id)->update($update);
        });

        return null;
    }

    private function resolverTienda(mixed $tiendaId, object $agente): ?object
    {
        $tiendaId = $tiendaId ?: $this->valor($agente, 'tienda_id', $this->valor($agente, 'tienda_base'));

        return $this->buscarTienda($tiendaId);
    }

    private function buscarTienda(mixed $tiendaId): ?object
    {
        if ($tiendaId === null || $tiendaId === '' || ! Schema::hasTable('tiendas')) {
            return null;
        }

        $query = DB::table('tiendas');
        if (Schema::hasColumn('tiendas', 'codigo') && Schema::hasColumn('tiendas', 'id')) {
            return $query->where(function ($q) use ($tiendaId) {
                $q->where('codigo', $tiendaId)->orWhere('id', $tiendaId);
            })->first();
        }
        if (Schema::hasColumn('tiendas', 'codigo')) {
            return $query->where('codigo', $tiendaId)->first();
        }
        if (Schema::hasColumn('tiendas', 'id')) {
            return $query->where('id', $tiendaId)->first();
        }

        return null;
    }

    private function identificadorTienda(object $tienda): string
    {
        return (string) $this->valor($tienda, 'codigo', $this->valor($tienda, 'id', ''));
    }

    private function agenteTieneRefrigerio(object $agente): bool
    {
        $inicio = $this->valor($agente, 'hora_ref_inicio');

        return ! empty($inicio) && $inicio !== '00:00:00';
    }

    private function calcularTardanzaEntrada(object $agente, Carbon $momento): int
    {
        if ($this->esDiaDescanso($agente, $momento)) {
            return 0;
        }

        $horaIngreso = $this->valor($agente, 'hora_ingreso', '09:00:00') ?: '09:00:00';
        $oficial = Carbon::parse($momento->toDateString().' '.$horaIngreso, $momento->timezone);

        return $momento->gt($oficial) ? (int) floor($oficial->diffInSeconds($momento) / 60) : 0;
    }

    private function calcularTardanzaRefrigerio(object $asistencia, object $agente, Carbon $momento): int
    {
        if (empty($asistencia->inicio_refrigerio)) {
            return 0;
        }

        $inicio = Carbon::parse($momento->toDateString().' '.$asistencia->inicio_refrigerio, $momento->timezone);
        $duracion = max(0, (int) floor($inicio->diffInSeconds($momento) / 60));
        $permitidos = $this->minutosRefrigerioPermitidos($asistencia, $agente);

        return max(0, $duracion - $permitidos);
    }

    private function minutosRefrigerioPermitidos(object $asistencia, object $agente): int
    {
        if ((bool) $this->valor($asistencia, 'turno_extendido', false)) {
            return max(0, (int) $this->valor($asistencia, 'minutos_refrigerio_asignado', 0));
        }

        $minutos = 120;
        $inicio = $this->valor($agente, 'hora_ref_inicio');
        $fin = $this->valor($agente, 'hora_ref_fin');
        if ($inicio && $fin && $inicio !== '00:00:00' && $fin !== '00:00:00') {
            $minutos = max(0, (int) floor((strtotime($fin) - strtotime($inicio)) / 60));
        }

        if ((bool) $this->valor($asistencia, 'comodin_usado', false)) {
            $minutos = max(30, $minutos - (int) $this->valor($asistencia, 'min_comodin', 0));
        }

        return $minutos;
    }

    private function calcularDeudaYExtra(object $asistencia, object $agente, Carbon $momento): array
    {
        $entrada = Carbon::parse($momento->toDateString().' '.$asistencia->hora_ingreso, $momento->timezone);
        $minutosDeuda = 0;
        $minutosExtra = 0;

        if ($this->esDiaDescanso($agente, $momento)) {
            $minutosExtra = max(0, (int) round($entrada->diffInSeconds($momento) / 60));
            if (! empty($asistencia->inicio_refrigerio) && ! empty($asistencia->fin_refrigerio)) {
                $inicioRef = Carbon::parse($momento->toDateString().' '.$asistencia->inicio_refrigerio, $momento->timezone);
                $finRef = Carbon::parse($momento->toDateString().' '.$asistencia->fin_refrigerio, $momento->timezone);
                $minutosExtra -= max(0, (int) round($inicioRef->diffInSeconds($finRef) / 60));
            }
        } else {
            $salidaOficial = $this->valor($agente, 'hora_salida');
            if ($salidaOficial) {
                $oficial = Carbon::parse($momento->toDateString().' '.$salidaOficial, $momento->timezone);
                $diferencia = (int) round($oficial->diffInSeconds($momento, false) / 60);
                if ($diferencia < 0) {
                    $minutosDeuda = abs($diferencia);
                } else {
                    $minutosExtra += $diferencia;
                }
            }

            $ingresoOficial = $this->valor($agente, 'hora_ingreso');
            if ($ingresoOficial) {
                $oficial = Carbon::parse($momento->toDateString().' '.$ingresoOficial, $momento->timezone);
                if ($entrada->lt($oficial)) {
                    $minutosExtra += (int) round($entrada->diffInSeconds($oficial) / 60);
                }
            }
        }

        return [$minutosDeuda, $minutosExtra >= 60 ? $minutosExtra : 0];
    }

    // A1 — Anti-spoofing "salto imposible" (paridad legacy registrar_marcacion.php):
    // velocidad entre el punto de entrada y la marcación actual; si supera 200 km/h, a revisión.
    private function detectarSaltoImposible(?object $asistencia, array $contexto, Carbon $momento): bool
    {
        if (! $asistencia || ! isset($contexto['lat'], $contexto['lng'])) {
            return false;
        }
        $latPrev = $this->valor($asistencia, 'lat_entrada');
        $lngPrev = $this->valor($asistencia, 'lng_entrada');
        $horaPrev = $this->valor($asistencia, 'hora_ingreso');
        if ($latPrev === null || $lngPrev === null || empty($horaPrev)) {
            return false;
        }

        $metros = $this->haversine((float) $latPrev, (float) $lngPrev, (float) $contexto['lat'], (float) $contexto['lng']);
        $prev = Carbon::parse($momento->toDateString().' '.$horaPrev, $momento->timezone);
        $segundos = max(1, $prev->diffInSeconds($momento));

        return (($metros / 1000) / ($segundos / 3600)) > 200;
    }

    // A2 — Recuperación inteligente del banco de deudas (paridad legacy procesar_asistencia.php 447-470):
    // al marcar salida, si el agente tiene deuda_dias y trabajó en su día de descanso
    // o acumuló ≥1.5 jornadas, se le descuentan días de la deuda.
    private function recuperarDeudaDias(object $agente, object $asistencia, Carbon $momento): void
    {
        $deuda = (int) $this->valor($agente, 'deuda_dias', 0);
        if ($deuda <= 0 || empty($asistencia->hora_ingreso)) {
            return;
        }

        $ingreso = Carbon::parse($momento->toDateString().' '.$asistencia->hora_ingreso, $momento->timezone);
        $horasEfectivas = max(0, $ingreso->diffInMinutes($momento) / 60);

        if (! empty($asistencia->inicio_refrigerio) && ! empty($asistencia->fin_refrigerio)) {
            $ri = Carbon::parse($momento->toDateString().' '.$asistencia->inicio_refrigerio, $momento->timezone);
            $rf = Carbon::parse($momento->toDateString().' '.$asistencia->fin_refrigerio, $momento->timezone);
            $horasEfectivas -= max(0, $ri->diffInMinutes($rf) / 60);
        }

        $jornadaNormal = $this->agenteTieneRefrigerio($agente) ? 9 : 6;
        $diasRecuperados = min((int) floor($horasEfectivas / $jornadaNormal), $deuda);
        $trabajoHorasExtra = $horasEfectivas >= ($jornadaNormal * 1.5);

        if (($this->esDiaDescanso($agente, $momento) || $trabajoHorasExtra) && $diasRecuperados > 0) {
            DB::table('agentes')->where('id', $agente->id)
                ->update(['deuda_dias' => DB::raw("GREATEST(deuda_dias - {$diasRecuperados}, 0)")]);
        }
    }

    private function validarIntervaloMinimo(string $tipo, ?object $asistencia, Carbon $momento, string $fecha): ?string
    {
        if (! $asistencia) {
            return null;
        }

        $referencia = null;
        $minimo = 5;
        if ($tipo === 'inicio_refrigerio') {
            $referencia = $asistencia->hora_ingreso;
            $minimo = 60;
        } elseif ($tipo === 'fin_refrigerio') {
            $referencia = $asistencia->inicio_refrigerio;
        } elseif ($tipo === 'salida') {
            $referencia = $asistencia->fin_refrigerio ?: $asistencia->hora_ingreso;
        }

        if (! $referencia) {
            return null;
        }

        $ultima = Carbon::parse($fecha.' '.$referencia, $momento->timezone);
        $transcurridos = (int) floor($ultima->diffInSeconds($momento, false) / 60);
        if ($transcurridos >= $minimo) {
            return null;
        }

        $faltan = max(1, $minimo - max(0, $transcurridos));

        return "Debes esperar al menos {$minimo} minutos antes de esta marcación. Faltan {$faltan} min.";
    }

    private function camposAuditoria(string $tipo, string $metodo, array $contexto, string $tiendaId): array
    {
        $campos = [];
        $mapa = [
            'entrada' => ['lat_entrada', 'lng_entrada', 'accuracy_entrada', 'distancia_entrada', 'tienda_ingreso', 'metodo_marcacion'],
            'inicio_refrigerio' => ['lat_salida_refrigerio', 'lng_salida_refrigerio', 'accuracy_salida_refrigerio', 'distancia_salida_refrigerio', 'tienda_inicio_ref', 'metodo_salida_refrigerio'],
            'fin_refrigerio' => ['lat_entrada_refrigerio', 'lng_entrada_refrigerio', 'accuracy_entrada_refrigerio', 'distancia_entrada_refrigerio', 'tienda_fin_ref', 'metodo_entrada_refrigerio'],
            'salida' => ['lat_salida', 'lng_salida', 'accuracy_salida', 'distancia_salida', 'tienda_salida', 'metodo_salida'],
        ];
        [$lat, $lng, $accuracy, $distancia, $tienda, $campoMetodo] = $mapa[$tipo];

        foreach ([
            $lat => $contexto['lat'] ?? null,
            $lng => $contexto['lng'] ?? null,
            $accuracy => $contexto['accuracy'] ?? null,
            $distancia => $contexto['distancia'] ?? null,
        ] as $campo => $valor) {
            if ($valor !== null && Schema::hasColumn('asistencias', $campo)) {
                $campos[$campo] = $valor;
            }
        }
        if (Schema::hasColumn('asistencias', $tienda)) {
            $campos[$tienda] = $tiendaId;
        }
        if (Schema::hasColumn('asistencias', $campoMetodo)) {
            $campos[$campoMetodo] = $metodo;
        }
        if (! empty($contexto['hora_intento_gps']) && Schema::hasColumn('asistencias', 'hora_intento_gps')) {
            $campos['hora_intento_gps'] = $contexto['hora_intento_gps'];
        }
        if (! empty($contexto['requiere_revision'])) {
            $campos['requiere_revision'] = 1;
        }
        if (! empty($contexto['foto_marcacion'])) {
            $campos['foto_marcacion'] = $contexto['foto_marcacion'];
        }

        return $campos;
    }

    private function campoHoraPorTipo(string $tipo): string
    {
        return match ($tipo) {
            'inicio_refrigerio' => 'inicio_refrigerio',
            'fin_refrigerio' => 'fin_refrigerio',
            'salida' => 'hora_salida',
            default => 'hora_ingreso',
        };
    }

    private function normalizarFoto(string $foto): ?string
    {
        $foto = trim($foto);
        $base64 = $foto;
        if (preg_match('#^data:(image/(?:jpeg|png));base64,(.+)$#is', $foto, $matches)) {
            $base64 = $matches[2];
        }

        $binario = base64_decode($base64, true);
        if ($binario === false || $binario === '' || strlen($binario) > 8 * 1024 * 1024) {
            return null;
        }
        $info = @getimagesizefromstring($binario);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }

        $mime = $info['mime'];
        if (function_exists('imagecreatefromstring')) {
            $imagen = @imagecreatefromstring($binario);
            if ($imagen !== false) {
                $ancho = imagesx($imagen);
                $alto = imagesy($imagen);
                if ($ancho > 1024 || $alto > 1024) {
                    $ratio = min(1024 / $ancho, 1024 / $alto);
                    $redimensionada = imagecreatetruecolor((int) round($ancho * $ratio), (int) round($alto * $ratio));
                    imagecopyresampled(
                        $redimensionada,
                        $imagen,
                        0,
                        0,
                        0,
                        0,
                        imagesx($redimensionada),
                        imagesy($redimensionada),
                        $ancho,
                        $alto
                    );
                    imagedestroy($imagen);
                    $imagen = $redimensionada;
                }

                foreach ([75, 65, 55, 45, 35] as $calidad) {
                    ob_start();
                    imagejpeg($imagen, null, $calidad);
                    $jpeg = ob_get_clean();
                    if (strlen($jpeg) <= 153600 || $calidad === 35) {
                        $binario = $jpeg;
                        $mime = 'image/jpeg';
                        break;
                    }
                }
                imagedestroy($imagen);
            }
        }

        return "data:{$mime};base64,".base64_encode($binario);
    }

    private function registrarIntentoFallido(object $agente, array $data, string $motivo, ?float $distancia = null): void
    {
        if (! Schema::hasTable('asistencia_intentos_fallidos')) {
            return;
        }

        DB::table('asistencia_intentos_fallidos')->insert([
            'agente_id' => $agente->id,
            'tipo_marcacion' => $this->normalizarTipo($data['tipo'] ?? null) ?? 'desconocido',
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'distancia' => $distancia,
            'motivo' => $motivo,
            'fecha' => $this->ahora(),
        ]);
    }

    private function registrarFraudeDispositivo(object $agente, string $duenio, string $tienda): void
    {
        if (! Schema::hasTable('log_fraude_dispositivo')) {
            return;
        }

        DB::table('log_fraude_dispositivo')->insert([
            'fecha_hora' => $this->ahora(),
            'agente_id' => $agente->id,
            'nombre_agente' => $agente->nombres ?? '',
            'dni_ingresado' => $agente->dni ?? '',
            'dni_duenio_hash' => $duenio,
            'tienda_intento' => $tienda,
        ]);
    }

    private function esDiaDescanso(object $agente, Carbon $momento): bool
    {
        $dias = ['DOMINGO', 'LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO'];

        return strtoupper((string) $this->valor($agente, 'dia_descanso', '')) === $dias[$momento->dayOfWeek];
    }

    private function normalizarTipo(?string $tipo): ?string
    {
        return match ($tipo) {
            'salida_refrigerio' => 'inicio_refrigerio',
            'entrada_refrigerio' => 'fin_refrigerio',
            'finalizado' => 'completado',
            default => $tipo,
        };
    }

    private function tipoLegacy(string $tipo): string
    {
        return match ($tipo) {
            'inicio_refrigerio' => 'salida_refrigerio',
            'fin_refrigerio' => 'entrada_refrigerio',
            'completado' => 'finalizado',
            default => $tipo,
        };
    }

    private function tiposAceptados(): array
    {
        return ['entrada', 'inicio_refrigerio', 'fin_refrigerio', 'salida', 'salida_refrigerio', 'entrada_refrigerio'];
    }

    private function fechaDesdeMilisegundos(mixed $valor): ?Carbon
    {
        if (! is_numeric($valor) || (int) $valor <= 0) {
            return null;
        }

        $fecha = Carbon::createFromTimestampMs((int) $valor, $this->zonaHoraria());
        if ($fecha->isFuture() || ! $fecha->isSameDay($this->ahora())) {
            return null;
        }

        return $fecha;
    }

    private function valor(?object $objeto, string $campo, mixed $default = null): mixed
    {
        return $objeto && property_exists($objeto, $campo) ? $objeto->{$campo} : $default;
    }

    private function ahora(): Carbon
    {
        return Carbon::now($this->zonaHoraria());
    }

    private function zonaHoraria(): string
    {
        return (string) config('asistencia.timezone', 'America/Lima');
    }

    // ── Fotos pendientes de revisión ──────────────────────────────────────────
    public function fotosPendientes(Request $request): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $columnas = [
            'a.id', 'a.agente_id', 'a.fecha', 'a.hora_ingreso', 'a.metodo_marcacion',
            'a.foto_marcacion',
            'ag.nombres', 'ag.tienda_base',
        ];
        foreach (['lat_entrada', 'lng_entrada', 'accuracy_entrada', 'distancia_entrada'] as $geo) {
            if (Schema::hasColumn('asistencias', $geo)) {
                $columnas[] = "a.{$geo}";
            }
        }

        $rows = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->where('a.requiere_revision', 1)
            ->whereNotNull('a.foto_marcacion')
            ->select($columnas)
            ->orderByDesc('a.fecha')
            ->paginate(20);

        return response()->json($rows);
    }

    // ── Monitor de fraude de dispositivos (admin) ──────────────────────────────
    // Paridad legacy gerencia/panel_asistencias.php: últimas 50 alertas, más
    // recientes primero. Solo lectura — el legacy no ofrece acciones sobre el log.
    // Si la tabla aún no existe, responde estado vacío en vez de reventar.
    public function fraudeDispositivos(Request $request): JsonResponse
    {
        // APP-06 conserva este endpoint como feed unico del monitor admin. Las
        // incidencias de ubicacion se normalizan al contrato legacy en lectura;
        // fuente/tipo_ubicacion permiten distinguirlas sin duplicar rutas o permisos.
        $hayDispositivos = Schema::hasTable('log_fraude_dispositivo');
        $hayUbicacion = Schema::hasTable('asistencia_incidencias_ubicacion');

        if (! $hayDispositivos && ! $hayUbicacion) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $dispositivos = $hayDispositivos
            ? DB::table('log_fraude_dispositivo')
                ->select(['id', 'fecha_hora', 'nombre_agente', 'dni_ingresado', 'dni_duenio_hash', 'tienda_intento'])
                ->orderByDesc('fecha_hora')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(function (object $row) {
                    $row->fuente = 'dispositivo';
                    $row->tipo_ubicacion = null;

                    return $row;
                })
            : collect();

        $ubicacion = $hayUbicacion
            ? DB::table('asistencia_incidencias_ubicacion as iu')
                ->leftJoin('agentes as ag', 'ag.id', '=', 'iu.agente_id')
                ->leftJoin('tiendas as t', 't.id', '=', 'iu.tienda_id')
                ->select([
                    'iu.id', 'iu.created_at', 'iu.tipo', 'iu.agente_id', 'iu.tienda_id',
                    'ag.nombres', 'ag.dni', 't.codigo as tienda_codigo',
                ])
                ->orderByDesc('iu.created_at')
                ->orderByDesc('iu.id')
                ->limit(50)
                ->get()
                ->map(function (object $row) {
                    // El frontend (MonitorFraudePanel) renderiza la etiqueta/ícono a partir de
                    // tipo_ubicacion; nombre_agente y dni_duenio_hash quedan limpios (sin texto
                    // duplicado horneado aquí, columna "DNI dueño" no aplica a este tipo de alerta).
                    return (object) [
                        // Negativo evita colisiones con ids del log en la key del panel.
                        'id' => -((int) $row->id),
                        'fecha_hora' => $row->created_at,
                        'nombre_agente' => $row->nombres ?? "Agente #{$row->agente_id}",
                        'dni_ingresado' => $row->dni,
                        'dni_duenio_hash' => null,
                        'tienda_intento' => $row->tienda_codigo ?? ($row->tienda_id !== null ? (string) $row->tienda_id : null),
                        'fuente' => 'ubicacion',
                        'tipo_ubicacion' => $row->tipo,
                    ];
                })
            : collect();

        $rows = $dispositivos
            ->concat($ubicacion)
            ->sortByDesc(fn (object $row) => sprintf('%s|%012d', $row->fecha_hora, abs((int) $row->id)))
            ->take(50)
            ->values();

        return response()->json(['data' => $rows, 'total' => $rows->count()]);
    }

    // ── Aprobar/rechazar foto ──────────────────────────────────────────────────
    public function photoAction(Request $request, int $id): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        $data = $request->validate([
            'accion' => ['required', Rule::in(['aprobar', 'rechazar'])],
        ]);

        $asistencia = DB::table('asistencias')->where('id', $id)->first();
        if (! $asistencia) {
            return response()->json(['error' => 'Registro no encontrado.'], 404);
        }

        if ($data['accion'] === 'aprobar') {
            DB::table('asistencias')->where('id', $id)->update([
                'requiere_revision' => 0,
                'foto_marcacion' => null,
                'updated_at' => now(),
            ]);

            return response()->json(['message' => 'Foto aprobada y eliminada por política zero-retention.']);
        }

        DB::table('asistencias')->where('id', $id)->delete();

        return response()->json(['message' => 'Marcación rechazada y eliminada.']);
    }

    // ── Panel admin ───────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json([
                'warning' => 'La tabla asistencias no existe.',
                'data' => [],
                'kpis' => ['presentes' => 0, 'ausentes' => 0, 'tardanzas' => 0, 'pendientes_revision' => 0],
            ]);
        }

        $desde = $request->get('fecha_desde', now()->toDateString());
        $hasta = $request->get('fecha_hasta', now()->toDateString());
        $agente = $request->get('agente_id');

        $query = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->when($agente, fn ($q) => $q->where('a.agente_id', $agente))
            ->select([
                'a.*',
                'ag.nombres',
                'ag.tienda_base',
                'ag.hora_ingreso as ingreso_oficial',
                'ag.hora_salida as salida_oficial',
                'ag.hora_ref_inicio as ref_inicio_oficial',
                'ag.hora_ref_fin as ref_fin_oficial',
                'ag.dia_descanso',
            ])
            ->orderByDesc('a.fecha')
            ->orderByDesc('a.hora_ingreso');

        $kpis = DB::table('asistencias as a')
            ->join('agentes as ag2', 'ag2.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->when($agente, fn ($q) => $q->where('a.agente_id', $agente))
            ->selectRaw('
                COALESCE(SUM(CASE WHEN a.hora_ingreso IS NOT NULL THEN 1 ELSE 0 END), 0)  AS presentes,
                COALESCE(SUM(CASE WHEN a.hora_ingreso IS NULL THEN 1 ELSE 0 END), 0)      AS ausentes,
                COALESCE(SUM(CASE WHEN a.minutos_tardanza > 0 THEN 1 ELSE 0 END), 0)      AS tardanzas,
                COALESCE(SUM(CASE WHEN a.requiere_revision = 1 THEN 1 ELSE 0 END), 0)     AS pendientes_revision
            ')
            ->first();

        $data = $query->paginate(Paginacion::desde($request, 30));

        return response()->json(['kpis' => $kpis, 'data' => $data]);
    }

    public function registrar(Request $request): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        $data = $request->validate([
            'agente_id' => ['required', 'integer'],
            'fecha' => ['required', 'date'],
            'hora_ingreso' => ['nullable', 'string'],
            'hora_salida' => ['nullable', 'string'],
            'inicio_refrigerio' => ['nullable', 'string'],
            'fin_refrigerio' => ['nullable', 'string'],
            'tipo' => ['nullable', 'string', Rule::in(['ENTRADA', 'SALIDA'])],
            'metodo_marcacion' => ['nullable', Rule::in(['MANUAL', 'FOTO', 'QR', 'GPS'])],
            'observacion' => ['nullable', 'string', 'max:255'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $existente = DB::table('asistencias')
            ->where('agente_id', $data['agente_id'])
            ->where('fecha', $data['fecha'])
            ->first();

        if ($existente) {
            if ($data['tipo'] === 'SALIDA' && ! $existente->hora_salida) {
                DB::table('asistencias')
                    ->where('id', $existente->id)
                    ->update(['hora_salida' => $data['hora_salida'] ?? now()->toTimeString(), 'updated_at' => now()]);

                return response()->json(['message' => 'Salida registrada.', 'id' => $existente->id]);
            }

            return response()->json(['message' => 'Ya tiene asistencia registrada.', 'id' => $existente->id]);
        }

        // Registro manual completo (paridad acciones_asistencia.php → crear_manual):
        // admite día pasado con refrigerio y motivo. Columnas de refrigerio guardadas por drift legacy.
        $insert = [
            'agente_id' => $data['agente_id'],
            'fecha' => $data['fecha'],
            'hora_ingreso' => $data['hora_ingreso'] ?? now()->toTimeString(),
            'metodo_marcacion' => $data['metodo_marcacion'] ?? 'MANUAL',
            'observacion' => $data['observacion'] ?? ($data['motivo'] ?? null),
            'requiere_revision' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (! empty($data['hora_salida'])) {
            $insert['hora_salida'] = $data['hora_salida'];
        }
        foreach (['inicio_refrigerio', 'fin_refrigerio'] as $rc) {
            if (! empty($data[$rc]) && Schema::hasColumn('asistencias', $rc)) {
                $insert[$rc] = $data[$rc];
            }
        }

        $id = DB::table('asistencias')->insertGetId($insert);

        return response()->json(['message' => 'Asistencia registrada.', 'id' => $id], 201);
    }

    public function aprobar(Request $request, int $id): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        DB::table('asistencias')->where('id', $id)->update(['requiere_revision' => 0, 'updated_at' => now()]);

        return response()->json(['message' => 'Asistencia aprobada.']);
    }

    public function exportar(Request $request): StreamedResponse|JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Tabla asistencias no configurada.'], 422);
        }

        $desde = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta = $request->get('fecha_hasta', now()->toDateString());
        $agente = $request->get('agente_id');

        $rows = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->when($agente, fn ($q) => $q->where('a.agente_id', $agente))
            ->select(
                'a.fecha',
                'ag.nombres',
                'ag.dia_descanso',
                'a.estado_asistencia',
                'a.hora_ingreso',
                'a.inicio_refrigerio',
                'a.fin_refrigerio',
                'a.hora_salida',
                'a.omitio_refrigerio',
                'a.minutos_tardanza',
                'a.minutos_deuda',
            )
            ->orderByDesc('a.fecha')
            ->orderBy('ag.nombres')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Asistencias');
        $sheet->fromArray([
            'Fecha', 'Día', 'Agente', 'Estado', 'Ingreso', 'Sal. Refri.',
            'Reg. Refri.', 'Salida Final', 'Turno Corrido', 'Min. Tardanza', 'Min. Deuda',
        ], null, 'A1');
        $sheet->getStyle('A1:K1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
        ]);

        $dias = ['DOMINGO', 'LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO'];
        foreach ($rows as $index => $row) {
            $fecha = Carbon::parse($row->fecha);
            $dia = $dias[$fecha->dayOfWeek];
            $esDescanso = $dia === strtoupper((string) ($row->dia_descanso ?? ''));
            $sheet->fromArray([
                $fecha->format('d/m/Y'),
                $dia.($esDescanso ? ' (DESC)' : ''),
                $row->nombres,
                $row->estado_asistencia,
                $row->hora_ingreso ? substr($row->hora_ingreso, 0, 5) : '-',
                $row->inicio_refrigerio ? substr($row->inicio_refrigerio, 0, 5) : '-',
                $row->fin_refrigerio ? substr($row->fin_refrigerio, 0, 5) : '-',
                $row->hora_salida ? substr($row->hora_salida, 0, 5) : 'NO MARCÓ',
                $row->omitio_refrigerio ? 'Sí' : 'No',
                (int) $row->minutos_tardanza,
                (int) $row->minutos_deuda,
            ], null, 'A'.($index + 2));
        }

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        return response()->streamDownload(
            static function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            "asistencias_{$desde}_al_{$hasta}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    // ── POST /attendance/salvavidas — Perdonar tardanza con descuento en refrigerio ──
    public function salvavidas(Request $request): JsonResponse
    {
        $asistenciaPasadaId = (int) $request->input('asistencia_id', 0);
        $minutos = (int) $request->input('minutos', 0);

        if ($asistenciaPasadaId <= 0) {
            return response()->json(['success' => false, 'mensaje' => 'ID de asistencia inválido.']);
        }
        if ($minutos <= 0 || $minutos > 120) {
            return response()->json(['success' => false, 'mensaje' => "Los minutos deben estar entre 1 y 120. Valor recibido: {$minutos}."]);
        }

        $agente = $this->userAgentResolver->resolveOrFail($request->user());

        $fechaHoy = now()->toDateString();

        try {
            DB::beginTransaction();

            $asistenciaPasada = DB::table('asistencias')
                ->where('id', $asistenciaPasadaId)
                ->lockForUpdate()
                ->first();

            if (! $asistenciaPasada) {
                throw new \Exception('El registro de asistencia no existe.');
            }
            if ((int) $asistenciaPasada->agente_id !== (int) $agente->id) {
                abort(403, 'La asistencia no pertenece al usuario autenticado.');
            }

            // Solo tardanzas de esta semana
            $inicioSemana = now()->startOfWeek()->toDateString();
            $finSemana = now()->endOfWeek()->toDateString();

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

            if (! $asistenciaHoy) {
                throw new \Exception('Para usar el salvavidas, primero debes haber marcado tu INGRESO del día de HOY.');
            }
            if (! empty($asistenciaHoy->inicio_refrigerio)) {
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

            $fechaFormateada = Carbon::parse($asistenciaPasada->fecha)->format('d/m/Y');

            return response()->json([
                'success' => true,
                'mensaje' => "¡Salvavidas aplicado! Se perdonó tu tardanza del {$fechaFormateada}. Hoy se descontarán {$minutos} minutos de tu refrigerio.",
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($e);

            return response()->json(['success' => false, 'mensaje' => 'Error interno del servidor']);
        }
    }

    // ── PATCH /asistencias/{id} — Edición admin de tiempos de una asistencia ──
    public function editar(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! Permisos::puede($user, 'modificar_asistencias')) {
            return response()->json(['success' => false, 'message' => 'Solo administradores o gerentes.'], 403);
        }

        $asistencia = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->where('a.id', $id)
            ->select([
                'a.*',
                'ag.hora_ingreso as ingreso_oficial',
                'ag.hora_salida as salida_oficial',
                'ag.hora_ref_inicio as ref_inicio_oficial',
                'ag.hora_ref_fin as ref_fin_oficial',
                'ag.dia_descanso',
            ])
            ->first();
        if (! $asistencia) {
            return response()->json(['success' => false, 'message' => 'Asistencia no encontrada.'], 404);
        }

        $validated = $request->validate([
            'fecha' => ['sometimes', 'date_format:Y-m-d'],
            'hora_ingreso' => ['sometimes', 'nullable', 'date_format:H:i'],
            'hora_salida' => ['sometimes', 'nullable', 'date_format:H:i'],
            'inicio_refrigerio' => ['sometimes', 'nullable', 'date_format:H:i'],
            'fin_refrigerio' => ['sometimes', 'nullable', 'date_format:H:i'],
            'omitio_refrigerio' => ['sometimes', 'boolean'],
            'observacion_admin' => ['sometimes', 'nullable', 'string', 'max:500'],
            'horas_extras_aprobadas' => ['sometimes', 'numeric', 'between:0,24'],
            'horas_extras' => ['sometimes', 'numeric', 'between:0,24'],
            'minutos_refrigerio_asignado' => ['sometimes', 'nullable', 'integer', 'between:0,180'],
        ]);

        $update = [];
        if (array_key_exists('fecha', $validated)) {
            $update['fecha'] = $validated['fecha'];
        }
        foreach (['hora_ingreso', 'hora_salida', 'inicio_refrigerio', 'fin_refrigerio'] as $campo) {
            if (array_key_exists($campo, $validated)) {
                $update[$campo] = $validated[$campo] ? $validated[$campo].':00' : null;
            }
        }
        if (array_key_exists('omitio_refrigerio', $validated)) {
            $update['omitio_refrigerio'] = (bool) $validated['omitio_refrigerio'];
        }
        if (array_key_exists('observacion_admin', $validated)) {
            $observacion = trim((string) ($validated['observacion_admin'] ?? ''));
            if (Schema::hasColumn('asistencias', 'observacion_admin')) {
                $update['observacion_admin'] = $observacion ?: null;
            }
            if (Schema::hasColumn('asistencias', 'observacion_tardanza')) {
                $update['observacion_tardanza'] = $observacion ?: null;
            } elseif (Schema::hasColumn('asistencias', 'observacion')) {
                $update['observacion'] = $observacion ?: null;
            }
        }

        $horasExtras = $validated['horas_extras_aprobadas'] ?? $validated['horas_extras'] ?? null;
        if ($horasExtras !== null && Schema::hasColumn('asistencias', 'horas_extras_aprobadas')) {
            $update['horas_extras_aprobadas'] = round((float) $horasExtras, 2);
        }

        if (array_key_exists('minutos_refrigerio_asignado', $validated)) {
            if (! (bool) ($asistencia->turno_extendido ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se puede asignar refrigerio a un turno extendido.',
                ], 422);
            }
            $update['minutos_refrigerio_asignado'] = $validated['minutos_refrigerio_asignado'];
        }

        $estado = (object) array_merge((array) $asistencia, $update);
        [$minutosTardanza, $minutosDeuda, $tardanzaRefrigerio] = $this->recalcularTiemposAsistencia($estado);
        $update['minutos_tardanza'] = $minutosTardanza;
        $update['minutos_deuda'] = $minutosDeuda;
        $update['minutos_extra'] = 0;
        if (Schema::hasColumn('asistencias', 'minutos_tardanza_refrigerio')) {
            $update['minutos_tardanza_refrigerio'] = $tardanzaRefrigerio;
        }

        if (Schema::hasColumn('asistencias', 'updated_at')) {
            $update['updated_at'] = now();
        }

        if (! empty($update)) {
            DB::table('asistencias')->where('id', $id)->update($update);
            $this->registrarEdicionAsistencia($asistencia, $update, $user->id, $request->ip());
        }

        return response()->json([
            'success' => true,
            'message' => 'Asistencia actualizada y recalculada correctamente.',
            'minutos_tardanza' => $minutosTardanza,
            'minutos_deuda' => $minutosDeuda,
            'minutos_tardanza_refrigerio' => $tardanzaRefrigerio,
        ]);
    }

    // ── Auditoría: registra en log_ediciones_asistencia cada campo que cambió ──
    // Paridad legacy: gerencia/admin_editar_asistencia.php y acciones_asistencia.php.
    private function registrarEdicionAsistencia(object $anterior, array $update, int $adminId, ?string $ip): void
    {
        if (! Schema::hasTable('log_ediciones_asistencia')) {
            return;
        }

        $camposAuditables = [
            'fecha', 'hora_ingreso', 'hora_salida', 'inicio_refrigerio', 'fin_refrigerio',
            'omitio_refrigerio', 'observacion_admin', 'observacion_tardanza', 'observacion',
            'horas_extras_aprobadas', 'minutos_refrigerio_asignado',
        ];
        $normalizar = fn ($valor) => is_bool($valor) ? ($valor ? '1' : '0') : (string) ($valor ?? '');
        $ahora = now();
        $filas = [];
        foreach ($camposAuditables as $campo) {
            if (! array_key_exists($campo, $update)) {
                continue;
            }
            $valorAnterior = $normalizar($anterior->$campo ?? null);
            $valorNuevo = $normalizar($update[$campo]);
            if ($valorAnterior === $valorNuevo) {
                continue;
            }
            $filas[] = [
                'asistencia_id' => $anterior->id,
                'agente_id' => $anterior->agente_id,
                'admin_id' => $adminId,
                'campo_modificado' => $campo,
                'valor_anterior' => $valorAnterior,
                'valor_nuevo' => $valorNuevo,
                'fecha_cambio' => $ahora,
                'ip_admin' => $ip ?? '',
            ];
        }

        if (! empty($filas)) {
            DB::table('log_ediciones_asistencia')->insert($filas);
        }
    }

    private function recalcularTiemposAsistencia(object $asistencia): array
    {
        $dias = ['DOMINGO', 'LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO'];
        $fecha = Carbon::parse($asistencia->fecha);
        $esDescanso = $dias[$fecha->dayOfWeek] === strtoupper((string) ($asistencia->dia_descanso ?? ''));

        $minutosTardanza = 0;
        $tardanzaRefrigerio = 0;
        $minutosDeuda = 0;

        if (! $esDescanso && $asistencia->hora_ingreso && $asistencia->ingreso_oficial) {
            $ingresoReal = Carbon::createFromFormat('H:i:s', $this->normalizarHora($asistencia->hora_ingreso));
            $ingresoOficial = Carbon::createFromFormat('H:i:s', $this->normalizarHora($asistencia->ingreso_oficial));
            if ($ingresoReal->gt($ingresoOficial)) {
                $minutosTardanza += $ingresoOficial->diffInMinutes($ingresoReal);
            }
        }

        $omitioRefrigerio = (bool) ($asistencia->omitio_refrigerio ?? false);
        if (! $esDescanso && ! $omitioRefrigerio && $asistencia->inicio_refrigerio && $asistencia->fin_refrigerio) {
            $duracionPermitida = null;
            if ((bool) ($asistencia->turno_extendido ?? false) && $asistencia->minutos_refrigerio_asignado !== null) {
                $duracionPermitida = (int) $asistencia->minutos_refrigerio_asignado;
            } elseif ($asistencia->ref_inicio_oficial && $asistencia->ref_fin_oficial) {
                $refInicioOficial = Carbon::createFromFormat('H:i:s', $this->normalizarHora($asistencia->ref_inicio_oficial));
                $refFinOficial = Carbon::createFromFormat('H:i:s', $this->normalizarHora($asistencia->ref_fin_oficial));
                $duracionPermitida = $refInicioOficial->diffInMinutes($refFinOficial);
            }

            if ($duracionPermitida !== null) {
                if ((bool) ($asistencia->comodin_usado ?? false) && (int) ($asistencia->min_comodin ?? 0) > 0) {
                    $duracionPermitida = max(30, $duracionPermitida - (int) $asistencia->min_comodin);
                }
                $refInicioReal = Carbon::createFromFormat('H:i:s', $this->normalizarHora($asistencia->inicio_refrigerio));
                $refFinReal = Carbon::createFromFormat('H:i:s', $this->normalizarHora($asistencia->fin_refrigerio));
                $duracionReal = $refInicioReal->diffInMinutes($refFinReal);
                $tardanzaRefrigerio = max(0, $duracionReal - $duracionPermitida);
                $minutosTardanza += $tardanzaRefrigerio;
            }
        }

        if ($asistencia->hora_salida && $asistencia->salida_oficial) {
            $salidaReal = Carbon::createFromFormat('H:i:s', $this->normalizarHora($asistencia->hora_salida));
            $salidaOficial = Carbon::createFromFormat('H:i:s', $this->normalizarHora($asistencia->salida_oficial));
            if ($salidaReal->lt($salidaOficial)) {
                $minutosDeuda = $salidaReal->diffInMinutes($salidaOficial);
            }
        }

        return [$minutosTardanza, $minutosDeuda, $tardanzaRefrigerio];
    }

    private function normalizarHora(string $hora): string
    {
        return strlen($hora) === 5 ? $hora.':00' : substr($hora, 0, 8);
    }

    // ── DELETE /asistencias/{id} — Eliminar registro (admin, paridad acciones_asistencia.php) ──
    public function eliminar(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! Permisos::puede($user, 'modificar_asistencias')) {
            return response()->json(['success' => false, 'message' => 'Solo administradores o gerentes.'], 403);
        }
        $asistencia = DB::table('asistencias')->where('id', $id)->first();
        if (! $asistencia) {
            return response()->json(['success' => false, 'message' => 'Asistencia no encontrada.'], 404);
        }

        DB::table('asistencias')->where('id', $id)->delete();
        $this->registrarEliminacionAsistencia($asistencia, $user->id, $request->ip());

        return response()->json(['success' => true, 'message' => 'Registro de asistencia eliminado.']);
    }

    // ── Auditoría: registra en log_ediciones_asistencia la eliminación de un registro ──
    // Paridad legacy: gerencia/acciones_asistencia.php (accion 'eliminar_registro').
    private function registrarEliminacionAsistencia(object $asistencia, int $adminId, ?string $ip): void
    {
        if (! Schema::hasTable('log_ediciones_asistencia')) {
            return;
        }

        DB::table('log_ediciones_asistencia')->insert([
            'asistencia_id' => $asistencia->id,
            'agente_id' => $asistencia->agente_id,
            'admin_id' => $adminId,
            'campo_modificado' => 'ELIMINACION',
            'valor_anterior' => sprintf(
                'fecha=%s entrada=%s salida=%s',
                $asistencia->fecha,
                $asistencia->hora_ingreso,
                $asistencia->hora_salida
            ),
            'valor_nuevo' => 'ELIMINADO',
            'fecha_cambio' => now(),
            'ip_admin' => $ip ?? '',
        ]);
    }

    // ── GET /asistencias/mis-tardanzas?dni= — Tardanzas de la semana del agente ──
    // Alimenta el salvavidas (paridad legacy mi_historial.php): el agente ve sus
    // tardanzas recuperables de la semana actual y si ya usó su comodín.
    public function misTardanzas(Request $request): JsonResponse
    {
        $dni = $this->userAgentResolver->resolveOrFail($request->user())->dni;
        if (! preg_match('/^\d{8}$/', $dni)) {
            return response()->json(['success' => false, 'mensaje' => 'DNI inválido.'], 422);
        }

        $agente = DB::table('agentes')->where('dni', $dni)->first();
        if (! $agente) {
            return response()->json(['success' => false, 'mensaje' => 'Agente no encontrado.'], 404);
        }
        $inicioSemana = now()->startOfWeek()->toDateString();
        $finSemana    = now()->endOfWeek()->toDateString();

        $tieneTardanzaCol = Schema::hasColumn('asistencias', 'minutos_tardanza');
        $tieneComodinCol  = Schema::hasColumn('asistencias', 'comodin_usado');

        $query = DB::table('asistencias')
            ->where('agente_id', $agente->id)
            ->whereBetween('fecha', [$inicioSemana, $finSemana]);
        if ($tieneTardanzaCol) {
            $query->where('minutos_tardanza', '>', 0);
        }

        $cols = ['id', 'fecha', 'hora_ingreso'];
        if ($tieneTardanzaCol) $cols[] = 'minutos_tardanza';
        if ($tieneComodinCol)  $cols[] = 'comodin_usado';

        $tardanzas = $query->orderBy('fecha')->get($cols);

        $yaUso = $tieneComodinCol && DB::table('asistencias')
            ->where('agente_id', $agente->id)
            ->whereBetween('fecha', [$inicioSemana, $finSemana])
            ->where('comodin_usado', 1)
            ->exists();

        return response()->json([
            'success'        => true,
            'agente'         => ['id' => $agente->id, 'nombres' => $agente->nombres],
            'tardanzas'      => $tardanzas,
            'salvavidas_usado' => $yaUso,
        ]);
    }

    // GET /asistencias/mi-historial
    // Historial del agente autenticado, comisiones por dia y panel del equipo
    // cuando el agente vinculado es jefe de tienda.
    public function miHistorial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fecha_desde' => 'nullable|date_format:Y-m-d',
            'fecha_hasta' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_desde',
        ]);

        $agente = $this->userAgentResolver->resolveOrFail($request->user());
        $desde = $validated['fecha_desde'] ?? now()->startOfMonth()->toDateString();
        $hasta = $validated['fecha_hasta'] ?? now()->toDateString();

        $asistencias = DB::table('asistencias')
            ->where('agente_id', $agente->id)
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderByDesc('fecha')
            ->get();

        $comisiones = Schema::hasTable('ventas') && Schema::hasTable('reportes')
            ? DB::table('ventas as v')
                ->join('reportes as r', 'r.id', '=', 'v.reporte_id')
                ->where('v.vendedor_id', $agente->id)
                ->whereBetween('r.fecha', [$desde, $hasta])
                ->where('v.comision_estado', '!=', 'ANULADA')
                ->groupBy('r.fecha')
                ->orderByDesc('r.fecha')
                ->selectRaw('r.fecha, MIN(r.id) as reporte_id, COUNT(v.id) as items, COALESCE(SUM(v.comision_generada), 0) as comision')
                ->get()
            : collect();

        $rolJefe = strtolower(trim((string) ($agente->es_gerencia ?? '0')));
        $esJefe = ! in_array($rolJefe, ['', '0', 'false', 'no'], true);
        $tiendaJefe = trim((string) ($agente->tienda_base ?? ''));
        $equipo = collect();

        // Fail-closed: el jefe SOLO ve el equipo de su propia tienda. Sin tienda_base
        // no hay equipo — evita que ->where('tienda_base', null) degenere en whereNull
        // y termine listando agentes de otras tiendas (paridad TiendaGuard).
        if ($esJefe && $tiendaJefe !== '') {
            $equipo = DB::table('agentes as a')
                ->leftJoin('asistencias as asi', function ($join) use ($desde, $hasta) {
                    $join->on('asi.agente_id', '=', 'a.id')
                        ->whereBetween('asi.fecha', [$desde, $hasta]);
                })
                ->where('a.tienda_base', $tiendaJefe)
                ->where('a.estado', 'ACTIVO')
                ->groupBy('a.id', 'a.nombres', 'a.dni', 'a.tienda_base')
                ->orderBy('a.nombres')
                ->selectRaw("
                    a.id, a.nombres, a.dni, a.tienda_base,
                    COALESCE(SUM(CASE WHEN asi.hora_ingreso IS NOT NULL THEN 1 ELSE 0 END), 0) as presentes,
                    COALESCE(SUM(CASE WHEN asi.estado_asistencia = 'FALTA_INJUSTIFICADA' THEN 1 ELSE 0 END), 0) as faltas,
                    COALESCE(SUM(asi.minutos_tardanza), 0) as tardanza_total
                ")
                ->get();
        }

        return response()->json([
            'agente' => [
                'id' => $agente->id,
                'dni' => $agente->dni,
                'nombres' => $agente->nombres,
                'tienda_base' => $agente->tienda_base,
                'es_jefe' => $esJefe,
            ],
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'asistencias' => $asistencias,
            'comisiones' => $comisiones,
            'total_comisiones' => round((float) $comisiones->sum('comision'), 2),
            'equipo' => $equipo,
        ]);
    }

    // ── POST /asistencias/excepcion — Registrar FALTA/PERMISO o PERDONAR (admin) ──
    // Paridad legacy gerencia/acciones_asistencia.php (acción registrar_excepcion).
    public function registrarExcepcion(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! Permisos::puede($user, 'modificar_asistencias')) {
            return response()->json(['success' => false, 'message' => 'Solo administradores o gerentes.'], 403);
        }

        $validated = $request->validate([
            'agente_id' => 'required|integer|min:1',
            'fecha'     => 'required|date_format:Y-m-d',
            'estado'    => 'required|in:FALTA_INJUSTIFICADA,PERMISO,PERDONAR',
        ]);

        $agenteId = (int) $validated['agente_id'];
        $fecha    = $validated['fecha'];
        $estado   = $validated['estado'];

        // PERDONAR: borra cualquier registro negativo del agente en esa fecha.
        if ($estado === 'PERDONAR') {
            $borrados = DB::table('asistencias')
                ->where('agente_id', $agenteId)
                ->whereDate('fecha', $fecha)
                ->whereIn('estado_asistencia', ['FALTA_INJUSTIFICADA', 'PERMISO', 'EXCEPCION'])
                ->delete();

            return response()->json([
                'success' => true,
                'message' => $borrados > 0 ? 'Excepción perdonada (registro negativo eliminado).' : 'No había registros negativos que perdonar.',
            ]);
        }

        // FALTA/PERMISO: insertar excepción (sin duplicar el día).
        $existe = DB::table('asistencias')
            ->where('agente_id', $agenteId)
            ->whereDate('fecha', $fecha)
            ->exists();
        if ($existe) {
            return response()->json(['success' => false, 'message' => 'Ya existe un registro de asistencia para ese día.'], 422);
        }

        $tiendaId = DB::table('agentes')->where('id', $agenteId)->value('tienda_base') ?? 'S/T';
        // PERMISO → deuda de 540 min (9h) para forzar recuperación; FALTA → sin deuda de minutos.
        $minutosDeuda = $estado === 'PERMISO' ? 540 : 0;

        $insert = [
            'agente_id' => $agenteId,
            'tienda_id' => $tiendaId,
            'fecha'     => $fecha,
        ];
        if (Schema::hasColumn('asistencias', 'estado_asistencia')) $insert['estado_asistencia'] = $estado;
        if (Schema::hasColumn('asistencias', 'minutos_deuda'))     $insert['minutos_deuda']     = $minutosDeuda;
        if (Schema::hasColumn('asistencias', 'hora_ingreso'))      $insert['hora_ingreso']      = '00:00:00';
        if (Schema::hasColumn('asistencias', 'latitud_ingreso'))   $insert['latitud_ingreso']   = 'EXCEPCION';
        if (Schema::hasColumn('asistencias', 'longitud_ingreso'))  $insert['longitud_ingreso']  = 'EXCEPCION';

        DB::table('asistencias')->insert($insert);

        return response()->json([
            'success' => true,
            'message' => $estado === 'PERMISO' ? 'Permiso registrado (genera deuda de 9h).' : 'Falta injustificada registrada.',
        ], 201);
    }

    // ── GET /asistencias/matriz — Control mensual agente × día (paridad legacy control_asistencias.php) ──
    public function matriz(Request $request): JsonResponse
    {
        $user = $request->user();
        // Asistencias VER (plan 16): admin/gerente + jefe_tienda en solo lectura.
        if (! $user?->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE, Usuario::ROL_JEFE_TIENDA)) {
            return response()->json(['success' => false, 'message' => 'No autorizado.'], 403);
        }
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $mes = trim((string) $request->query('mes', ''));
        if (! preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $mes = $this->ahora()->format('Y-m');
        }

        $fechaIni = $mes.'-01';
        $fechaFinMes = Carbon::parse($fechaIni)->endOfMonth()->toDateString();
        $hoy = $this->ahora()->toDateString();
        $fechaFin = ($mes === $this->ahora()->format('Y-m')) ? $hoy : $fechaFinMes;
        $diasMostrar = (int) Carbon::parse($fechaFin)->day;

        $agentes = DB::table('agentes')
            ->where('estado', 'ACTIVO')
            ->orderBy('tienda_base')->orderBy('nombres')
            ->get(['id', 'nombres', 'tienda_base', 'dia_descanso']);

        $ids = $agentes->pluck('id');

        $asisMap = [];
        if ($ids->isNotEmpty()) {
            foreach (DB::table('asistencias')->whereIn('agente_id', $ids)->whereBetween('fecha', [$fechaIni, $fechaFin])->get() as $row) {
                $dia = (int) Carbon::parse($row->fecha)->day;
                $asisMap[$row->agente_id][$dia] = $row;
            }
        }

        $exMap = [];
        if ($ids->isNotEmpty() && Schema::hasTable('excepciones_jornada')) {
            foreach (DB::table('excepciones_jornada')->whereIn('agente_id', $ids)->whereBetween('fecha', [$fechaIni, $fechaFin])->get(['agente_id', 'fecha', 'tipo']) as $row) {
                $dia = (int) Carbon::parse($row->fecha)->day;
                $exMap[$row->agente_id][$dia] = $row->tipo;
            }
        }

        $porTienda = [];
        foreach ($agentes as $agente) {
            $porTienda[$agente->tienda_base ?? '—'][] = $agente;
        }

        $tiendas = [];
        foreach ($porTienda as $tienda => $agentesTienda) {
            $filas = [];
            foreach ($agentesTienda as $agente) {
                $dias = [];
                for ($d = 1; $d <= $diasMostrar; $d++) {
                    $fechaD = $mes.'-'.str_pad((string) $d, 2, '0', STR_PAD_LEFT);
                    $asis = $asisMap[$agente->id][$d] ?? null;
                    $exTipo = $exMap[$agente->id][$d] ?? null;
                    $dias[$d] = $this->celdaMatriz($agente, $asis, $exTipo, $fechaD, $hoy);
                }
                $filas[] = [
                    'id' => $agente->id,
                    'nombre' => $agente->nombres,
                    'dia_descanso' => $agente->dia_descanso,
                    'dias' => $dias,
                ];
            }
            $tiendas[] = ['tienda' => $tienda, 'agentes' => $filas];
        }

        return response()->json([
            'mes' => $mes,
            'dias_mostrar' => $diasMostrar,
            'tiendas' => $tiendas,
        ]);
    }

    private function celdaMatriz(object $agente, ?object $asis, ?string $exTipo, string $fecha, string $hoy): array
    {
        $diasEs = ['', 'LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO', 'DOMINGO'];
        $iso = Carbon::parse($fecha)->dayOfWeekIso;
        $esFuturo = $fecha > $hoy;
        $esDescanso = ! $asis && strtoupper((string) ($agente->dia_descanso ?? '')) === ($diasEs[$iso] ?? '');
        $estadoAsis = $asis->estado_asistencia ?? null;
        $tardanza = (int) ($asis->minutos_tardanza ?? 0);
        $esMedioTiempo = $exTipo === 'MEDIO_TIEMPO';

        $estado = match (true) {
            $esDescanso => 'DESCANSO',
            $esFuturo => 'FUTURO',
            ! $asis => 'SIN_MARCA',
            $estadoAsis === 'FALTA_INJUSTIFICADA' => 'FALTA',
            $estadoAsis === 'PERMISO' => 'PERMISO',
            $estadoAsis === 'FERIADO' => 'FERIADO',
            $esMedioTiempo => 'MEDIO_TIEMPO',
            $tardanza > 0 => 'TARDANZA',
            default => 'OK',
        };

        return [
            'fecha' => $fecha,
            'asistencia_id' => $asis->id ?? null,
            'estado' => $estado,
            'minutos_tardanza' => $tardanza,
            'hora_ingreso' => $asis->hora_ingreso ?? null,
            'inicio_refrigerio' => $asis->inicio_refrigerio ?? null,
            'fin_refrigerio' => $asis->fin_refrigerio ?? null,
            'hora_salida' => $asis->hora_salida ?? null,
            'omitio_refrigerio' => (bool) ($asis->omitio_refrigerio ?? false),
            'excepcion_tipo' => $exTipo,
        ];
    }

    // ── POST /asistencias/excepcion-jornada — Toggle MEDIO_TIEMPO/TURNO_LIBRE/OTRO ──
    // Paridad legacy gerencia/ajax_excepcion_jornada.php: si ya existe la fila
    // (agente_id, fecha) la borra (estado off); si no existe, la crea (estado on).
    public function excepcionJornada(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! Permisos::puede($user, 'modificar_asistencias')) {
            return response()->json(['success' => false, 'message' => 'Solo administradores o gerentes.'], 403);
        }
        if (! Schema::hasTable('excepciones_jornada')) {
            return response()->json(['success' => false, 'message' => 'Tabla excepciones_jornada no configurada.'], 503);
        }

        $validated = $request->validate([
            'agente_id' => 'required|integer|min:1',
            'fecha' => 'required|date_format:Y-m-d',
            'tipo' => 'nullable|in:MEDIO_TIEMPO,TURNO_LIBRE,OTRO',
            'horas_trabajadas' => 'nullable|numeric',
        ]);

        $agenteId = (int) $validated['agente_id'];
        $fecha = $validated['fecha'];
        $tipo = $validated['tipo'] ?? 'MEDIO_TIEMPO';
        $horas = round(max(0, min(24, (float) ($validated['horas_trabajadas'] ?? 4.0))), 2);

        $existente = DB::table('excepciones_jornada')->where('agente_id', $agenteId)->where('fecha', $fecha)->first();

        if ($existente) {
            DB::table('excepciones_jornada')->where('id', $existente->id)->delete();

            return response()->json(['success' => true, 'estado' => 'off']);
        }

        try {
            DB::table('excepciones_jornada')->insert([
                'agente_id' => $agenteId,
                'fecha' => $fecha,
                'tipo' => $tipo,
                'horas_trabajadas' => $horas,
                'registrado_por' => $user->id,
                'creado_en' => $this->ahora(),
            ]);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }

            // Doble submit: otra petición ya insertó la excepción para este agente/fecha.
            return response()->json(['success' => true, 'estado' => 'on']);
        }

        return response()->json(['success' => true, 'estado' => 'on'], 201);
    }
}
