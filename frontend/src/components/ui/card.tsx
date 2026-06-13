import type { HTMLAttributes } from 'react'

export function Card({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={[
        'relative overflow-hidden rounded-xl border border-gray-200/80 bg-white/80 shadow-[0_14px_35px_-26px_rgba(15,23,42,0.45)] backdrop-blur-xl',
        'dark:border-white/[0.08] dark:bg-zinc-900/65 dark:shadow-[0_20px_45px_-28px_rgba(0,0,0,0.95)]',
        className,
      ].join(' ')}
      {...props}
    />
  )
}

export function CardHeader({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={['flex flex-col space-y-1.5 border-b border-gray-200/80 p-4 dark:border-white/[0.07]', className].join(' ')} {...props} />
}

export function CardContent({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={['p-4', className].join(' ')} {...props} />
}

export function CardTitle({ className = '', ...props }: HTMLAttributes<HTMLHeadingElement>) {
  return <h3 className={['text-base font-semibold text-gray-900', className].join(' ')} {...props} />
}
