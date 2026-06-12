import type { HTMLAttributes } from 'react'

interface GlassPanelProps extends HTMLAttributes<HTMLDivElement> {
  accentTop?: string
}

export function GlassPanel({ accentTop, className = '', style, ...props }: GlassPanelProps) {
  return (
    <div
      className={['rounded-xl', className].join(' ')}
      style={{
        background: '#18181b',
        border: '1px solid rgba(255,255,255,0.08)',
        boxShadow: '0 4px 20px -2px rgba(0,0,0,0.5)',
        ...(accentTop ? { borderTop: `3px solid ${accentTop}` } : {}),
        ...style,
      }}
      {...props}
    />
  )
}
