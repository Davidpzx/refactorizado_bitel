<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoRetornoAgentes extends Command
{
    protected $signature   = 'bitel:auto-retorno';
    protected $description = 'Reactiva agentes con permiso largo cuya fecha de retorno ya llegó';

    public function handle(): int
    {
        $hoy = now()->toDateString();

        $agentes = DB::table('agentes')
            ->where('estado', 'INACTIVO')
            ->where('permiso_largo', 1)
            ->whereNotNull('fecha_retorno')
            ->where('fecha_retorno', '<=', $hoy)
            ->select('id', 'nombres', 'tienda_base', 'fecha_retorno')
            ->get();

        if ($agentes->isEmpty()) {
            $this->info("[{$hoy}] auto_retorno: 0 agentes a reactivar. OK.");
            return self::SUCCESS;
        }

        // Asegurar tabla historial_agentes
        if (!DB::getSchemaBuilder()->hasTable('historial_agentes')) {
            DB::statement("
                CREATE TABLE historial_agentes (
                    id INT NOT NULL AUTO_INCREMENT,
                    id_agente INT NOT NULL,
                    estado VARCHAR(20) NOT NULL,
                    clasificacion_baja VARCHAR(20) NULL DEFAULT NULL,
                    observacion TEXT NULL DEFAULT NULL,
                    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_id_agente (id_agente)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $reactivados = 0;
        $errores     = 0;

        foreach ($agentes as $ag) {
            try {
                DB::table('agentes')->where('id', $ag->id)->update([
                    'estado'              => 'ACTIVO',
                    'permiso_largo'       => 0,
                    'clasificacion_baja'  => null,
                    'motivo_baja'         => null,
                    'fecha_baja'          => null,
                    'fecha_retorno'       => null,
                    'observacion'         => null,
                ]);

                DB::table('historial_agentes')->insert([
                    'id_agente'   => $ag->id,
                    'estado'      => 'ACTIVO',
                    'observacion' => "Reactivación automática programada. Fecha retorno: {$ag->fecha_retorno}",
                ]);

                $this->info("[{$hoy}] REACTIVADO: {$ag->nombres} (ID {$ag->id}) · Tienda: {$ag->tienda_base} · Retorno: {$ag->fecha_retorno}");
                $reactivados++;
            } catch (\Throwable $e) {
                $this->error("[{$hoy}] ERROR al reactivar ID {$ag->id}: " . $e->getMessage());
                logger()->error('[bitel:auto-retorno] ID ' . $ag->id . ': ' . $e->getMessage());
                $errores++;
            }
        }

        $this->info("[{$hoy}] auto_retorno: {$reactivados} reactivados, {$errores} errores.");
        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }
}
