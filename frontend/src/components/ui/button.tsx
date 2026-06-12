import type { ButtonHTMLAttributes } from 'react'

type Variant = 'default' | 'destructive' | 'outline' | 'ghost' | 'link'
type Size = 'default' | 'sm' | 'lg' | 'icon'

const variantClasses: Record<Variant, string> = {
  default:     'text-white border border-[#4338ca] bg-[linear-gradient(180deg,#6366f1,#4f46e5)] shadow-[0_1px_2px_rgba(0,0,0,0.2)] hover:brightness-110 hover:-translate-y-px focus:ring-indigo-500',
  destructive: 'text-white border border-red-800 bg-[linear-gradient(180deg,#ef4444,#dc2626)] shadow-[0_1px_2px_rgba(0,0,0,0.2)] hover:brightness-110 hover:-translate-y-px focus:ring-red-500',
  outline:     'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-indigo-500 dark:border-white/12 dark:bg-zinc-900/50 dark:backdrop-blur-sm dark:text-zinc-200 dark:hover:bg-white/[0.06] dark:hover:border-white/25',
  ghost:       'text-gray-700 hover:bg-gray-100 focus:ring-gray-400 dark:text-zinc-300 dark:hover:bg-zinc-800',
  link:        'text-indigo-600 underline-offset-4 hover:underline focus:ring-indigo-500 dark:text-indigo-400',
}

const sizeClasses: Record<Size, string> = {
  default: 'h-9 px-4 py-2 text-sm',
  sm:      'h-8 px-3 text-xs',
  lg:      'h-10 px-6 text-base',
  icon:    'h-9 w-9',
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
