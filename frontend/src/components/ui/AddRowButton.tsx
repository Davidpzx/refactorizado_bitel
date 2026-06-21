import type { ButtonHTMLAttributes, CSSProperties } from 'react'
import { Plus } from 'lucide-react'

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
  label: string
  accent?: string
  dashed?: boolean
}

export function AddRowButton({
  label,
  accent = '#6366f1',
  dashed = true,
  className = '',
  type = 'button',
  ...props
}: Props) {
  return (
    <button
      type={type}
      style={{ '--accent': accent } as CSSProperties}
      className={[
        'w-full inline-flex items-center justify-center gap-2 rounded-lg border py-2 text-sm font-bold',
        'transition-all duration-200 hover:-translate-y-px active:scale-[0.99] motion-reduce:transform-none',
        'focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-zinc-950',
        'disabled:opacity-50 disabled:pointer-events-none',
        dashed ? 'border-dashed' : 'border-solid',
        'text-[var(--accent)]',
        'border-[color-mix(in_srgb,var(--accent)_45%,transparent)] hover:border-[var(--accent)]',
        'bg-[color-mix(in_srgb,var(--accent)_8%,transparent)] hover:bg-[color-mix(in_srgb,var(--accent)_18%,transparent)]',
        'focus:ring-[color-mix(in_srgb,var(--accent)_45%,transparent)]',
        className,
      ].join(' ')}
      {...props}
    >
      <Plus size={16} strokeWidth={2.5} />
      {label}
    </button>
  )
}
