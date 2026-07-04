<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regla única para bloquear operaciones que revertirían una comisión ya
 * PAGADA en una planilla cerrada (usada por InventarioController::restaurar
 * y ReporteController::destroy: ambos revierten stock/comisión y no deben
 * hacerlo si el agente ya cobró esa comisión en una boleta cerrada).
 */
class PlanillaGuard
{
    public static function boletaPagada(int $agenteId, string $fecha): ?object
    {
        if (! Schema::hasTable('pagos_planilla')) {
            return null;
        }

        return DB::table('pagos_planilla')
            ->where('agente_id', $agenteId)
            ->where('estado', 'PAGADO')
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->whereDate('fecha_fin', '>=', $fecha)
            ->first();
    }
}
