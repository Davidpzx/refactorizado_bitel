import type { ReactNode } from 'react'

interface DialogProps {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
  maxWidth?: 'sm' | 'md' | 'lg' | 'xl'
}

const maxWidthClasses = {
  sm: 'max-w-sm',
  md: 'max-w-md',
  lg: 'max-w-lg',
  xl: 'max-w-xl',
}

export function Dialog({ open, onClose, title, children, maxWidth = 'md' }: DialogProps) {
  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div
        className="absolute inset-0 bg-slate-950/65 backdrop-blur-sm"
        onClick={onClose}
      />
      <div
        className={[
          'relative z-10 flex max-h-[90vh] w-full flex-col overflow-hidden rounded-2xl border border-white/60 bg-white/95 shadow-2xl backdrop-blur-xl',
          'dark:border-white/10 dark:bg-zinc-900/95 dark:shadow-[0_28px_80px_-24px_rgba(0,0,0,0.95)]',
          maxWidthClasses[maxWidth],
        ].join(' ')}
      >
        <div
          aria-hidden
          className="absolute inset-x-0 top-0 h-px"
          style={{ background: 'linear-gradient(90deg, #6366f1, #ffc200 45%, transparent 85%)' }}
        />
        <div className="flex flex-shrink-0 items-center justify-between border-b border-gray-200/80 px-5 py-4 dark:border-white/[0.07]">
          <h2 className="text-base font-bold tracking-tight text-gray-900 dark:text-zinc-50">{title}</h2>
          <button
            onClick={onClose}
            className="flex h-8 w-8 items-center justify-center rounded-lg text-xl leading-none text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/[0.06] dark:hover:text-zinc-100"
            aria-label="Cerrar"
          >
            ×
          </button>
        </div>
        <div className="flex-1 overflow-y-auto p-5">{children}</div>
      </div>
    </div>
  )
}
