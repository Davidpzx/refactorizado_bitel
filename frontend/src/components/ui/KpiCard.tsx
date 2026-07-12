import type { ReactNode } from 'react'
import { CaretDown, CaretUp } from '@phosphor-icons/react'
import { Sparkline } from './Sparkline'

// Formateo de moneda PEN (mismo que dashboard/cuadre). Solo se usa con `monetary`.
const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

/** Color del valor protagonista. Oro reservado SOLO a dinero (usar con `monetary`). */
type KpiTone = 'neutral' | 'gold' | 'success' | 'danger' | 'info' | 'indigo'

const toneClass: Record<KpiTone, string> = {
  neutral: 'text-kyro-text',
  gold: 'text-kyro-gold',
  success: 'text-kyro-success',
  danger: 'text-kyro-danger',
  info: 'text-kyro-info',
  indigo: 'text-kyro-indigo',
}

interface KpiCardProps {
  /** Etiqueta caption uppercase (12px). */
  title: string
  /**
   * Valor protagonista. `null`/`undefined` (o `loading`) renderiza el skeleton.
   * Si `monetary` y es número, se formatea como PEN.
   */
  value: ReactNode | number | null | undefined
  /** Formatea un `value` numérico como moneda PEN y aplica tipografía tabular. */
  monetary?: boolean
  /**
   * Color del valor. Default `neutral`. `gold` es el ÚNICO acento cálido y queda
   * reservado al monto protagonista monetario — nunca a KPIs no monetarios.
   */
  tone?: KpiTone
  /**
   * Acento del sparkline / ícono / indicador izquierdo. Default indigo.
   * NUNCA oro por defecto: el oro sólo entra por `tone="gold"` en el valor.
   */
  accent?: string
  /** Ícono opcional (chip tintado con `accent`). */
  icon?: ReactNode
  /** Línea muted bajo el valor. */
  subtitle?: ReactNode
  /** Serie cronológica para el sparkline de tendencia (mínimo 2 puntos). */
  trend?: number[]
  /**
   * Variación vs periodo anterior. Número = porcentaje (`+12` → "▲ 12%", verde;
   * `-5` → "▼ 5%", rojo). String = texto libre neutro ("+3 este mes").
   */
  delta?: number | string
  /** Etiqueta muted tras el delta ("vs mes anterior"). */
  deltaLabel?: string
  /** Fuerza el estado de carga (skeleton) aunque `value` esté definido. */
  loading?: boolean
  className?: string
}

/**
 * Tarjeta KPI definitiva de la visión "Centro de Operaciones"
 * (`plan/10-vision-diseno-fintech.md`, ticket DIS-FX-03).
 *
 * Superficie glass tokenizada (dark + light) `rounded-[18px] p-4 min-h-[132px]`,
 * valor 28px tabular, delta 12px y sparkline de 48px al pie. El default es
 * indigo/neutro: el oro (`--color-kyro-gold`) sólo aparece si el valor es un
 * monto protagonista (`monetary` + `tone="gold"`).
 */
export function KpiCard({
  title,
  value,
  monetary = false,
  tone = 'neutral',
  accent = 'var(--color-kyro-indigo)',
  icon,
  subtitle,
  trend,
  delta,
  deltaLabel,
  loading = false,
  className = '',
}: KpiCardProps) {
  const isLoading = loading || value === null || value === undefined
  const display = monetary && typeof value === 'number' ? pen.format(value) : value
  const showSpark = !isLoading && trend != null && trend.length >= 2

  return (
    <div
      className={`group relative flex min-h-[132px] flex-col overflow-hidden rounded-[18px] border border-kyro-border bg-kyro-card p-4 shadow-kyro-card backdrop-blur-[18px] transition-all duration-200 hover:-translate-y-0.5 hover:border-kyro-indigo/40 ${className}`}
    >
      {/* Halo sutil de marca para que el glass tenga contra qué desenfocar. */}
      <div
        aria-hidden
        className="pointer-events-none absolute -right-10 -top-10 h-24 w-24 rounded-full blur-2xl"
        style={{ background: `color-mix(in srgb, ${accent} 12%, transparent)` }}
      />
      {/* Indicador izquierdo de acento (informativo, nunca oro por defecto). */}
      <div
        aria-hidden
        className="absolute inset-y-3 left-0 w-[3px] rounded-full"
        style={{ background: accent, opacity: 0.75 }}
      />

      <div className="relative flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="truncate text-[12px] font-semibold uppercase leading-tight tracking-[0.08em] text-kyro-muted">
            {title}
          </p>

          {isLoading ? (
            <div className="mt-2 h-7 w-24 animate-pulse rounded-md bg-kyro-border" />
          ) : (
            <p
              className={`mt-2 text-[28px] font-bold leading-none tracking-tight ${monetary ? 'kyro-money' : 'tabular-nums'} ${toneClass[tone]}`}
            >
              {display}
            </p>
          )}

          {delta !== undefined && !isLoading && (
            <p className="mt-2 flex items-center gap-1 text-[12px] leading-none">
              {typeof delta === 'number' ? (
                <>
                  <span
                    className={`inline-flex items-center gap-0.5 font-semibold ${
                      delta >= 0 ? 'text-kyro-success' : 'text-kyro-danger'
                    }`}
                  >
                    {delta >= 0 ? <CaretUp size={11} weight="bold" /> : <CaretDown size={11} weight="bold" />}
                    {Math.abs(delta).toLocaleString('es-PE', { maximumFractionDigits: 1 })}%
                  </span>
                  {deltaLabel && <span className="text-kyro-subtle">{deltaLabel}</span>}
                </>
              ) : (
                <span className="text-kyro-subtle">
                  {delta}
                  {deltaLabel ? ` ${deltaLabel}` : ''}
                </span>
              )}
            </p>
          )}
        </div>

        {icon && (
          <span
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
            style={{ background: `color-mix(in srgb, ${accent} 14%, transparent)`, color: accent }}
            aria-hidden
          >
            {icon}
          </span>
        )}
      </div>

      {/* Contexto de soporte + sparkline anclados al pie de la card. */}
      <div className="relative mt-auto pt-3">
        {subtitle && !isLoading && (
          <p className="mb-1 truncate text-[12px] leading-tight text-kyro-muted">{subtitle}</p>
        )}
        {isLoading ? (
          <div className="h-12 w-full animate-pulse rounded-md bg-kyro-border/60" />
        ) : showSpark ? (
          <div className="pointer-events-none -mb-1" style={{ color: accent }}>
            <Sparkline data={trend!} height={48} width={220} className="w-full" fill />
          </div>
        ) : null}
      </div>
    </div>
  )
}
