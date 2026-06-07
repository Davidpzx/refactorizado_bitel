import type { ButtonHTMLAttributes } from 'react'

type Variant = 'default' | 'destructive' | 'outline' | 'ghost' | 'link'
type Size = 'default' | 'sm' | 'lg' | 'icon'

const variantClasses: Record<Variant, string> = {
  default:     'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500',
  destructive: 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
  outline:     'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-blue-500',
  ghost:       'text-gray-700 hover:bg-gray-100 focus:ring-gray-400',
  link:        'text-blue-600 underline-offset-4 hover:underline focus:ring-blue-500',
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
        'inline-flex items-center justify-center rounded-md font-medium',
        'transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2',
        'disabled:opacity-50 disabled:pointer-events-none',
        variantClasses[variant],
        sizeClasses[size],
        className,
      ].join(' ')}
      {...props}
    />
  )
}
