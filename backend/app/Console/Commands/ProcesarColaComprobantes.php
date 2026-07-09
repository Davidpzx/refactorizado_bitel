<?php

namespace App\Console\Commands;

use App\Models\ComprobanteCola;
use App\Services\Facturacion\ProcesadorColaComprobantes;
use App\Services\Facturacion\ResultadoProceso;
use Illuminate\Console\Command;

/**
 * Drena `comprobantes_cola` contra la API externa de facturación.
 *
 * Port de `cron/procesar_cola_comprobantes.php` (legacy). Corre cada minuto desde
 * el scheduler. Tolerante a caídas de la API y de SUNAT: una fila que falla por
 * causa transitoria vuelve a la cola con backoff exponencial, nunca se descarta.
 *
 *   php artisan facturacion:procesar-cola [--dry-run] [--id=N] [--limit=N]
 */
class ProcesarColaComprobantes extends Command
{
    protected $signature = 'facturacion:procesar-cola
        {--dry-run : Lista las filas candidatas sin tocar la BD ni llamar a la API}
        {--id= : Procesa solo esta fila de la cola, si sigue siendo candidata}
        {--limit= : Máximo de filas por corrida (por defecto, facturacion.cola_limite)}';

    protected $description = 'Emite los comprobantes encolados contra la API externa de facturación, con backoff exponencial';

    public function handle(ProcesadorColaComprobantes $procesador): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limite = max(1, (int) ($this->option('limit') ?: config('facturacion.cola_limite', 20)));

        $filas = ComprobanteCola::query()
            ->pendientes()
            ->when($this->option('id'), fn ($q, $id) => $q->whereKey((int) $id))
            ->limit($limite)
            ->get();

        if ($filas->isEmpty()) {
            $this->info('[COLA-CPE] Sin comprobantes pendientes de emitir.');

            return self::SUCCESS;
        }

        $conteo = array_fill_keys([
            ProcesadorColaComprobantes::ACEPTADA,
            ProcesadorColaComprobantes::RECHAZADA,
            ProcesadorColaComprobantes::REINTENTAR,
            ProcesadorColaComprobantes::SALTADA,
            ProcesadorColaComprobantes::SIN_CONFIG,
            ProcesadorColaComprobantes::YA_EMITIDA,
        ], 0);

        foreach ($filas as $fila) {
            if ($dryRun) {
                $this->line($this->prefijo($fila)." intento={$this->proximoIntento($fila)} (dry-run, sin cambios)");
                continue;
            }

            $resultado = $procesador->procesar($fila);
            $conteo[$resultado->resultado]++;

            $this->reportar($resultado);
        }

        if ($dryRun) {
            $this->info("\n[COLA-CPE] RESUMEN dry-run: candidatas={$filas->count()} (sin cambios)");

            return self::SUCCESS;
        }

        $this->info("\n[COLA-CPE] RESUMEN: procesadas={$filas->count()} ".collect($conteo)
            ->map(fn (int $n, string $clave) => "{$clave}={$n}")
            ->implode(' '));

        return self::SUCCESS;
    }

    private function reportar(ResultadoProceso $resultado): void
    {
        $fila  = $resultado->cola;
        $linea = $this->prefijo($fila)." estado={$resultado->resultado} intentos={$fila->intentos}";

        if ($resultado->mensaje !== '') {
            $linea .= " mensaje=\"{$resultado->mensaje}\"";
        }

        match ($resultado->resultado) {
            ProcesadorColaComprobantes::ACEPTADA   => $this->info($linea.' numero='.$fila->numero_completo),
            ProcesadorColaComprobantes::RECHAZADA  => $this->error($linea),
            ProcesadorColaComprobantes::REINTENTAR => $this->warn($linea.' proximo_intento='.$fila->proximo_intento_at?->toDateTimeString()),
            default                                => $this->line($linea),
        };
    }

    private function prefijo(ComprobanteCola $fila): string
    {
        return "[COLA-CPE] id={$fila->getKey()} tipo={$fila->tipo_comprobante}";
    }

    private function proximoIntento(ComprobanteCola $fila): int
    {
        return $fila->intentos + 1;
    }
}
