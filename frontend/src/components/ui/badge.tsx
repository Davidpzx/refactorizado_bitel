import type { HTMLAttributes } from 'react'

type Variant = 'default' | 'success' | 'warning' | 'destructive' | 'outline'

const variantClasses: Record<Variant, string> = {
  default:     'bg-blue-100 text-blue-800',
  success:     'bg-green-100 text-green-800',
  warning:     'bg-yellow-100 text-yellow-800',
  destructive: 'bg-red-100 text-red-800',
  outline:     'border border-gray-300 text-gray-700',
}

interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  variant?: Variant
}

export function Badge({ variant = 'default', className = '', ...props }: BadgeProps) {
  return (
    <span
      className={[
        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
        variantClasses[variant],
        className,
      ].join(' ')}
      {...props}
    />
  )
}
