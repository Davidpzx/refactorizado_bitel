import type { InputHTMLAttributes } from 'react'

type InputProps = InputHTMLAttributes<HTMLInputElement>

export function Input({ className = '', ...props }: InputProps) {
  return (
    <input
      className={[
        'flex h-10 w-full rounded-[10px] border border-gray-300/90 bg-white/90 px-3 py-1 text-sm text-gray-800',
        'shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all duration-200',
        'placeholder:text-gray-400 hover:border-gray-400',
        'focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20',
        'dark:border-white/10 dark:bg-black/20 dark:text-zinc-100 dark:shadow-inner',
        'dark:placeholder:text-zinc-600 dark:hover:border-white/20 dark:focus:border-indigo-400',
        'disabled:cursor-not-allowed disabled:opacity-50',
        className,
      ].join(' ')}
      {...props}
    />
  )
}
