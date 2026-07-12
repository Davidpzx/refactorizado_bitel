import type { ReactNode } from 'react'
import { GlassPanel } from './GlassPanel'
import { AddRowButton } from './AddRowButton'

/**
 * Panel de sección numerada del cuadre diario.
 * Paridad legacy (`reportes/nuevo_reporte.php`): cabecera de texto en el color de
 * la sección sobre hairline `rgba(255,255,255,.05)`, sin relleno tintado, y pie
 * "Cantidad: N | Subtotal X: S/ 0.00" en ese mismo color.
 */
export function SectionPanel({
  title, accent, count = 0, addLabel, onAdd, children, subtotal, subtotalLabel, icon, number,
}: {
  title: string; accent: string; count?: number
  addLabel?: string; onAdd?: () => void; children: ReactNode
  subtotal?: number; subtotalLabel?: string
  icon?: ReactNode; number?: number
}) {
  return (
    <GlassPanel className="relative mb-4 overflow-hidden p-0">
      <div
        aria-hidden
        className="absolute inset-x-0 top-0 z-10 h-[2px] bg-kyro-indigo shadow-[0_0_10px_1px_rgba(99,102,241,0.45)]"
        data-accent={accent}
      />

      <div
        className="flex items-center gap-2 border-b border-kyro-indigo/10 px-4 py-3 text-sm font-bold text-kyro-indigo"
        data-accent={accent}
      >
        {icon}
        {number !== undefined && <span className="tabular-nums">{number}.</span>}
        <span>{title}</span>
        {count > 0 && (
          <span
            className="ml-auto rounded-full bg-kyro-indigo/10 px-1.5 py-0.5 text-[10px] font-bold text-kyro-indigo"
            data-accent={accent}
          >
            {count}
          </span>
        )}
      </div>

      <div className="flex flex-col gap-3 p-4">
        {children}
        {onAdd && (
          <AddRowButton label={addLabel ?? 'Agregar'} onClick={onAdd} />
        )}
        {subtotal !== undefined && (
          <div className="pr-1 text-right text-xs text-kyro-muted">
            Cantidad: <b className="text-kyro-indigo tabular-nums">{count}</b>
            {' | '}
            {subtotalLabel ?? 'Subtotal'}: <b className="text-kyro-gold tabular-nums">S/ {subtotal.toFixed(2)}</b>
          </div>
        )}
      </div>
    </GlassPanel>
  )
}
