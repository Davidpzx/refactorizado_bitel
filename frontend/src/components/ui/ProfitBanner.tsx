import type { ReactNode } from 'react'
import { TrendingUp } from 'lucide-react'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

interface ProfitBannerProps {
  title: string
  subtitle?: ReactNode
  /** Valor a mostrar a la derecha. Si es `number`, se formatea como moneda PEN. */
  value: ReactNode | number
  accent?: string
  icon?: ReactNode
  className?: string
}

/**
 * Banda ancha "GANANCIA TOTAL DEL PERIODO" del legacy: acento izquierdo,
 * título + subtítulo a la izquierda, valor grande a la derecha.
 */
export function ProfitBanner({
  title,
  subtitle,
  value,
  accent = '#10b981',
  icon = <TrendingUp size={16} />,
  className = '',
}: ProfitBannerProps) {
  const display = typeof value === 'number' ? pen.format(value) : value

  return (
    <div
      className={`premium-kpi flex items-center justify-between gap-4 rounded-kyro-lg px-4 py-3 ${className}`}
      style={{ borderLeft: `4px solid ${accent}` }}
    >
      <div className="min-w-0">
        <p
          className="flex items-center gap-1.5 text-[0.72rem] font-bold uppercase tracking-[0.08em]"
          style={{ color: accent }}
        >
          {icon}
          {title}
        </p>
        {subtitle && (
          <p className="mt-1 truncate text-[0.75rem] text-gray-500 dark:text-zinc-400">{subtitle}</p>
        )}
      </div>
      <p className="shrink-0 text-2xl font-bold tracking-tight" style={{ color: accent }}>
        {display}
      </p>
    </div>
  )
}
