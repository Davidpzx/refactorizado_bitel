import type { ReactNode } from 'react'
import { GlassPanel } from './GlassPanel'
import { AddRowButton } from './AddRowButton'

export function SectionPanel({
  title, accent, count = 0, addLabel, onAdd, children, subtotal, icon, number,
}: {
  title: string; accent: string; count?: number
  addLabel?: string; onAdd?: () => void; children: ReactNode; subtotal?: number
  icon?: ReactNode; number?: number
}) {
  return (
    <GlassPanel className="mb-4 overflow-hidden">
      <div className="flex items-center justify-between px-3 py-2 border-b"
           style={{
             borderColor: `color-mix(in srgb, ${accent} 30%, transparent)`,
             background: `color-mix(in srgb, ${accent} 9%, transparent)`,
           }}>
        <span className="text-sm font-bold flex items-center gap-2" style={{ color: accent }}>
          {icon}
          {number !== undefined && <span className="tabular-nums">{number}.</span>}
          {title}
          {count > 0 && (
            <span className="text-[10px] font-bold rounded-full px-1.5 py-0.5"
                  style={{ background: `color-mix(in srgb, ${accent} 18%, transparent)`, color: accent }}>{count}</span>
          )}
        </span>
      </div>

      <div className="px-3 py-2">
        {children}
        {onAdd && (
          <AddRowButton label={addLabel ?? 'Agregar'} accent={accent} onClick={onAdd} className="mt-2" />
        )}
        {subtotal !== undefined && (
          <div className="mt-2 text-right text-[11px] text-kyro-muted">
            Cantidad: <b style={{ color: accent }}>{count}</b>
            {'  ·  '}
            Subtotal: <b style={{ color: accent }}>S/ {subtotal.toFixed(2)}</b>
          </div>
        )}
      </div>
    </GlassPanel>
  )
}
