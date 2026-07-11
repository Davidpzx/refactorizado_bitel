import type { ReactNode } from 'react'
import { CaretDown, CaretUp } from '@phosphor-icons/react'
import { CardTopAccent, type TopAccentColor } from './CardTopAccent'
import { Sparkline } from './Sparkline'

// Formateo de moneda PEN (mismo que usan las páginas de cuadre/dashboard).
const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

interface StatCardProps {
  /** Etiqueta pequeña uppercase (estilo legacy). */
  title: string
  /** Valor a mostrar. `null`/`undefined` renderiza un placeholder de carga. */
  value: ReactNode
  /** Color del acento (borde). Por defecto oro Kyro. Ignorado si se pasa `topAccentColor`. */
  accent?: string
  /** Ícono opcional a la izquierda del contenido (variante `d-flex gap-3` del legacy). */
  icon?: ReactNode
  /** Posición del acento: `left` (border-left, por defecto) o `top` (border-top). Ignorado si se pasa `topAccentColor`. */
  align?: 'left' | 'top'
  /** Si se define, reemplaza el borde plano por el hairline con glow de `CardTopAccent` (patrón ticket-021). */
  topAccentColor?: TopAccentColor
  /** Clase Tailwind para el color del valor. */
  valueColorClass?: string
  /** Si es `true`, formatea un `value` numérico como moneda PEN. */
  formatMoney?: boolean
  /** Línea muted bajo el valor (estilo legacy: "Desembolsos aprobados", "0 ventas — 2026-07"). */
  subtitle?: ReactNode
  /** Serie cronológica para el sparkline de tendencia (mínimo 2 puntos). */
  trend?: number[]
  /**
   * Variación vs periodo anterior. Número = porcentaje (+12 → "▲ 12%", verde;
   * -5 → "▼ 5%", rojo). String = texto libre neutro ("2 de alta prioridad").
   */
  delta?: number | string
  /** Etiqueta muted tras el delta ("vs mes anterior"). */
  deltaLabel?: string
  className?: string
}

/**
 * Tarjeta de métrica (KPI) compartida. Reproduce el patrón del legacy sis_bipay:
 * acento de 4px (izquierda o superior), etiqueta uppercase muted y valor grande bold,
 * con el glow sutil del refactor. Extraído del `KpiCard` local de `DashboardPage`.
 */
export function StatCard({
  title,
  value,
  accent = '#ffc200',
  icon,
  align = 'left',
  topAccentColor,
  valueColorClass = 'text-gray-900 dark:text-zinc-50',
  formatMoney = false,
  subtitle,
  trend,
  delta,
  deltaLabel,
  className = '',
}: StatCardProps) {
  const isLoading = value === null || value === undefined
  const display = isLoading ? (
    <span className="text-gray-300 animate-pulse">···</span>
  ) : formatMoney ? (
    pen.format(Number(value))
  ) : (
    value
  )
  const borderStyle = topAccentColor
    ? undefined
    : align === 'top'
      ? { borderTop: `4px solid ${accent}` }
      : { borderLeft: `4px solid ${accent}` }

  return (
    <div
      className={`group relative overflow-hidden premium-kpi rounded-kyro-xl p-4 transition-all duration-200 hover:-translate-y-0.5 ${className}`}
      style={borderStyle}
    >
      {topAccentColor && <CardTopAccent color={topAccentColor} />}
      <div
        aria-hidden
        className="absolute -right-8 -top-8 h-20 w-20 rounded-full bg-indigo-500/[0.05] blur-2xl dark:bg-indigo-500/[0.10]"
      />
      <div className={icon || trend ? 'relative flex items-center gap-3' : 'relative'}>
        {icon && (
          <span className="shrink-0" style={{ color: accent }} aria-hidden>
            {icon}
          </span>
        )}
        <div className="min-w-0 flex-1">
          <p className="text-[0.68rem] font-semibold uppercase leading-tight tracking-[0.08em] text-gray-500 dark:text-zinc-400">
            {title}
          </p>
          <p className={`mt-2 text-xl font-bold tracking-tight ${formatMoney ? 'kyro-money' : ''} ${valueColorClass}`}>
            {display}
          </p>
          {delta !== undefined && (
            <p className="mt-1 flex items-center gap-1 text-[0.7rem] leading-tight">
              {typeof delta === 'number' ? (
                <>
                  <span
                    className={`inline-flex items-center gap-0.5 font-semibold ${
                      delta >= 0 ? 'text-kyro-success' : 'text-kyro-danger'
                    }`}
                  >
                    {delta >= 0 ? <CaretUp size={10} weight="bold" /> : <CaretDown size={10} weight="bold" />}
                    {Math.abs(delta).toLocaleString('es-PE', { maximumFractionDigits: 1 })}%
                  </span>
                  {deltaLabel && <span className="text-gray-500 dark:text-zinc-500">{deltaLabel}</span>}
                </>
              ) : (
                <span className="text-gray-500 dark:text-zinc-500">{delta}{deltaLabel ? ` ${deltaLabel}` : ''}</span>
              )}
            </p>
          )}
          {subtitle && (
            <p className="mt-1 truncate text-[0.7rem] text-gray-500 dark:text-zinc-400">{subtitle}</p>
          )}
        </div>
        {trend && trend.length >= 2 && (
          <div className="pointer-events-none shrink-0 self-end" style={{ color: accent }}>
            <Sparkline data={trend} />
          </div>
        )}
      </div>
    </div>
  )
}
