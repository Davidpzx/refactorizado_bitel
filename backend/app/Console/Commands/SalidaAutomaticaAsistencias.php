<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SalidaAutomaticaAsistencias extends Command
{
    protected $signature   = 'bitel:salida-automatica';
    protected $description = 'Cierra automáticamente turnos de asistencia sin hora de salida';

    public function handle(): int
    {
        $fechaHoy  = now()->toDateString();
        $horaAhora = now()->format('H:i:s');

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

        $abiertos = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->where('a.fecha', $fechaHoy)
            ->whereNull('a.hora_salida')
            ->where('ag.estado', 'ACTIVO')
            ->select(
                'a.id as asistencia_id',
                'a.agente_id',
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
                // Cerrar refrigerio abierto
                if ($row->inicio_refrigerio && !$row->fin_refrigerio) {
                    DB::table('asistencias')
                        ->where('id', $row->asistencia_id)
                        ->update(['fin_refrigerio' => $horaAhora]);
                    $this->line("  [REF CERRADO] {$row->nombres} — refrigerio sin fin corregido.");
                }

                $horaCierre = $row->hora_salida_prog ?? $horaAhora;
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
