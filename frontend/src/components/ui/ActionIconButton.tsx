import type { ButtonHTMLAttributes, HTMLAttributes, ReactNode } from 'react'

type ActionTone = 'view' | 'edit' | 'delete' | 'excel'

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
  tone: ActionTone
  label: string
  icon: ReactNode
}

const toneClasses: Record<ActionTone, string> = {
  view: 'hover:border-cyan-500 hover:bg-cyan-500/10 hover:text-cyan-600 dark:hover:border-cyan-500 dark:hover:bg-cyan-500/10 dark:hover:text-cyan-300 focus:ring-cyan-500',
  edit: 'hover:border-indigo-500 hover:bg-indigo-500/10 hover:text-indigo-600 dark:hover:border-indigo-500 dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300 focus:ring-indigo-500',
  delete: 'hover:border-red-500 hover:bg-red-500/10 hover:text-red-600 dark:hover:border-red-500 dark:hover:bg-red-500/10 dark:hover:text-red-300 focus:ring-red-500',
  excel: 'hover:border-emerald-500 hover:bg-emerald-500/10 hover:text-emerald-600 dark:hover:border-emerald-500 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-300 focus:ring-emerald-500',
}

export function ActionIconButton({
  tone,
  label,
  icon,
  className = '',
  type = 'button',
  ...props
}: Props) {
  return (
    <button
      type={type}
      aria-label={label}
      title={label}
      className={[
        'h-9 w-9 inline-flex items-center justify-center rounded-[10px] border transition-all duration-200 active:scale-95',
        'border-gray-300 bg-white text-gray-500',
        'focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-white',
        'dark:border-white/10 dark:bg-zinc-900/50 dark:text-zinc-400 dark:focus:ring-offset-zinc-950',
        'disabled:opacity-40 disabled:pointer-events-none',
        toneClasses[tone],
        className,
      ].join(' ')}
      {...props}
    >
      {icon}
    </button>
  )
}

export function TableActions({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={['flex items-center gap-1.5', className].join(' ')}
      {...props}
    />
  )
}
