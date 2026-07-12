import type { HTMLAttributes } from 'react'

interface GlassPanelProps extends HTMLAttributes<HTMLDivElement> {
  accentTop?: string
}

export function GlassPanel({ accentTop, className = '', style, ...props }: GlassPanelProps) {
  const hasExplicitPadding = /(?:^|\s)!?p[trblxy]?-[^\s]+/.test(className)
  return (
    <div
      className={['premium-surface rounded-[18px] [&::before]:!bg-[linear-gradient(90deg,rgba(99,102,241,0.7),transparent_78%)]', hasExplicitPadding ? '' : 'p-5', className].join(' ')}
      style={{
        ...(accentTop ? { borderTop: `3px solid ${accentTop}` } : {}),
        ...style,
      }}
      {...props}
    />
  )
}
