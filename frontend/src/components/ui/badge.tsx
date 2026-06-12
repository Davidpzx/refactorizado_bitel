import type { HTMLAttributes } from 'react'

type Variant = 'default' | 'success' | 'warning' | 'destructive' | 'outline'

const variantClasses: Record<Variant, string> = {
  default:     'border-blue-200/70 bg-blue-50 text-blue-700 dark:border-blue-400/15 dark:bg-blue-500/10 dark:text-blue-300',
  success:     'border-emerald-200/70 bg-emerald-50 text-emerald-700 dark:border-emerald-400/15 dark:bg-emerald-500/10 dark:text-emerald-300',
  warning:     'border-amber-200/70 bg-amber-50 text-amber-700 dark:border-amber-400/15 dark:bg-amber-500/10 dark:text-amber-300',
  destructive: 'border-red-200/70 bg-red-50 text-red-700 dark:border-red-400/15 dark:bg-red-500/10 dark:text-red-300',
  outline:     'border-gray-300 bg-white/60 text-gray-600 dark:border-white/10 dark:bg-white/[0.03] dark:text-zinc-300',
}

interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  variant?: Variant
}

export function Badge({ variant = 'default', className = '', ...props }: BadgeProps) {
  return (
    <span
      className={[
        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-[0.68rem] font-semibold tracking-wide',
        variantClasses[variant],
        className,
      ].join(' ')}
      {...props}
    />
  )
}
