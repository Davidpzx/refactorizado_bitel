<?php

namespace App\Services;

use App\Models\ComisionPlan;
use Illuminate\Support\Facades\DB;

/**
 * Motor de comisión SERVER-SIDE — paridad 1:1 con el legacy procesar_reporte.php (líneas 190-211, 261-301).
 *
 * El servidor es la autoridad: NO confía en el `comision_unitaria` que envía el cliente.
 * Busca el plan por `nombre_plan` (igual clave que usa el frontend) y aplica los tramos exactos.
 * Devuelve la comisión TOTAL de la línea (unidad × cantidad).
 */
class ComisionService
{
    private const COSTO_CHIP = 1.00;        // S/1 por chip físico
    private const REMATE_UMBRAL = 20.00;    // < S/20 = no comisionable (postpago)
    private const APOYO_PCT_PAQUETE = 0.075; // 7.5% del cobrado
    private const APOYO_UPGRADE_FLAT = 10.00;

    /** @var array<string, ?ComisionPlan> */
    private array $planCache = [];
    /** @var array<int, float> */
    private array $comisionEspecialCache = [];

    /**
     * Comisión total (S/) de una línea de venta normalizada del payload del reporte.
     *
     * @param array<string, mixed> $venta
     */
    public function calcularComisionVenta(array $venta): float
    {
        $tipo = strtoupper((string) ($venta['tipo_venta'] ?? ''));
        $cantidad = max(1, (int) ($venta['cantidad'] ?? 1));

        // ── Equipos / Accesorios / Otros flujo ──────────────────────────────────
        if ($tipo === 'ACCESORIO' || $tipo === 'OTROS_FLUJO') {
            return 0.0;
        }
        if ($tipo === 'EQUIPO') {
            if (strtoupper((string) ($venta['tipo_pago'] ?? 'CONTADO')) === 'CUOTAS') {
                return 0.0; // diferida hasta confirmar desembolso (PENDIENTE)
            }
            $especial = $this->comisionEspecial((int) ($venta['inventario_tienda_id'] ?? 0));

            return $especial > 0 ? round($especial, 2) : 0.0; // rango EQUIPO se aplica en planilla
        }

        // ── Líneas: POSTPAGO / PREPAGO / APOYO ──────────────────────────────────
        $plan = $this->buscarPlan((string) ($venta['plan_nombre'] ?? ''));
        $esExtranjero = (bool) ($venta['es_extranjero'] ?? false);
        $esMigracion = (bool) ($venta['es_migracion'] ?? false);
        $esUpgrade = (bool) ($venta['es_upgrade'] ?? false);
        $esEsim = (bool) ($venta['es_esim'] ?? false);
        $costoChip = ($esMigracion || $esUpgrade || $esEsim) ? 0.0 : self::COSTO_CHIP;
        $base = $plan ? (float) ($esExtranjero ? $plan->comision_ext_n : $plan->comision_dni_n) : 0.0;
        $cobrado = (float) ($venta['cobrado_unitario'] ?? $venta['monto_total'] ?? 0);

        // Apoyo inter-tienda (cross-selling)
        if ($tipo === 'APOYO') {
            if (strtoupper((string) ($venta['subtipo'] ?? '')) === 'PAQUETE') {
                return round($cobrado * self::APOYO_PCT_PAQUETE, 2);
            }
            if ($esUpgrade) {
                return self::APOYO_UPGRADE_FLAT;
            }
            if ($cobrado > 0 && $cobrado < self::REMATE_UMBRAL) {
                return 0.0; // remate
            }

            return round(max(0.0, $base - $costoChip), 2);
        }

        // Postpago / Prepago
        $esPrepago = $tipo === 'PREPAGO'
            || ($plan && strtoupper((string) $plan->tipo_servicio) === 'PREPAGO');
        $esRemate = ! $esPrepago && ! $esUpgrade && $cobrado > 0 && $cobrado < self::REMATE_UMBRAL;

        if ($esRemate) {
            $unidad = 0.0;
        } elseif ($esUpgrade) {
            $diff = ($plan ? (float) $plan->fee_monto : 0.0) - (float) ($venta['plan_anterior'] ?? 0);
            $unidad = $diff >= 20 ? 20.0 : ($diff >= 10 ? 10.0 : 0.0);
        } else {
            // Postpago normal y Prepago: max(0, base - chip); el cobrado no influye en prepago
            $unidad = max(0.0, $base - $costoChip);
        }

        return round($unidad * $cantidad, 2);
    }

    private function buscarPlan(string $nombre): ?ComisionPlan
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            return null;
        }
        if (! array_key_exists($nombre, $this->planCache)) {
            $this->planCache[$nombre] = ComisionPlan::where('nombre_plan', $nombre)->first();
        }

        return $this->planCache[$nombre];
    }

    private function comisionEspecial(int $inventarioTiendaId): float
    {
        if ($inventarioTiendaId <= 0) {
            return 0.0;
        }
        if (! array_key_exists($inventarioTiendaId, $this->comisionEspecialCache)) {
            $val = DB::table('inventario_tiendas')->where('id', $inventarioTiendaId)->value('comision_especial');
            $this->comisionEspecialCache[$inventarioTiendaId] = (float) ($val ?? 0);
        }

        return $this->comisionEspecialCache[$inventarioTiendaId];
    }
}
