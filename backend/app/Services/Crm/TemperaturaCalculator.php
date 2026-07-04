<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\DB;

/**
 * Puerto 1:1 de crmCalcularTemperatura() en gerencia/crm_dashboard.php del legacy sis_bipay.
 * Las 3 reglas se evalúan en orden fijo; la primera que matchea gana (sin combinarlas).
 */
class TemperaturaCalculator
{
    public const COLORES = [
        'Caliente'  => ['bg' => 'rgba(34,197,94,.15)',   'text' => '#4ade80', 'border' => 'rgba(34,197,94,.35)'],
        'Frío'      => ['bg' => 'rgba(239,68,68,.15)',   'text' => '#f87171', 'border' => 'rgba(239,68,68,.35)'],
        'Upselling' => ['bg' => 'rgba(251,191,36,.15)',  'text' => '#fbbf24', 'border' => 'rgba(251,191,36,.35)'],
        'Neutro'    => ['bg' => 'rgba(148,163,184,.12)', 'text' => '#94a3b8', 'border' => 'rgba(148,163,184,.25)'],
    ];

    public function calcular(string $dni): array
    {
        // 1. Caliente: interacción sin rechazo en las últimas 48 horas.
        $caliente = DB::table('crm_interacciones as i')
            ->join('crm_clientes as c', 'c.id', '=', 'i.cliente_id')
            ->where('c.dni', $dni)
            ->whereNull('i.motivo_rechazo')
            ->where('i.fecha_hora', '>=', now()->subHours(48))
            ->exists();
        if ($caliente) {
            return $this->etiqueta('Caliente');
        }

        // 2. Frío: rechazo por evaluación crediticia, sin ventana de tiempo.
        $frio = DB::table('crm_interacciones as i')
            ->join('crm_clientes as c', 'c.id', '=', 'i.cliente_id')
            ->where('c.dni', $dni)
            ->where('i.motivo_rechazo', 'Evaluación Crediticia')
            ->exists();
        if ($frio) {
            return $this->etiqueta('Frío');
        }

        // 3. Upselling: consulta Prepago de hace más de 30 días.
        $upselling = DB::table('crm_interacciones as i')
            ->join('crm_clientes as c', 'c.id', '=', 'i.cliente_id')
            ->where('c.dni', $dni)
            ->where('i.tipo_operacion', 'like', '%Prepago%')
            ->where('i.fecha_hora', '<=', now()->subDays(30))
            ->exists();
        if ($upselling) {
            return $this->etiqueta('Upselling');
        }

        return $this->etiqueta('Neutro');
    }

    private function etiqueta(string $etiqueta): array
    {
        return array_merge(['etiqueta' => $etiqueta], self::COLORES[$etiqueta]);
    }
}
