import type { LabelHTMLAttributes } from 'react'

type LabelProps = LabelHTMLAttributes<HTMLLabelElement>

export function Label({ className = '', ...props }: LabelProps) {
  return (
    <label
      className={[
        'text-xs font-medium text-gray-700 leading-none dark:text-zinc-300',
        'peer-disabled:cursor-not-allowed peer-disabled:opacity-70',
        className,
      ].join(' ')}
      {...props}
    />
  )
}
