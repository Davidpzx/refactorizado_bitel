<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Exclusiones compartidas del ranking/comisión de agentes.
 *
 * Fuente única de verdad de "qué venta cuenta para el ranking": excluye remates,
 * paquetes y upgrades. Esta condición ya la aplicaba PlanillaController al calcular
 * la comisión de planes (y PostpagoController al segregar KPIs), pero
 * EstadisticasController no la aplicaba a su ranking de agentes — inconsistencia
 * interna. Se centraliza aquí para no mantener una tercera variante.
 *
 * Opera sobre el query builder (ambos controladores usan DB::table, no Eloquent),
 * recibiendo el alias de la tabla `ventas` ('ventas' en Planilla, 'v' en Estadísticas).
 */
class RankingVentaScope
{
    /** Excluye remates y paquetes (nivel venta). */
    public static function excluirRematesYPaquetes(Builder $query, string $alias = 'ventas'): Builder
    {
        return $query
            ->where("{$alias}.es_remate", false)
            ->where(fn (Builder $q) => $q
                ->whereNull("{$alias}.subtipo")
                ->orWhere("{$alias}.subtipo", '!=', 'PAQUETE'));
    }

    /** Excluye ventas cuya línea es UPGRADE (vive en venta_lineas.tipo_alta). */
    public static function excluirUpgrades(Builder $query, string $alias = 'ventas'): Builder
    {
        return $query->whereNotExists(fn (Builder $q) => $q
            ->from('venta_lineas as vl_upg')
            ->whereColumn('vl_upg.venta_id', "{$alias}.id")
            ->whereRaw('UPPER(vl_upg.tipo_alta) LIKE ?', ['%UPGRADE%']));
    }

    /** Aplica todas las exclusiones del ranking de agentes. */
    public static function aplicar(Builder $query, string $alias = 'ventas'): Builder
    {
        return static::excluirUpgrades(
            static::excluirRematesYPaquetes($query, $alias),
            $alias
        );
    }
}
