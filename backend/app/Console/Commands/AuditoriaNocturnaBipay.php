<?php

namespace App\Console\Commands;

use App\Services\AuditoriaBipayService;
use Illuminate\Console\Command;

/**
 * Puerto de cron/cron_auditoria_nocturna.php del legacy sis_bipay:
 * fuerza el cruce Bipay (declarado vs scrapeado) de todas las tiendas.
 */
class AuditoriaNocturnaBipay extends Command
{
    protected $signature = 'bitel:auditoria-nocturna {fecha? : Fecha a auditar (YYYY-MM-DD, default hoy)}';

    protected $description = 'Ejecuta el cruce de auditoría Bipay de todas las tiendas para una fecha';

    public function handle(AuditoriaBipayService $auditoria): int
    {
        $fecha = $this->argument('fecha') ?: now()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $this->error("Fecha inválida: {$fecha}");
            return self::FAILURE;
        }

        $this->info("Auditoría nocturna Bipay — {$fecha}");
        $resultados = $auditoria->ejecutarCruceDiario($fecha);

        foreach ($resultados as $tienda => $r) {
            $this->line(sprintf(
                '  %s → %s (dif: %s)',
                $tienda,
                $r['estado'] ?? $r['status'],
                isset($r['diferencia']) ? 'S/ ' . number_format($r['diferencia'], 2) : '—'
            ));
        }

        $this->info('Tiendas auditadas: ' . count($resultados));
        return self::SUCCESS;
    }
}
