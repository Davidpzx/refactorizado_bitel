<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DetectarAsistenciasSinSenal extends Command
{
    protected $signature = 'bitel:detectar-sin-senal';

    protected $description = 'Registra incidencias para turnos abiertos sin un ping de ubicacion reciente';

    private const MINUTOS_SIN_SENAL = 45;

    public function handle(): int
    {
        if (! Schema::hasTable('asistencias')
            || ! Schema::hasTable('asistencia_presencia')
            || ! Schema::hasTable('asistencia_incidencias_ubicacion')) {
            $this->warn('Las tablas de monitoreo de presencia no estan configuradas.');

            return self::SUCCESS;
        }

        $tz = (string) config('asistencia.timezone', 'America/Lima');
        $ahora = now()->timezone($tz);
        $limite = $ahora->copy()->subMinutes(self::MINUTOS_SIN_SENAL);

        $turnos = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->leftJoin('asistencia_presencia as p', 'p.agente_id', '=', 'a.agente_id')
            ->whereDate('a.fecha', $ahora->toDateString())
            ->whereNotNull('a.hora_ingreso')
            ->whereNull('a.hora_salida')
            ->select([
                'a.agente_id',
                'a.tienda_id as asistencia_tienda_id',
                'a.fecha',
                'a.hora_ingreso',
                'ag.nombres',
                'ag.hash_dispositivo',
                'p.tienda_id as presencia_tienda_id',
                'p.device_hash as presencia_device_hash',
                'p.capturado_en as ultimo_ping',
            ])
            ->get();

        $tiendas = Schema::hasTable('tiendas')
            ? DB::table('tiendas')->get()->keyBy(fn (object $tienda) => (string) $tienda->codigo)
            : collect();

        $creadas = 0;
        $omitidas = 0;

        foreach ($turnos as $turno) {
            $inicioTurno = Carbon::parse("{$turno->fecha} {$turno->hora_ingreso}", $tz);
            $ultimoPing = $turno->ultimo_ping ? Carbon::parse($turno->ultimo_ping, $tz) : null;
            $sinPingVencido = $ultimoPing === null && $inicioTurno->lte($limite);
            $pingVencido = $ultimoPing !== null && $ultimoPing->lte($limite);

            if (! $sinPingVencido && ! $pingVencido) {
                $omitidas++;

                continue;
            }

            $incidenciaReciente = DB::table('asistencia_incidencias_ubicacion')
                ->where('agente_id', $turno->agente_id)
                ->where('tipo', 'sin_senal')
                ->where('created_at', '>=', $limite)
                ->exists();

            if ($incidenciaReciente) {
                $omitidas++;

                continue;
            }

            $tienda = $tiendas->get((string) $turno->asistencia_tienda_id);
            $tiendaId = $turno->presencia_tienda_id ?? $tienda?->id;
            $deviceHash = trim((string) ($turno->presencia_device_hash ?? $turno->hash_dispositivo ?? ''));

            DB::table('asistencia_incidencias_ubicacion')->insert([
                'agente_id' => $turno->agente_id,
                'tienda_id' => $tiendaId,
                'device_hash' => $deviceHash,
                'tipo' => 'sin_senal',
                'lat' => null,
                'lng' => null,
                'accuracy' => null,
                'distancia_metros' => null,
                'capturado_en' => $ultimoPing ?? $ahora,
                'created_at' => $ahora,
            ]);

            $this->line("[SIN SENAL] {$turno->nombres} (agente #{$turno->agente_id})");
            $creadas++;
        }

        $this->info("detectar-sin-senal: {$creadas} incidencia(s), {$omitidas} turno(s) omitido(s).");

        return self::SUCCESS;
    }
}
