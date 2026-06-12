import type { HTMLAttributes } from 'react'

export function Card({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={['rounded-xl border border-gray-200 bg-white shadow-sm dark:shadow-[0_4px_20px_-2px_rgba(0,0,0,0.5)]', className].join(' ')}
      {...props}
    />
  )
}

export function CardHeader({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={['flex flex-col space-y-1.5 p-4 border-b border-gray-200', className].join(' ')} {...props} />
}

export function CardContent({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={['p-4', className].join(' ')} {...props} />
}

export function CardTitle({ className = '', ...props }: HTMLAttributes<HTMLHeadingElement>) {
  return <h3 className={['text-base font-semibold text-gray-900', className].join(' ')} {...props} />
}
