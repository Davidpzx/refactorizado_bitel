import type { ReactNode } from 'react'
import { GlassPanel } from './GlassPanel'

export function SectionPanel({
  title, accent, count = 0, addLabel, onAdd, children, subtotal,
}: {
  title: string; accent: string; count?: number
  addLabel?: string; onAdd?: () => void; children: ReactNode; subtotal?: number
}) {
  return (
    <GlassPanel className="mb-4 overflow-hidden">
      <div className="flex items-center justify-between px-3 py-2 border-b"
           style={{ borderColor: 'rgba(255,255,255,0.06)' }}>
        <span className="text-sm font-bold flex items-center gap-2" style={{ color: accent }}>
          {title}
          {count > 0 && (
            <span className="text-[10px] font-bold rounded-full px-1.5 py-0.5"
                  style={{ background: `${accent}22`, color: accent }}>{count}</span>
          )}
        </span>
        {onAdd && (
          <button type="button" onClick={onAdd}
            className="text-xs font-semibold flex items-center gap-1 px-2 py-1 rounded-md transition-colors"
            style={{ color: accent, background: `${accent}14` }}>
            <span className="text-base leading-none">+</span> {addLabel}
          </button>
        )}
      </div>
      <div className="px-3 py-2">{children}</div>
      {subtotal !== undefined && count > 0 && (
        <div className="text-right text-xs font-semibold px-3 pb-2" style={{ color: accent }}>
          Subtotal: S/ {subtotal.toFixed(2)}
        </div>
      )}
    </GlassPanel>
  )
}
