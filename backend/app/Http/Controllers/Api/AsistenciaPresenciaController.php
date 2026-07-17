<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * APP-04 — Monitoreo de presencia por ubicación.
 *
 * - ping-ubicacion (público, autenticado por device_hash como el terminal):
 *   la app envía su posición cada 30 min mientras el turno está abierto. Se hace
 *   upsert del ÚLTIMO ping por agente (DECISIÓN-APP-03: estado, no historial) y,
 *   si el estado no es `ok`, se registra una incidencia que sí persiste.
 * - presencia (admin): semáforo en vivo de los agentes en turno.
 * - consentimiento-ubicacion (APP-08): el agente debe aceptar el texto de rastreo
 *   ANTES de que la app empiece a mandar pings — sin consentimiento registrado,
 *   ping-ubicacion rechaza con CONSENT_REQUIRED (428).
 */
class AsistenciaPresenciaController extends Controller
{
    // Sube esta versión si el texto de consentimiento cambia de forma sustancial
    // (obliga a los agentes ya autorizados a re-aceptar).
    private const VERSION_TEXTO_CONSENTIMIENTO = 'v1';

    public function registrarConsentimiento(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dni' => ['required', 'string'],
            'device_hash' => ['required', 'string', 'max:128'],
        ]);

        $agente = DB::table('agentes')->where('dni', trim($data['dni']))->first();
        if (! $agente) {
            return response()->json(['error' => 'DNI no encontrado.'], 404);
        }

        $hashDb = trim((string) ($agente->hash_dispositivo ?? ''));
        $deviceHash = trim($data['device_hash']);
        if ($hashDb === '' || ! hash_equals($hashDb, $deviceHash)) {
            return response()->json(['error' => 'Dispositivo no autorizado.', 'code' => 'DEVICE_MISMATCH'], 403);
        }

        $ahora = $this->ahora();
        DB::table('asistencia_consentimientos_ubicacion')->updateOrInsert(
            ['agente_id' => $agente->id, 'device_hash' => $deviceHash],
            [
                'version_texto' => self::VERSION_TEXTO_CONSENTIMIENTO,
                'aceptado_en' => $ahora,
                'updated_at' => $ahora,
                'created_at' => $ahora,
            ]
        );

        return response()->json(['ok' => true, 'version_texto' => self::VERSION_TEXTO_CONSENTIMIENTO]);
    }

    public function pingUbicacion(Request $request): JsonResponse
    {
        if (! Schema::hasTable('asistencias') || ! Schema::hasTable('asistencia_presencia')) {
            return response()->json(['error' => 'Sistema de asistencias no configurado.'], 503);
        }

        $data = $request->validate([
            'dni' => ['required', 'string'],
            'device_hash' => ['required', 'string', 'max:128'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'mock_gps' => ['nullable', 'boolean'],
            'battery_pct' => ['nullable', 'integer', 'between:0,100'],
            'capturado_en' => ['required', 'date'],
        ]);

        $agente = DB::table('agentes')->where('dni', trim($data['dni']))->first();
        if (! $agente) {
            return response()->json(['error' => 'DNI no encontrado.'], 404);
        }

        // Dispositivo: debe coincidir con el hash vinculado al agente (mismo mecanismo
        // que AsistenciaController::validarSeguridad, aquí sin token de emergencia).
        $hashDb = trim((string) ($agente->hash_dispositivo ?? ''));
        $deviceHash = trim($data['device_hash']);
        if ($hashDb === '' || ! hash_equals($hashDb, $deviceHash)) {
            return response()->json([
                'error' => 'Dispositivo no autorizado.',
                'code' => 'DEVICE_MISMATCH',
            ], 403);
        }

        // APP-08: sin consentimiento registrado para este agente+dispositivo, no se rastrea.
        // La app debe llamar a consentimiento-ubicacion antes de empezar a mandar pings.
        if (Schema::hasTable('asistencia_consentimientos_ubicacion')) {
            $consentido = DB::table('asistencia_consentimientos_ubicacion')
                ->where('agente_id', $agente->id)
                ->where('device_hash', $deviceHash)
                ->exists();
            if (! $consentido) {
                return response()->json([
                    'error' => 'Falta aceptar el consentimiento de ubicación.',
                    'code' => 'CONSENT_REQUIRED',
                ], 428);
            }
        }

        // Turno abierto hoy: entrada marcada y salida aún no registrada.
        $hoy = $this->ahora()->toDateString();
        $asistencia = DB::table('asistencias')
            ->where('agente_id', $agente->id)
            ->where('fecha', $hoy)
            ->first();

        if (! $asistencia || empty($asistencia->hora_ingreso) || ! empty($asistencia->hora_salida)) {
            return response()->json([
                'error' => 'No hay un turno abierto para este agente.',
                'code' => 'NO_OPEN_SHIFT',
            ], 422);
        }

        $tienda = $this->buscarTienda($asistencia->tienda_id
            ?? ($agente->tienda_id ?? $agente->tienda_base ?? null));

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $accuracy = isset($data['accuracy']) ? (float) $data['accuracy'] : null;
        $mockGps = (bool) ($data['mock_gps'] ?? false);
        $capturadoEn = Carbon::parse($data['capturado_en'], $this->zonaHoraria());
        $recibidoEn = $this->ahora();

        $distancia = null;
        $estado = 'ok';

        if ($mockGps) {
            $estado = 'mock_gps';
        } elseif ($tienda) {
            $latTienda = $tienda->lat_centro ?? $tienda->latitud ?? null;
            $lngTienda = $tienda->lng_centro ?? $tienda->longitud ?? null;
            if ($latTienda !== null && $lngTienda !== null) {
                $radio = max(10, (int) ($tienda->radio_permitido ?? 60) ?: 60);
                $distancia = GeoService::haversine($lat, $lng, (float) $latTienda, (float) $lngTienda);
                $distanciaEfectiva = max(0, $distancia - (float) ($accuracy ?? 0));
                if ($distanciaEfectiva > $radio) {
                    $estado = 'fuera_de_rango';
                }
            }
        }

        $tiendaId = $tienda->id ?? null;

        DB::table('asistencia_presencia')->updateOrInsert(
            ['agente_id' => $agente->id],
            [
                'tienda_id' => $tiendaId,
                'device_hash' => $deviceHash,
                'lat' => $lat,
                'lng' => $lng,
                'accuracy' => $accuracy,
                'estado' => $estado,
                'battery_pct' => $data['battery_pct'] ?? null,
                'capturado_en' => $capturadoEn,
                'recibido_en' => $recibidoEn,
                'updated_at' => $recibidoEn,
                'created_at' => $recibidoEn,
            ]
        );

        if ($estado !== 'ok' && Schema::hasTable('asistencia_incidencias_ubicacion')) {
            DB::table('asistencia_incidencias_ubicacion')->insert([
                'agente_id' => $agente->id,
                'tienda_id' => $tiendaId,
                'device_hash' => $deviceHash,
                'tipo' => $estado,
                'lat' => $lat,
                'lng' => $lng,
                'accuracy' => $accuracy,
                'distancia_metros' => $distancia !== null ? round($distancia, 2) : null,
                'capturado_en' => $capturadoEn,
                'created_at' => $recibidoEn,
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'estado' => $estado,
            'distancia' => $distancia !== null ? round($distancia) : null,
        ]);
    }

    public function presencia(Request $request): JsonResponse
    {
        if (! Schema::hasTable('asistencias') || ! Schema::hasTable('asistencia_presencia')) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $ahora = $this->ahora();
        $hoy = $ahora->toDateString();

        // Agentes con turno abierto hoy (entrada marcada, sin salida).
        $turnos = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->where('a.fecha', $hoy)
            ->whereNotNull('a.hora_ingreso')
            ->whereNull('a.hora_salida')
            ->select([
                'ag.id as agente_id',
                'ag.dni',
                'ag.nombres as nombre',
                'a.tienda_id as tienda_codigo',
                'a.hora_ingreso',
            ])
            ->orderBy('nombre')
            ->get();

        if ($turnos->isEmpty()) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $agenteIds = $turnos->pluck('agente_id')->all();

        $presencias = DB::table('asistencia_presencia')
            ->whereIn('agente_id', $agenteIds)
            ->get()
            ->keyBy('agente_id');

        // Conteo de incidencias del día por agente.
        $incidencias = Schema::hasTable('asistencia_incidencias_ubicacion')
            ? DB::table('asistencia_incidencias_ubicacion')
                ->whereIn('agente_id', $agenteIds)
                ->where('created_at', '>=', $hoy)
                ->where('created_at', '<', Carbon::parse($hoy)->addDay()->toDateString())
                ->select('agente_id', DB::raw('COUNT(*) as total'))
                ->groupBy('agente_id')
                ->pluck('total', 'agente_id')
            : collect();

        $tiendas = Schema::hasTable('tiendas')
            ? DB::table('tiendas')->get()->keyBy(fn ($t) => (string) $t->codigo)
            : collect();

        $data = $turnos->map(function (object $turno) use ($presencias, $incidencias, $tiendas, $ahora) {
            $ping = $presencias->get($turno->agente_id);
            $tienda = $tiendas->get((string) $turno->tienda_codigo);

            $minutosDesdePing = null;
            $distancia = null;
            if ($ping) {
                $capturado = Carbon::parse($ping->capturado_en, $this->zonaHoraria());
                $minutosDesdePing = (int) floor($capturado->diffInSeconds($ahora, false) / 60);

                $latTienda = $tienda->lat_centro ?? $tienda->latitud ?? null;
                $lngTienda = $tienda->lng_centro ?? $tienda->longitud ?? null;
                if ($latTienda !== null && $lngTienda !== null) {
                    $distancia = round(GeoService::haversine(
                        (float) $ping->lat,
                        (float) $ping->lng,
                        (float) $latTienda,
                        (float) $lngTienda
                    ));
                }
            }

            return [
                'agente_id' => (int) $turno->agente_id,
                'dni' => $turno->dni,
                'nombre' => $turno->nombre,
                'tienda' => $turno->tienda_codigo,
                'hora_ingreso' => $turno->hora_ingreso,
                'estado' => $ping->estado ?? 'sin_ping',
                'ultimo_ping' => $ping->capturado_en ?? null,
                'minutos_desde_ping' => $minutosDesdePing,
                'distancia' => $distancia,
                'battery_pct' => $ping->battery_pct ?? null,
                'incidencias_dia' => (int) ($incidencias[$turno->agente_id] ?? 0),
            ];
        });

        return response()->json([
            'data' => $data->values(),
            'total' => $data->count(),
        ]);
    }

    private function buscarTienda(mixed $tiendaId): ?object
    {
        if ($tiendaId === null || $tiendaId === '' || ! Schema::hasTable('tiendas')) {
            return null;
        }

        return DB::table('tiendas')
            ->where(function ($q) use ($tiendaId) {
                $q->where('codigo', $tiendaId)->orWhere('id', $tiendaId);
            })
            ->first();
    }

    private function ahora(): Carbon
    {
        return Carbon::now($this->zonaHoraria());
    }

    private function zonaHoraria(): string
    {
        return (string) config('asistencia.timezone', 'America/Lima');
    }
}
