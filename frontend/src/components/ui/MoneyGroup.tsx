import type { ReactNode } from 'react'
import { CurrencyCircleDollar as CircleDollarSign } from '@phosphor-icons/react'
import { StatCard } from './StatCard'

export interface MoneyGroupItem {
  key: string
  title: string
  value: ReactNode
  accent: string
  icon: ReactNode
  valueColorClass?: string
  formatMoney?: boolean
}

interface MoneyGroupProps {
  /** Rótulo del grupo. Por defecto el texto legacy. */
  label?: string
  items: MoneyGroupItem[]
  className?: string
}

/**
 * Grupo "DINERO DIGITAL DEL PERÍODO" del legacy: rótulo dorado + fila de
 * `StatCard` con acento izquierdo e ícono en caja redondeada por tipo de pago.
 */
export function MoneyGroup({ label = 'Dinero digital del período', items, className = '' }: MoneyGroupProps) {
  return (
    <section className={className}>
      <h3 className="mb-2 flex items-center gap-1.5 text-[0.72rem] font-bold uppercase tracking-[0.08em] text-kyro-gold">
        <CircleDollarSign size={14} aria-hidden />
        {label}
      </h3>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {items.map((item) => (
          <StatCard
            key={item.key}
            title={item.title}
            value={item.value}
            accent={item.accent}
            valueColorClass={item.valueColorClass}
            formatMoney={item.formatMoney}
            icon={
              <span
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                style={{ background: `color-mix(in srgb, ${item.accent} 18%, transparent)`, color: item.accent }}
                aria-hidden
              >
                {item.icon}
              </span>
            }
          />
        ))}
      </div>
    </section>
  )
}
