import type { ReactNode } from 'react'
import { AlertTriangle, Info } from 'lucide-react'

export type AlertBannerTone = 'danger' | 'warning' | 'info'

const TONE_ACCENT: Record<AlertBannerTone, string> = {
  danger: '#ef4444',
  warning: '#ffc200',
  info: '#06b6d4',
}

const TONE_ICON: Record<AlertBannerTone, ReactNode> = {
  danger: <AlertTriangle size={16} />,
  warning: <AlertTriangle size={16} />,
  info: <Info size={16} />,
}

interface AlertBannerProps {
  tone?: AlertBannerTone
  title: ReactNode
  subtitle?: ReactNode
  icon?: ReactNode
  /** Slot derecho: badge de conteo, botón, etc. (estilo legacy: "15 productos estancados"). */
  action?: ReactNode
  className?: string
}

/**
 * Banner de alerta del legacy (Stock Estancado, Monitor de Fraude): fondo
 * tintado + borde-izq del color semántico, ícono en caja circular, título
 * bold coloreado y subtítulo muted. Reemplaza los banners grises planos.
 */
export function AlertBanner({
  tone = 'danger',
  title,
  subtitle,
  icon,
  action,
  className = '',
}: AlertBannerProps) {
  const accent = TONE_ACCENT[tone]
  const displayIcon = icon ?? TONE_ICON[tone]

  return (
    <div
      className={`flex items-center justify-between gap-4 rounded-kyro-lg border px-4 py-3 ${className}`}
      style={{
        borderColor: `color-mix(in srgb, ${accent} 40%, transparent)`,
        borderLeftWidth: '4px',
        background: `color-mix(in srgb, ${accent} 8%, transparent)`,
      }}
    >
      <div className="flex min-w-0 items-start gap-2.5">
        <span
          className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
          style={{ background: `color-mix(in srgb, ${accent} 20%, transparent)`, color: accent }}
          aria-hidden
        >
          {displayIcon}
        </span>
        <div className="min-w-0">
          <p className="text-sm font-bold" style={{ color: accent }}>
            {title}
          </p>
          {subtitle && (
            <p className="mt-0.5 text-[0.75rem] text-gray-500 dark:text-zinc-400">{subtitle}</p>
          )}
        </div>
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}
