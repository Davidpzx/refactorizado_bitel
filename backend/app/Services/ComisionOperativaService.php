<?php

namespace App\Services;

use App\Models\Reporte;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ComisionOperativaService
{
    public function recalcularReporte(Reporte $reporte): int
    {
        if (! Schema::hasTable('reporte_comisiones_operativas')) {
            return 0;
        }

        $bases = [
            'recargas' => (float) $reporte->recarga_bipay,
            'bipay' => (float) $reporte->bipay,
            'krece' => (float) $reporte->pago_krece,
            'payjoy' => (float) ($reporte->pago_payjoy ?? 0),
        ];

        $actualizados = 0;
        foreach ($bases as $tipo => $montoBase) {
            if ($montoBase <= 0) {
                DB::table('reporte_comisiones_operativas')
                    ->where('reporte_id', $reporte->id)
                    ->where('tipo_servicio', $tipo)
                    ->delete();
                continue;
            }

            $ganancia = $tipo === 'recargas'
                ? $this->gananciaRecargas($montoBase)
                : $this->gananciaPorRango($tipo, $montoBase);

            DB::table('reporte_comisiones_operativas')->updateOrInsert(
                ['reporte_id' => $reporte->id, 'tipo_servicio' => $tipo],
                [
                    'monto_base' => round($montoBase, 2),
                    'ganancia' => round($ganancia, 2),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $actualizados++;
        }

        return $actualizados;
    }

    private function gananciaRecargas(float $monto): float
    {
        if (! Schema::hasTable('config_comisiones')) {
            return 0.0;
        }

        $porcentaje = (float) (DB::table('config_comisiones')
            ->where('tipo', 'ganancia_recargas')
            ->value('monto') ?? 0);

        return round($monto * ($porcentaje / 100), 2);
    }

    private function gananciaPorRango(string $tipo, float $monto): float
    {
        if (Schema::hasTable('comisiones_rangos')) {
            $rango = DB::table('comisiones_rangos')
                ->where('tipo_servicio', $tipo)
                ->where('monto_min', '<=', $monto)
                ->where(function ($query) use ($monto) {
                    $query->whereNull('monto_max')->orWhere('monto_max', '>=', $monto);
                })
                ->orderBy('monto_min')
                ->first();

            if ($rango) {
                return (float) $rango->ganancia;
            }
        }

        if (! Schema::hasTable('config_comisiones')) {
            return 0.0;
        }

        return (float) (DB::table('config_comisiones')
            ->where('tipo', 'ganancia_'.$tipo)
            ->value('monto') ?? 0);
    }
}
