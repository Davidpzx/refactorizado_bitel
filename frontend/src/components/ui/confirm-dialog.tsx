import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react'
import type { Icon as LucideIcon } from '@phosphor-icons/react'
import { Warning as AlertTriangle, SealCheck as CheckCircle2, Info, Keyhole as KeyRound } from '@phosphor-icons/react'
import { Dialog } from './dialog'
import { Button } from './button'

export type ConfirmIntent = 'danger' | 'success' | 'gold' | 'indigo'

export interface ConfirmOptions {
  title: string
  description?: ReactNode
  intent?: ConfirmIntent
  icon?: LucideIcon
  confirmLabel?: string
  cancelLabel?: string
}

interface PendingConfirm extends ConfirmOptions {
  resolve: (value: boolean) => void
}

const DEFAULT_ICON: Record<ConfirmIntent, LucideIcon> = {
  danger: AlertTriangle,
  success: CheckCircle2,
  gold: KeyRound,
  indigo: Info,
}

const INTENT_BUTTON_VARIANT: Record<ConfirmIntent, 'destructive' | 'success' | 'gold' | 'default'> = {
  danger: 'destructive',
  success: 'success',
  gold: 'gold',
  indigo: 'default',
}

const INTENT_ICON_WRAP: Record<ConfirmIntent, string> = {
  danger: 'border-red-500/30 bg-red-500/10 text-red-400',
  success: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400',
  gold: 'border-[#ffc200]/30 bg-[#ffc200]/10 text-[#ffc200]',
  indigo: 'border-indigo-500/30 bg-indigo-500/10 text-indigo-400',
}

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>

const ConfirmDialogContext = createContext<ConfirmFn | null>(null)

export function ConfirmDialogProvider({ children }: { children: ReactNode }) {
  const [pending, setPending] = useState<PendingConfirm | null>(null)
  const resolveRef = useRef<((value: boolean) => void) | null>(null)

  const confirmDialog = useCallback<ConfirmFn>((options) => {
    return new Promise<boolean>((resolve) => {
      resolveRef.current = resolve
      setPending({ ...options, resolve })
    })
  }, [])

  const close = (value: boolean) => {
    resolveRef.current?.(value)
    resolveRef.current = null
    setPending(null)
  }

  const intent = pending?.intent ?? 'indigo'
  const Icon = pending?.icon ?? DEFAULT_ICON[intent]

  return (
    <ConfirmDialogContext.Provider value={confirmDialog}>
      {children}
      {pending && (
        <Dialog open onClose={() => close(false)} title={pending.title} maxWidth="sm">
          <div className="flex flex-col items-center gap-4 py-1 text-center">
            <div className={`flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full border ${INTENT_ICON_WRAP[intent]}`}>
              <Icon size={22} weight="bold" />
            </div>
            {pending.description && (
              <p className="whitespace-pre-line text-sm leading-relaxed text-gray-600 dark:text-zinc-400">
                {pending.description}
              </p>
            )}
            <div className="mt-2 flex w-full items-center justify-center gap-3">
              <button
                type="button"
                onClick={() => close(false)}
                className="inline-flex h-9 flex-1 items-center justify-center rounded-lg bg-[#3f3f46] px-4 text-sm font-medium text-zinc-100 transition-all duration-200 hover:brightness-125 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-zinc-500 focus:ring-offset-white dark:focus:ring-offset-zinc-950"
              >
                {pending.cancelLabel ?? 'Cancelar'}
              </button>
              <Button
                type="button"
                variant={INTENT_BUTTON_VARIANT[intent]}
                className="flex-1"
                onClick={() => close(true)}
                autoFocus
              >
                {pending.confirmLabel ?? 'Confirmar'}
              </Button>
            </div>
          </div>
        </Dialog>
      )}
    </ConfirmDialogContext.Provider>
  )
}

export function useConfirmDialog(): ConfirmFn {
  const ctx = useContext(ConfirmDialogContext)
  if (!ctx) throw new Error('useConfirmDialog debe usarse dentro de <ConfirmDialogProvider>')
  return ctx
}
