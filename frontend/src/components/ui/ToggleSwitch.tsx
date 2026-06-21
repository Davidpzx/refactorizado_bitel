import type { InputHTMLAttributes, CSSProperties } from 'react'

interface Props extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  accent?: string
}

export function ToggleSwitch({ label, accent = '#6366f1', className = '', ...props }: Props) {
  return (
    <label
      style={{ '--accent': accent } as CSSProperties}
      className={['inline-flex items-center gap-1.5 cursor-pointer select-none text-[10px] font-medium', className].join(' ')}
    >
      <input type="checkbox" className="peer sr-only" {...props} />
      <span
        className="relative h-4 w-7 shrink-0 rounded-full bg-zinc-400/50 transition-colors duration-200 dark:bg-zinc-600/60
          after:absolute after:left-0.5 after:top-0.5 after:h-3 after:w-3 after:rounded-full after:bg-white after:shadow-sm after:transition-transform after:duration-200 after:content-['']
          peer-checked:bg-[var(--accent)] peer-checked:after:translate-x-3
          peer-focus-visible:ring-2 peer-focus-visible:ring-[color-mix(in_srgb,var(--accent)_45%,transparent)]"
      />
      <span className="text-kyro-muted transition-colors peer-checked:font-semibold peer-checked:text-[var(--accent)]">
        {label}
      </span>
    </label>
  )
}
