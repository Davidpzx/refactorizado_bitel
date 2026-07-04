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
        $hoy       = now()->toDateString();
        $timestamp = now()->format('Y-m-d H:i:s');

        $this->info("[{$timestamp}] limpieza fotos asistencia iniciada. Corte: {$cutoff}");

        $errores = 0;

        // ── Sección A — Auto-aprobación de fotos pendientes de días anteriores ──
        // Paridad legacy cron/limpiar_fotos_asistencia.php: asistencias con
        // metodo_marcacion=FOTO y requiere_revision=1 de días ya pasados se
        // auto-aprueban para que no queden bloqueadas indefinidamente si
        // gerencia no las revisó a tiempo.
        $this->info('-- Sección A: auto-aprobación de fotos pendientes de días anteriores --');

        $pendientes = DB::table('asistencias')
            ->where('fecha', '<', $hoy)
            ->where('requiere_revision', 1)
            ->where('metodo_marcacion', 'FOTO')
            ->select('id', 'foto_marcacion')
            ->get();

        $autoAprobadas = 0;
        foreach ($pendientes as $row) {
            try {
                if (! empty($row->foto_marcacion) && Storage::disk('public')->exists($row->foto_marcacion)) {
                    Storage::disk('public')->delete($row->foto_marcacion);
                }

                DB::table('asistencias')
                    ->where('id', $row->id)
                    ->update(['requiere_revision' => 0, 'foto_marcacion' => null]);
                $autoAprobadas++;
            } catch (\Throwable $e) {
                $this->error("  [ERROR] Asistencia #{$row->id}: " . $e->getMessage());
                logger()->error('[bitel:limpiar-fotos] Sección A #' . $row->id . ': ' . $e->getMessage());
                $errores++;
            }
        }

        $this->info("Fotos auto-aprobadas (requiere_revision 1→0): {$autoAprobadas}");

        // ── Sección B — Limpieza de archivos con más de N días ──
        $this->info('-- Sección B: limpieza de registros con más de ' . $dias . ' días --');

        $registros = DB::table('asistencias')
            ->whereNotNull('foto_marcacion')
            ->where('foto_marcacion', '!=', '')
            ->where('fecha', '<', $cutoff)
            ->select('id', 'foto_marcacion')
            ->get();

        if ($registros->isEmpty()) {
            $this->info("Sin fotos antiguas. Nada que limpiar.");
            return $errores > 0 ? self::FAILURE : self::SUCCESS;
        }

        $eliminadas = 0;
        $faltantes  = 0;

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
