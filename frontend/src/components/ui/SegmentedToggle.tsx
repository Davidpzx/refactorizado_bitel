import type { KeyboardEvent, ReactNode } from 'react'

type Tone = 'indigo' | 'gold' | 'success' | 'warning' | 'danger' | 'info'

interface Option<T> {
  value: T
  label: string
  icon?: ReactNode
  tone?: Tone
}

interface Props<T> {
  options: Option<T>[]
  value: T
  onChange: (v: T) => void
  size?: 'sm' | 'md'
  ariaLabel?: string
}

const toneStyles: Record<Tone, { hex: string; ink: string }> = {
  indigo: { hex: '#6366f1', ink: '#ffffff' },
  gold: { hex: '#6366f1', ink: '#ffffff' },
  success: { hex: '#22c55e', ink: '#052e16' },
  warning: { hex: '#fbbf24', ink: '#451a03' },
  danger: { hex: '#ef4444', ink: '#ffffff' },
  info: { hex: '#06b6d4', ink: '#022c35' },
}

const sizeClasses: Record<NonNullable<Props<unknown>['size']>, string> = {
  sm: 'text-xs h-8 px-3',
  md: 'text-sm h-9 px-4',
}

function hexToRgba(hex: string, alpha: number) {
  const normalized = hex.replace('#', '')
  const value = Number.parseInt(normalized, 16)
  const r = (value >> 16) & 255
  const g = (value >> 8) & 255
  const b = value & 255
  return `rgba(${r},${g},${b},${alpha})`
}

export function SegmentedToggle<T>({
  options,
  value,
  onChange,
  size = 'md',
  ariaLabel,
}: Props<T>) {
  const selectedIndex = options.findIndex((option) => option.value === value)

  function move(delta: number) {
    if (options.length === 0) return
    const current = selectedIndex >= 0 ? selectedIndex : 0
    const next = (current + delta + options.length) % options.length
    onChange(options[next].value)
  }

  function handleKeyDown(event: KeyboardEvent<HTMLButtonElement>, optionValue: T) {
    if (event.key === 'ArrowLeft') {
      event.preventDefault()
      move(-1)
      return
    }
    if (event.key === 'ArrowRight') {
      event.preventDefault()
      move(1)
      return
    }
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      onChange(optionValue)
    }
  }

  return (
    <div
      role="tablist"
      aria-label={ariaLabel}
      className="inline-flex gap-1 rounded-[10px] border border-gray-200 bg-gray-100/70 p-1 dark:border-white/8 dark:bg-zinc-900/60"
    >
      {options.map((option) => {
        const selected = option.value === value
        const tone = toneStyles[option.tone ?? 'indigo']
        return (
          <button
            key={String(option.value)}
            type="button"
            role="tab"
            aria-selected={selected}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(option.value)}
            onKeyDown={(event) => handleKeyDown(event, option.value)}
            className={[
              'inline-flex items-center justify-center gap-1.5 rounded-[10px] font-medium transition-all duration-[250ms]',
              'focus:outline-none focus:ring-2 focus:ring-indigo-500/35 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-zinc-950',
              'motion-reduce:scale-100 motion-reduce:transition-none',
              sizeClasses[size],
              selected
                ? 'font-semibold scale-[1.02]'
                : 'bg-transparent text-gray-500 opacity-70 hover:opacity-100 hover:text-gray-700 dark:text-zinc-400 dark:hover:text-zinc-200',
            ].join(' ')}
            style={selected
              ? {
                  backgroundColor: tone.hex,
                  color: tone.ink,
                  boxShadow: `0 0 18px ${hexToRgba(tone.hex, 0.45)}`,
                }
              : undefined}
          >
            {option.icon}
            {option.label}
          </button>
        )
      })}
    </div>
  )
}
