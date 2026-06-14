<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
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

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
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

        $ahora = $this->ahora();
        $hoy = $ahora->toDateString();
        $asistencia = DB::table('asistencias')
            ->where('agente_id', $agente->id)
            ->where('fecha', $hoy)
            ->first();

        $siguiente = $this->siguienteMarcacion($asistencia, $agente);
        $tienda = $this->buscarTienda($request->query('tienda_id'));
        $radio = max(10, (int) ($this->valor($tienda, 'radio_permitido', 60) ?: 60));
        $tieneRefrigerio = $this->agenteTieneRefrigerio($agente);
        $deviceId = trim((string) $request->query('device_id', $request->query('device_hash', '')));
        $hashDispositivo = trim((string) $this->valor($agente, 'hash_dispositivo', ''));

        return response()->json([
            'agente' => [
                'id' => $agente->id,
                'nombre' => $agente->nombres ?? $agente->nombre ?? '',
                'tienda_id' => $agente->tienda_id ?? $agente->tienda_base ?? null,
            ],
            'asistencia' => $asistencia,
            'siguiente_marcacion' => $siguiente,
            'siguiente' => $this->tipoLegacy($siguiente),
            'next_tipo' => $this->tipoLegacy($siguiente),
            'fecha' => $hoy,
            'timestamp_servidor' => $ahora->getTimestampMs(),
            'hora_servidor' => $ahora->toTimeString(),
            'entrada' => ! empty($asistencia?->hora_ingreso),
            'salida_refrigerio' => ! empty($asistencia?->inicio_refrigerio),
            'entrada_refrigerio' => ! empty($asistencia?->fin_refrigerio),
            'salida' => ! empty($asistencia?->hora_salida),
            'tiene_refri' => $tieneRefrigerio,
            'es_medio_tiempo' => ! $tieneRefrigerio,
            'omitio_refrigerio' => (bool) $this->valor($asistencia, 'omitio_refrigerio', false),
            'turno_extendido' => (bool) $this->valor($asistencia, 'turno_extendido', false),
            'minutos_refrigerio_asignado' => $this->valor($asistencia, 'minutos_refrigerio_asignado'),
            'radio_permitido' => $radio,
            'accuracy_maxima' => $radio,
            'device_authorized' => $deviceId !== '' && $hashDispositivo !== '' && hash_equals($hashDispositivo, $deviceId),
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

        $bloqueActual = (int) floor(time() / 5);
        $bloqueQr = filter_var($bloqueToken, FILTER_VALIDATE_INT);
        $hmacEsperado = substr(hash_hmac('sha256', "AST|{$tiendaToken}|{$bloqueToken}", config('app.key')), 0, 16);
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

        return $this->procesarMarcacion($agente, $data['tipo'] ?? null, 'QR', [
            'tienda_id' => $this->identificadorTienda($tienda),
            'hora_intento_gps' => $intento?->toDateTimeString(),
            'omitir_refrigerio' => (bool) ($data['omitir_refrigerio'] ?? $data['omitir_ref'] ?? false),
            'turno_extendido' => (bool) ($data['turno_extendido'] ?? false),
            'minutos_refrigerio_asignado' => $data['minutos_refrigerio_asignado'] ?? null,
        ], $momento);
    }

    public function markPhoto(Request $request): JsonResponse
    {
        if (! $this->tablaExiste()) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $data = $request->validate([
            'dni' => ['required', 'string'],
            'tipo' => ['nullable', Rule::in($this->tiposAceptados())],
            'foto' => ['required', 'string'],
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

    public function qrStream(string $tienda_id): JsonResponse
    {
        $tienda = $this->buscarTienda($tienda_id);
        if (! $tienda) {
            return response()->json(['error' => 'Tienda no encontrada.'], 404);
        }

        $tiendaId = $this->identificadorTienda($tienda);
        $bloque = (int) floor(time() / 5);
        $hmac = substr(hash_hmac('sha256', "AST|{$tiendaId}|{$bloque}", config('app.key')), 0, 16);
        $token = "AST|{$tiendaId}|{$bloque}|{$hmac}";
        $ttl = 5 - (time() % 5);

        return response()->json([
            'token' => $token,
            'tienda_id' => $tiendaId,
            'expires_in' => $ttl,
            'bloque' => $bloque,
        ]);
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
            DB::table('agentes')->where('id', $agente->id)->update([
                'estado' => 'ACTIVO',
                'permiso_largo' => 0,
                'fecha_retorno' => null,
            ]);
            $agente->estado = 'ACTIVO';

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
            return null;
        }

        $hashDb = trim((string) $this->valor($agente, 'hash_dispositivo', ''));
        if (! $usaToken && $hashDb !== '' && ! hash_equals($hashDb, $deviceId)) {
            $this->registrarFraudeDispositivo($agente, 'HASH_DISTINTO', $tienda);

            return response()->json([
                'error' => 'Dispositivo no autorizado. Usa tu celular registrado o solicita un token.',
                'code' => 'DEVICE_MISMATCH',
            ], 403);
        }

        $duplicado = DB::table('agentes')
            ->where('hash_dispositivo', $deviceId)
            ->where('id', '!=', $agente->id)
            ->first();

        if ($duplicado && ! $usaToken) {
            $this->registrarFraudeDispositivo($agente, $duplicado->nombres ?? 'DISPOSITIVO_DUPLICADO', $tienda);

            return response()->json([
                'error' => 'Este dispositivo ya está registrado a nombre de otro agente.',
                'code' => 'DEVICE_DUPLICATE',
            ], 403);
        }

        DB::transaction(function () use ($agente, $deviceId, $tienda, $duplicado, $usaToken) {
            if ($duplicado && $usaToken) {
                DB::table('agentes')->where('id', $duplicado->id)->update(['hash_dispositivo' => null]);
            }

            $update = ['hash_dispositivo' => $deviceId];
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
                'ag.hora_salida as salida_oficial',
                'ag.dia_descanso',
            ])
            ->orderByDesc('a.fecha')
            ->orderByDesc('a.hora_ingreso');

        $kpis = DB::table('asistencias as a')
            ->join('agentes as ag2', 'ag2.id', '=', 'a.agente_id')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->when($agente, fn ($q) => $q->where('a.agente_id', $agente))
            ->selectRaw('
                SUM(CASE WHEN a.hora_ingreso IS NOT NULL THEN 1 ELSE 0 END)  AS presentes,
                SUM(CASE WHEN a.hora_ingreso IS NULL THEN 1 ELSE 0 END)      AS ausentes,
                SUM(CASE WHEN a.minutos_tardanza > 0 THEN 1 ELSE 0 END)      AS tardanzas,
                SUM(CASE WHEN a.requiere_revision = 1 THEN 1 ELSE 0 END)     AS pendientes_revision
            ')
            ->first();

        $data = $query->paginate($request->integer('per_page', 30));

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

    public function exportar(Request $request)
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
            ->select('a.fecha', 'ag.nombres', 'ag.tienda_base', 'a.hora_ingreso', 'a.hora_salida',
                'a.minutos_tardanza', 'a.metodo_marcacion', 'a.observacion')
            ->orderByDesc('a.fecha')
            ->get();

        $csv = "\xEF\xBB\xBF";
        $csv .= "Fecha,Agente,Tienda,Ingreso,Salida,Min.Tardanza,Método,Observación\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row->fecha,
                '"'.str_replace('"', '""', $row->nombres ?? '').'"',
                $row->tienda_base ?? '',
                $row->hora_ingreso ?? '',
                $row->hora_salida ?? '',
                $row->minutos_tardanza ?? 0,
                $row->metodo_marcacion ?? 'MANUAL',
                '"'.str_replace('"', '""', $row->observacion ?? '').'"',
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="asistencias_'.$desde.'_'.$hasta.'.csv"',
        ]);
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

        // Identificar al agente por DNI
        $dni = trim($request->input('dni', ''));
        $agente = DB::table('agentes')->where('dni', $dni)->first();
        if (! $agente) {
            return response()->json(['success' => false, 'mensaje' => 'Agente no encontrado.']);
        }

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
        if (! $asistencia) {
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
        // Acciones admin granulares (paridad acciones_asistencia.php). Guardadas por columna por drift legacy.
        if ($request->has('estado_asistencia') && Schema::hasColumn('asistencias', 'estado_asistencia')) {
            $update['estado_asistencia'] = substr(trim($request->input('estado_asistencia', '')), 0, 50) ?: null;
        }
        if ($request->has('horas_extras') && Schema::hasColumn('asistencias', 'horas_extras')) {
            $update['horas_extras'] = max(0, (float) $request->input('horas_extras', 0));
        }
        if ($request->has('minutos_refrigerio_asignado') && Schema::hasColumn('asistencias', 'minutos_refrigerio_asignado')) {
            $update['minutos_refrigerio_asignado'] = max(0, (int) $request->input('minutos_refrigerio_asignado', 0));
        }
        if ($request->has('minutos_tardanza') && Schema::hasColumn('asistencias', 'minutos_tardanza')) {
            $update['minutos_tardanza'] = max(0, (int) $request->input('minutos_tardanza', 0));
        }

        if (! empty($update)) {
            $update['updated_at'] = now();
            DB::table('asistencias')->where('id', $id)->update($update);
        }

        return response()->json(['success' => true, 'message' => 'Asistencia actualizada correctamente.']);
    }

    // ── DELETE /asistencias/{id} — Eliminar registro (admin, paridad acciones_asistencia.php) ──
    public function eliminar(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }
        $deleted = DB::table('asistencias')->where('id', $id)->delete();
        if (! $deleted) {
            return response()->json(['success' => false, 'message' => 'Asistencia no encontrada.'], 404);
        }
        return response()->json(['success' => true, 'message' => 'Registro de asistencia eliminado.']);
    }
}
