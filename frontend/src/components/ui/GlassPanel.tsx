import type { HTMLAttributes } from 'react'

interface GlassPanelProps extends HTMLAttributes<HTMLDivElement> {
  accentTop?: string
}

export function GlassPanel({ accentTop, className = '', style, ...props }: GlassPanelProps) {
  return (
    <div
      className={['premium-surface', className].join(' ')}
      style={{
        ...(accentTop ? { borderTop: `3px solid ${accentTop}` } : {}),
        ...style,
      }}
      {...props}
    />
  )
}
