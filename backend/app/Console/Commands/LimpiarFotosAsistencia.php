<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LimpiarFotosAsistencia extends Command
{
    protected $signature   = 'bitel:limpiar-fotos {--dias=7 : Días de retención}';
    protected $description = 'Elimina fotos de asistencia con más de N días';

    public function handle(): int
    {
        $dias      = (int)$this->option('dias');
        $cutoff    = now()->subDays($dias)->toDateString();
        $timestamp = now()->format('Y-m-d H:i:s');

        $this->info("[{$timestamp}] limpieza fotos asistencia iniciada. Corte: {$cutoff}");

        $registros = DB::table('asistencias')
            ->whereNotNull('foto_marcacion')
            ->where('foto_marcacion', '!=', '')
            ->where('fecha', '<', $cutoff)
            ->select('id', 'foto_marcacion')
            ->get();

        if ($registros->isEmpty()) {
            $this->info("Sin fotos antiguas. Nada que limpiar.");
            return self::SUCCESS;
        }

        $eliminadas = 0;
        $faltantes  = 0;
        $errores    = 0;

        foreach ($registros as $row) {
            try {
                $ruta = $row->foto_marcacion;

                // Intentar eliminar desde storage público
                if (Storage::disk('public')->exists($ruta)) {
                    Storage::disk('public')->delete($ruta);
                    $eliminadas++;
                } else {
                    $faltantes++;
                }

                DB::table('asistencias')
                    ->where('id', $row->id)
                    ->update(['foto_marcacion' => null]);
            } catch (\Throwable $e) {
                $this->error("  [ERROR] Asistencia #{$row->id}: " . $e->getMessage());
                logger()->error('[bitel:limpiar-fotos] #' . $row->id . ': ' . $e->getMessage());
                $errores++;
            }
        }

        $this->info("Eliminadas: {$eliminadas} | Faltantes: {$faltantes} | Errores: {$errores}");
        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }
}
