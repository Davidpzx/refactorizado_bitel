<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SalidaAutomaticaAsistencias extends Command
{
    protected $signature   = 'bitel:salida-automatica';
    protected $description = 'Cierra automáticamente turnos de asistencia sin hora de salida';

    /** Espera tras la hora de salida programada antes de auto-cerrar (paridad legacy). */
    private const ESPERA_MINUTOS = 90;

    public function handle(): int
    {
        // El módulo de asistencia trabaja en hora Lima; alineamos el cron a esa zona.
        $tz        = config('asistencia.timezone', 'America/Lima');
        $ahora     = now()->timezone($tz);
        $fechaHoy  = $ahora->toDateString();
        $horaAhora = $ahora->format('H:i:s');

        $this->info("[{$fechaHoy} {$horaAhora}] ── bitel:salida-automatica iniciado ──");

        // Asegurar tabla sys_notificaciones
        if (!DB::getSchemaBuilder()->hasTable('sys_notificaciones')) {
            DB::statement("
                CREATE TABLE sys_notificaciones (
                    id             INT NOT NULL AUTO_INCREMENT,
                    tipo           VARCHAR(50) NOT NULL DEFAULT 'alerta_asistencia',
                    mensaje        TEXT NOT NULL,
                    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    estado         ENUM('pendiente','leido') NOT NULL DEFAULT 'pendiente',
                    PRIMARY KEY (id),
                    KEY idx_estado (estado),
                    KEY idx_fecha  (fecha_creacion)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // fecha <= hoy: también cierra turnos que quedaron abiertos días anteriores
        // (incluye turnos nocturnos que cruzan medianoche). Igual que el legacy.
        //
        // Excluir filas de excepción (PERMISO/FALTA_INJUSTIFICADA, registradas por
        // AsistenciaController::registrarExcepcion con hora_ingreso='00:00:00' y
        // latitud_ingreso='EXCEPCION' como sentinela): el legacy tuvo el bug de que
        // el auto-cierre las pisaba, sobrescribiéndolas con CIERRE_AUTO y una
        // hora_salida falsa (por eso existía cron/reparar_excepciones_pisadas.php
        // como reparación manual). Aquí se previene en origen, sin necesitar el
        // script de reparación.
        $abiertos = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->where('a.fecha', '<=', $fechaHoy)
            ->whereNull('a.hora_salida')
            ->whereNotNull('a.hora_ingreso')
            ->where(function ($q) {
                $q->whereNull('a.estado_asistencia')
                    ->orWhereNotIn('a.estado_asistencia', ['PERMISO', 'FALTA_INJUSTIFICADA']);
            })
            ->where('ag.estado', 'ACTIVO')
            ->select(
                'a.id as asistencia_id',
                'a.agente_id',
                'a.fecha',
                'a.hora_ingreso',
                'a.inicio_refrigerio',
                'a.fin_refrigerio',
                'ag.nombres',
                'ag.tienda_base',
                'ag.hora_salida as hora_salida_prog'
            )
            ->get();

        if ($abiertos->isEmpty()) {
            $this->info("[{$fechaHoy}] salida-automatica: 0 turnos abiertos. OK.");
            return self::SUCCESS;
        }

        $cerrados = 0;
        $errores  = 0;

        foreach ($abiertos as $row) {
            try {
                $horaSalidaProg = $row->hora_salida_prog ?? $horaAhora;

                // Resguardo de horario inválido: si la salida programada del agente es
                // anterior o igual a su hora de ingreso real, el horario está mal
                // configurado (típico error AM/PM al editar el perfil, o turno nocturno
                // sin soporte de cruce de medianoche). NO cerramos con un dato corrupto:
                // alertamos a gerencia (una sola vez) y dejamos el turno abierto.
                if ($horaSalidaProg <= $row->hora_ingreso) {
                    $marcador = "[agente:{$row->agente_id}]";
                    $yaNotificado = DB::table('sys_notificaciones')
                        ->where('tipo', 'horario_invalido')
                        ->where('estado', 'pendiente')
                        ->where('mensaje', 'like', "%{$marcador}%")
                        ->exists();

                    if (!$yaNotificado) {
                        DB::table('sys_notificaciones')->insert([
                            'tipo'    => 'horario_invalido',
                            'mensaje' => "{$marcador} Horario de salida ({$horaSalidaProg}) de {$row->nombres} ({$row->tienda_base}) es anterior o igual a su hora de ingreso ({$row->hora_ingreso}). Corrige su horario en el perfil del agente; no se realizó el auto-cierre.",
                        ]);
                    }

                    $this->line("  [HORARIO INVALIDO] {$row->nombres} | salida prog {$horaSalidaProg} <= ingreso {$row->hora_ingreso} — se notificó a gerencia, no se cerró.");
                    continue;
                }

                // Espera de 90 min: para turnos de HOY, no cerrar hasta que la hora actual
                // supere la hora de salida programada + 90 min. Los turnos de días
                // anteriores llevan >24h abiertos, se cierran de inmediato.
                if ($row->fecha === $fechaHoy) {
                    $limite = Carbon::parse("{$fechaHoy} {$horaSalidaProg}", $tz)
                        ->addMinutes(self::ESPERA_MINUTOS);

                    if ($ahora->lt($limite)) {
                        $faltan = (int) ceil($ahora->diffInMinutes($limite, false));
                        $this->line("  [ESPERA] {$row->nombres} | Salida prog: {$horaSalidaProg} — faltan {$faltan} min para el límite.");
                        continue;
                    }
                }

                // Cerrar refrigerio abierto
                if ($row->inicio_refrigerio && !$row->fin_refrigerio) {
                    DB::table('asistencias')
                        ->where('id', $row->asistencia_id)
                        ->update(['fin_refrigerio' => $horaAhora]);
                    $this->line("  [REF CERRADO] {$row->nombres} — refrigerio sin fin corregido.");
                }

                // Cerrar con la hora de salida programada (ya validada arriba).
                $horaCierre = $horaSalidaProg;
                $affected   = DB::table('asistencias')
                    ->where('id', $row->asistencia_id)
                    ->whereNull('hora_salida')
                    ->update([
                        'hora_salida'       => $horaCierre,
                        'estado_asistencia' => 'CIERRE_AUTO',
                    ]);

                if ($affected === 0) {
                    $this->line("  [SKIP] Asistencia #{$row->asistencia_id} ya tenía salida.");
                    continue;
                }

                DB::table('sys_notificaciones')->insert([
                    'tipo'    => 'alerta_asistencia',
                    'mensaje' => "El sistema cerró automáticamente el turno de {$row->nombres} ({$row->tienda_base}). No marcó salida.",
                ]);

                $this->line("  [CERRADO] {$row->nombres} | #{$row->asistencia_id} | Cierre: {$horaCierre}");
                $cerrados++;
            } catch (\Throwable $e) {
                $this->error("  [ERROR] Asistencia #{$row->asistencia_id}: " . $e->getMessage());
                logger()->error('[bitel:salida-automatica] #' . $row->asistencia_id . ': ' . $e->getMessage());
                $errores++;
            }
        }

        $this->info("[{$fechaHoy}] salida-automatica: {$cerrados} cerrado(s), {$errores} error(es).");
        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }
}
