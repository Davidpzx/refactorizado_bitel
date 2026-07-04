import type { ReactNode } from 'react'
import { SlidersHorizontal } from 'lucide-react'

interface ListToolbarProps {
  children: ReactNode
  title?: string
  description?: string
  className?: string
  /**
   * Muestra la cabecera con ícono + título "Filtros" y el cromo decorativo
   * (hairline índigo + glow). Por defecto oculta, para dejar un panel de
   * formulario sobrio como el legacy (`glass-panel` simple con la fila de filtros).
   */
  showHeader?: boolean
}

export function ListToolbar({
  children,
  title = 'Filtros',
  description,
  className = '',
  showHeader = false,
}: ListToolbarProps) {
  return (
    <section
      className={[
        'relative mb-5 overflow-hidden rounded-xl border border-gray-200/80',
        'bg-white/80 p-3.5 shadow-[0_8px_30px_-20px_rgba(15,23,42,0.35)] backdrop-blur-xl',
        'dark:border-white/[0.08] dark:bg-zinc-900/65',
        'dark:shadow-[0_18px_40px_-24px_rgba(0,0,0,0.95)]',
        className,
      ].join(' ')}
    >
      {showHeader && (
        <>
          <div
            aria-hidden
            className="pointer-events-none absolute inset-x-0 top-0 h-px"
            style={{
              background:
                'linear-gradient(90deg, rgba(99,102,241,0.75), rgba(255,194,0,0.55) 42%, transparent 78%)',
            }}
          />
          <div
            aria-hidden
            className="pointer-events-none absolute -right-12 -top-16 h-32 w-32 rounded-full bg-indigo-500/[0.06] blur-3xl dark:bg-indigo-500/[0.10]"
          />
        </>
      )}

      <div className="relative flex flex-col gap-3">
        {showHeader && (
          <div className="flex items-center gap-2.5">
            <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-indigo-200/70 bg-indigo-50 text-indigo-600 dark:border-indigo-400/15 dark:bg-indigo-500/10 dark:text-indigo-300">
              <SlidersHorizontal size={14} />
            </span>
            <div>
              <h2 className="text-[0.7rem] font-bold uppercase tracking-[0.13em] text-gray-700 dark:text-zinc-200">
                {title}
              </h2>
              {description && (
                <p className="mt-0.5 text-xs text-gray-400 dark:text-zinc-500">{description}</p>
              )}
            </div>
          </div>
        )}

        {/* Acciones de filtro: "Buscar/Aplicar" debe usar variant="gold"; "Limpiar" variant="ghost". */}
        <div className="flex flex-wrap items-end gap-2.5 [&_button]:min-h-9 [&_input]:h-9 [&_select]:h-9">{children}</div>
      </div>
    </section>
  )
}
