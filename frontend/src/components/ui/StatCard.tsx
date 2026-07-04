import type { ReactNode } from 'react'

// Formateo de moneda PEN (mismo que usan las páginas de cuadre/dashboard).
const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

interface StatCardProps {
  /** Etiqueta pequeña uppercase (estilo legacy). */
  title: string
  /** Valor a mostrar. `null`/`undefined` renderiza un placeholder de carga. */
  value: ReactNode
  /** Color del acento (borde). Por defecto oro Kyro. */
  accent?: string
  /** Ícono opcional a la izquierda del contenido (variante `d-flex gap-3` del legacy). */
  icon?: ReactNode
  /** Posición del acento: `left` (border-left, por defecto) o `top` (border-top). */
  align?: 'left' | 'top'
  /** Clase Tailwind para el color del valor. */
  valueColorClass?: string
  /** Si es `true`, formatea un `value` numérico como moneda PEN. */
  formatMoney?: boolean
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
  valueColorClass = 'text-gray-900 dark:text-zinc-50',
  formatMoney = false,
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
  const borderStyle =
    align === 'top' ? { borderTop: `4px solid ${accent}` } : { borderLeft: `4px solid ${accent}` }

  return (
    <div
      className={`group relative overflow-hidden premium-kpi rounded-kyro-lg p-3 transition-all duration-200 hover:-translate-y-0.5 ${className}`}
      style={borderStyle}
    >
      <div
        aria-hidden
        className="absolute -right-8 -top-8 h-20 w-20 rounded-full bg-indigo-500/[0.05] blur-2xl dark:bg-indigo-500/[0.10]"
      />
      <div className={icon ? 'relative flex items-center gap-3' : 'relative'}>
        {icon && (
          <span className="shrink-0" style={{ color: accent }} aria-hidden>
            {icon}
          </span>
        )}
        <div className="min-w-0">
          <p className="text-[0.68rem] font-semibold uppercase leading-tight tracking-[0.08em] text-gray-500 dark:text-zinc-400">
            {title}
          </p>
          <p className={`mt-2 text-xl font-bold tracking-tight ${valueColorClass}`}>{display}</p>
        </div>
      </div>
    </div>
  )
}
