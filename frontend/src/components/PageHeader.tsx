import type { ComponentType, ReactNode } from 'react'

interface PageHeaderProps {
  title: string
  description?: string
  subtitle?: string
  actions?: ReactNode
  children?: ReactNode
  /** Acento del ícono / barrita lateral; por defecto oro Kyro */
  accent?: string
  /** Enables the gold treatment for a monetary header. */
  monetary?: boolean
  /**
   * Ícono (phosphor) a la izquierda del título, estilo legacy (`ph-fill`).
   * Cuando se pasa, reemplaza la barra vertical de acento.
   */
  Icon?: ComponentType<{ size?: number | string; className?: string }>
  /**
   * Línea divisoria degradada bajo el encabezado. Por defecto oculta para
   * alinear con el legacy (que no la tiene). Pásala `true` para conservarla.
   */
  divider?: boolean
}

export function PageHeader({
  title,
  description,
  subtitle,
  actions,
  children,
  monetary = false,
  accent = monetary ? '#ffc200' : '#6366f1',
  Icon,
  divider = false,
}: PageHeaderProps) {
  const sub = subtitle ?? description
  const slot = children ?? actions
  const normalizedAccent = accent.toLowerCase()
  const requestsGold = normalizedAccent.includes('gold') || normalizedAccent === '#ffc200'
  const resolvedAccent = requestsGold && !monetary ? '#6366f1' : accent
  return (
    <div className="mb-6">
      <div className="flex flex-col items-stretch justify-between gap-4 sm:flex-row sm:items-start">
        <div className="flex items-stretch gap-3 min-w-0">
          {Icon ? (
            <span
              aria-hidden
              className="flex h-10 w-10 shrink-0 items-center justify-center self-start rounded-xl"
              style={{
                color: resolvedAccent,
                background: `color-mix(in srgb, ${resolvedAccent} 12%, transparent)`,
                boxShadow: `0 0 16px -6px color-mix(in srgb, ${resolvedAccent} 55%, transparent)`,
              }}
            >
              <Icon size={22} />
            </span>
          ) : (
            <span
              aria-hidden
              className="w-1 rounded-full shrink-0 self-stretch"
              style={{
                background: `linear-gradient(180deg, ${resolvedAccent}, color-mix(in srgb, ${resolvedAccent} 20%, transparent))`,
                boxShadow: `0 0 12px -2px color-mix(in srgb, ${resolvedAccent} 55%, transparent)`,
              }}
            />
          )}
          <div className="min-w-0">
            <h1
              className="text-2xl leading-tight font-bold text-gray-900 dark:text-zinc-50 tracking-tight"
              style={{ fontFamily: "'Orbitron', sans-serif", letterSpacing: '0.02em' }}
            >
              {title}
            </h1>
            {sub && (
              <p className="mt-1 text-sm text-gray-500 dark:text-zinc-400 max-w-2xl">{sub}</p>
            )}
          </div>
        </div>
        {slot && <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">{slot}</div>}
      </div>
      {divider && (
        <div
          className="mt-4 h-px w-full"
          style={{
            background:
              `linear-gradient(90deg, color-mix(in srgb, ${resolvedAccent} 35%, transparent) 0%, rgba(148,163,184,0.12) 35%, transparent 100%)`,
          }}
        />
      )}
    </div>
  )
}
