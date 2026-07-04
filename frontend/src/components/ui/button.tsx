import type { ButtonHTMLAttributes } from 'react'

type Variant =
  | 'default'
  | 'destructive'
  | 'outline'
  | 'ghost'
  | 'link'
  | 'gold'
  | 'glassInfo'
  | 'glassSuccess'
  | 'glassWarning'
  | 'glassDanger'
  | 'glassIndigo'
type Size = 'default' | 'sm' | 'lg' | 'icon' | 'iconSm'

const variantClasses: Record<Variant, string> = {
  default:     'text-white border border-[#4338ca] bg-[linear-gradient(180deg,#6366f1,#4f46e5)] shadow-[0_1px_2px_rgba(0,0,0,0.2)] hover:brightness-110 hover:-translate-y-px focus:ring-indigo-500',
  destructive: 'text-white border border-red-800 bg-[linear-gradient(180deg,#ef4444,#dc2626)] shadow-[0_1px_2px_rgba(0,0,0,0.2)] hover:brightness-110 hover:-translate-y-px focus:ring-red-500',
  outline:     'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-indigo-500 dark:border-white/12 dark:bg-zinc-900/50 dark:backdrop-blur-sm dark:text-zinc-200 dark:hover:bg-white/[0.06] dark:hover:border-white/25',
  ghost:       'text-gray-700 hover:bg-gray-100 focus:ring-gray-400 dark:text-zinc-300 dark:hover:bg-zinc-800',
  link:        'text-indigo-600 underline-offset-4 hover:underline focus:ring-indigo-500 dark:text-indigo-400',
  gold:        'bg-[linear-gradient(180deg,#ffd028,#ffc200)] text-[#1a1a1a] border border-[#d4a000] font-semibold shadow-[0_1px_2px_rgba(0,0,0,0.15),inset_0_1px_0_rgba(255,255,255,0.4)] hover:brightness-[1.04] hover:-translate-y-px hover:shadow-[0_6px_18px_-4px_rgba(255,194,0,0.5)] focus:ring-amber-400',
  glassInfo:   'bg-white border border-cyan-500/40 text-cyan-700 hover:bg-cyan-500/10 hover:border-cyan-500 dark:bg-cyan-500/10 dark:border-cyan-500/35 dark:text-cyan-300 dark:hover:bg-cyan-500/20 dark:hover:border-cyan-500 backdrop-blur-sm hover:-translate-y-px focus:ring-cyan-500',
  glassSuccess: 'bg-white border border-emerald-500/40 text-emerald-700 hover:bg-emerald-500/10 hover:border-emerald-500 dark:bg-emerald-500/10 dark:border-emerald-500/35 dark:text-emerald-300 dark:hover:bg-emerald-500/20 dark:hover:border-emerald-500 backdrop-blur-sm hover:-translate-y-px focus:ring-emerald-500',
  glassWarning: 'bg-white border border-amber-500/40 text-amber-700 hover:bg-amber-500/10 hover:border-amber-500 dark:bg-amber-500/10 dark:border-amber-500/35 dark:text-amber-300 dark:hover:bg-amber-500/20 dark:hover:border-amber-500 backdrop-blur-sm hover:-translate-y-px focus:ring-amber-500',
  glassDanger: 'bg-white border border-red-500/40 text-red-700 hover:bg-red-500/10 hover:border-red-500 dark:bg-red-500/10 dark:border-red-500/35 dark:text-red-300 dark:hover:bg-red-500/20 dark:hover:border-red-500 backdrop-blur-sm hover:-translate-y-px focus:ring-red-500',
  glassIndigo: 'bg-white border border-indigo-500/40 text-indigo-700 hover:bg-indigo-500/10 hover:border-indigo-500 dark:bg-indigo-500/10 dark:border-indigo-500/35 dark:text-indigo-300 dark:hover:bg-indigo-500/20 dark:hover:border-indigo-500 backdrop-blur-sm hover:-translate-y-px focus:ring-indigo-500',
}

const sizeClasses: Record<Size, string> = {
  default: 'h-9 px-4 py-2 text-sm',
  sm:      'h-8 px-3 text-xs',
  lg:      'h-10 px-6 text-base',
  icon:    'h-9 w-9',
  iconSm:  'h-8 w-8 p-0',
}

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
}

export function Button({ variant = 'default', size = 'default', className = '', ...props }: ButtonProps) {
  return (
    <button
      className={[
        'inline-flex items-center justify-center rounded-lg font-medium tracking-[0.2px]',
        'transition-all duration-200 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-offset-2',
        'focus:ring-offset-white dark:focus:ring-offset-zinc-950',
        'disabled:opacity-50 disabled:pointer-events-none disabled:hover:translate-y-0 disabled:hover:brightness-100',
        variantClasses[variant],
        sizeClasses[size],
        className,
      ].join(' ')}
      {...props}
    />
  )
}
