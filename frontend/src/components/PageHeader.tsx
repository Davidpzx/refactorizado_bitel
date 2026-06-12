import type { ReactNode } from 'react'

interface PageHeaderProps {
  title: string
  description?: string
  subtitle?: string
  actions?: ReactNode
  children?: ReactNode
  /** Acento del barrita lateral; por defecto oro Kyro */
  accent?: string
}

export function PageHeader({
  title,
  description,
  subtitle,
  actions,
  children,
  accent = '#ffc200',
}: PageHeaderProps) {
  const sub = subtitle ?? description
  const slot = children ?? actions
  return (
    <div className="mb-6">
      <div className="flex flex-col items-stretch justify-between gap-4 sm:flex-row sm:items-start">
        <div className="flex items-stretch gap-3 min-w-0">
          <span
            aria-hidden
            className="w-1 rounded-full shrink-0 self-stretch"
            style={{
              background: `linear-gradient(180deg, ${accent}, ${accent}33)`,
              boxShadow: `0 0 12px -2px ${accent}88`,
            }}
          />
          <div className="min-w-0">
            <h1
              className="text-[1.35rem] leading-tight font-bold text-gray-900 dark:text-zinc-50 tracking-tight"
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
      <div
        className="mt-4 h-px w-full"
        style={{
          background:
            'linear-gradient(90deg, rgba(255,194,0,0.35) 0%, rgba(255,255,255,0.06) 35%, transparent 100%)',
        }}
      />
    </div>
  )
}
