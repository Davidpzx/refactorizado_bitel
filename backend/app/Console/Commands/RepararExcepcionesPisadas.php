<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Puerto de cron/reparar_excepciones_pisadas.php (legacy): repara filas de
 * asistencia PERMISO/FALTA_INJUSTIFICADA que el auto-cierre pisó dejándolas
 * como CIERRE_AUTO con una hora_salida falsa (bug de origen ya corregido en
 * SalidaAutomaticaAsistencias, que ahora excluye estas filas de su consulta).
 *
 * Herramienta de reparación puntual, no un cron recurrente: se ejecuta a mano
 * una sola vez para sanear datos que se corrompieron ANTES de la corrección.
 * Dry-run por defecto; requiere --apply para escribir.
 */
class RepararExcepcionesPisadas extends Command
{
    protected $signature = 'bitel:reparar-excepciones-pisadas {--apply : Aplica la reparación (por defecto es solo dry-run)}';

    protected $description = 'Repara filas PERMISO/FALTA_INJUSTIFICADA pisadas por el auto-cierre de asistencia';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('apply');

        // latitud_ingreso solo existe en instalaciones que adoptaron la tabla legacy
        // (ver rama de adopción del ticket-001); donde no exista, el sentinel
        // hora_ingreso='00:00:00' + estado_asistencia='CIERRE_AUTO' basta.
        $query = DB::table('asistencias as a')
            ->join('agentes as ag', 'ag.id', '=', 'a.agente_id')
            ->where('a.estado_asistencia', 'CIERRE_AUTO')
            ->where('a.hora_ingreso', '00:00:00');

        if (Schema::hasColumn('asistencias', 'latitud_ingreso')) {
            $query->where('a.latitud_ingreso', 'EXCEPCION');
        }

        $filas = $query
            ->select('a.id', 'a.agente_id', 'a.fecha', 'a.estado_asistencia', 'a.hora_salida', 'a.minutos_deuda', 'ag.nombres')
            ->orderBy('a.fecha')
            ->orderBy('ag.nombres')
            ->get();

        if ($filas->isEmpty()) {
            $this->info('No se encontraron filas candidatas. Nada que reparar.');

            return self::SUCCESS;
        }

        $totalPermiso = 0;
        $totalFalta   = 0;
        $reparadas    = 0;

        foreach ($filas as $fila) {
            $destino = ($fila->minutos_deuda >= 540) ? 'PERMISO' : 'FALTA_INJUSTIFICADA';
            $destino === 'PERMISO' ? $totalPermiso++ : $totalFalta++;

            $this->line(sprintf(
                '  #%d agente=%s (%s) fecha=%s %s -> %s',
                $fila->id,
                $fila->agente_id,
                $fila->nombres,
                $fila->fecha,
                $fila->estado_asistencia,
                $destino
            ));

            if ($aplicar) {
                $update = DB::table('asistencias')
                    ->where('id', $fila->id)
                    ->where('estado_asistencia', 'CIERRE_AUTO');

                if (Schema::hasColumn('asistencias', 'latitud_ingreso')) {
                    $update->where('latitud_ingreso', 'EXCEPCION');
                }

                $reparadas += $update->update(['estado_asistencia' => $destino, 'hora_salida' => null]);
            }
        }

        $total = $filas->count();

        if ($aplicar) {
            $this->info("RESUMEN: {$reparadas} filas reparadas de {$total} candidatas ({$totalPermiso} PERMISO, {$totalFalta} FALTA_INJUSTIFICADA).");
        } else {
            $this->info("RESUMEN: {$total} filas serían reparadas ({$totalPermiso} PERMISO, {$totalFalta} FALTA_INJUSTIFICADA).");
            $this->comment('MODO DRY-RUN: no se modificó nada. Ejecuta con --apply para aplicar.');
        }

        return self::SUCCESS;
    }
}
