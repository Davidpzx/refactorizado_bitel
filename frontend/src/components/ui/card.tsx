import type { HTMLAttributes } from 'react'

export function Card({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={['rounded-lg border border-gray-200 bg-white shadow-sm', className].join(' ')}
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
