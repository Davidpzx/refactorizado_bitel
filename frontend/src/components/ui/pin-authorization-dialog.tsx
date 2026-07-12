import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import axios from 'axios'
import { LockKey, ShieldCheck } from '@phosphor-icons/react'
import { api } from '../../services/api'
import { Dialog } from './dialog'
import { Button } from './button'

export interface PinAuthorizationResult {
  rol: string
  agenteId: number | null
  nombre: string
}

export interface PinAuthorizationOptions {
  /** Acción puntual que exige autorización, ej. "Anular comprobante #00234". */
  action?: string
  /** Texto de jerarquía bajo el título. Por defecto cubre admin > gerente > agente con turno abierto. */
  hierarchyHint?: string
}

interface PendingAuth extends PinAuthorizationOptions {
  resolve: (value: PinAuthorizationResult | null) => void
}

type RequestPinAuthFn = (options?: PinAuthorizationOptions) => Promise<PinAuthorizationResult | null>

const PinAuthorizationContext = createContext<RequestPinAuthFn | null>(null)

const DEFAULT_HIERARCHY_HINT = 'Autoriza un Administrador, Gerente o Agente con turno abierto.'

export function PinAuthorizationProvider({ children }: { children: ReactNode }) {
  const [pending, setPending] = useState<PendingAuth | null>(null)
  const resolveRef = useRef<((value: PinAuthorizationResult | null) => void) | null>(null)

  const requestPinAuthorization = useCallback<RequestPinAuthFn>((options) => {
    return new Promise<PinAuthorizationResult | null>((resolve) => {
      resolveRef.current = resolve
      setPending({ ...options, resolve })
    })
  }, [])

  const close = (value: PinAuthorizationResult | null) => {
    resolveRef.current?.(value)
    resolveRef.current = null
    setPending(null)
  }

  return (
    <PinAuthorizationContext.Provider value={requestPinAuthorization}>
      {children}
      {pending && (
        <PinAuthorizationDialog
          action={pending.action}
          hierarchyHint={pending.hierarchyHint}
          onAuthorized={(result) => close(result)}
          onCancel={() => close(null)}
        />
      )}
    </PinAuthorizationContext.Provider>
  )
}

export function usePinAuthorization(): RequestPinAuthFn {
  const ctx = useContext(PinAuthorizationContext)
  if (!ctx) throw new Error('usePinAuthorization debe usarse dentro de <PinAuthorizationProvider>')
  return ctx
}

interface PinAuthorizationDialogProps extends PinAuthorizationOptions {
  onAuthorized: (result: PinAuthorizationResult) => void
  onCancel: () => void
}

const fieldClasses =
  'flex h-10 w-full rounded-[10px] border border-gray-300/90 bg-white/90 px-3 py-1 text-sm text-gray-800 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all duration-200 placeholder:text-gray-400 hover:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-black/20 dark:text-zinc-100 dark:shadow-inner dark:placeholder:text-zinc-600 dark:hover:border-white/20 dark:focus:border-indigo-400 disabled:cursor-not-allowed disabled:opacity-50'

/**
 * Modal de autorización PIN — paridad visual con el SweetAlert2 legacy
 * (`solicitarAutorizacion` en includes/footer.php): candado índigo, DNI + PIN
 * con letter-spacing, auto-focus/auto-advance y shake en error.
 */
export function PinAuthorizationDialog({
  action,
  hierarchyHint,
  onAuthorized,
  onCancel,
}: PinAuthorizationDialogProps) {
  const [dni, setDni] = useState('')
  const [pin, setPin] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [shake, setShake] = useState(false)
  const [loading, setLoading] = useState(false)
  const dniRef = useRef<HTMLInputElement>(null)
  const pinRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    dniRef.current?.focus()
  }, [])

  function triggerShake(message: string) {
    setError(message)
    setPin('')
    setShake(true)
    window.setTimeout(() => setShake(false), 420)
    pinRef.current?.focus()
  }

  function handleDniChange(value: string) {
    const digits = value.replace(/\D/g, '').slice(0, 8)
    setDni(digits)
    setError(null)
    if (digits.length === 8) pinRef.current?.focus()
  }

  function handlePinChange(value: string) {
    setPin(value.replace(/\D/g, '').slice(0, 4))
    setError(null)
  }

  async function handleSubmit() {
    if (loading) return

    if (!/^\d{8}$/.test(dni)) {
      triggerShake('El DNI debe tener exactamente 8 dígitos.')
      dniRef.current?.focus()
      return
    }
    if (!/^\d{4}$/.test(pin)) {
      triggerShake('El PIN debe tener exactamente 4 dígitos.')
      return
    }

    setLoading(true)
    setError(null)
    try {
      const { data } = await api.post('/v1/auth/verify-pin', { dni, pin })
      onAuthorized({
        rol: data.rol,
        agenteId: data.agente_id ?? null,
        nombre: data.nombre,
      })
    } catch (err) {
      const message = axios.isAxiosError(err)
        ? ((err.response?.data as { error?: string } | undefined)?.error ?? 'DNI o PIN incorrecto')
        : 'Sin conexión con el servidor. Intenta de nuevo.'
      triggerShake(message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <Dialog open onClose={onCancel} title="Autorización Requerida" maxWidth="sm">
      <div
        className={`flex flex-col items-center gap-4 py-1 text-center ${shake ? 'animate-kyro-shake' : ''}`}
      >
        <div className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-full border border-indigo-500/30 bg-indigo-500/10 text-indigo-500 dark:text-indigo-400">
          <LockKey size={28} weight="fill" />
        </div>

        <div>
          {action && (
            <p className="text-sm font-semibold text-gray-800 dark:text-zinc-100">{action}</p>
          )}
          <p className="mt-0.5 text-xs text-gray-500 dark:text-zinc-500">
            {hierarchyHint ?? DEFAULT_HIERARCHY_HINT}
          </p>
        </div>

        <form
          className="w-full space-y-3 text-left"
          onSubmit={(e) => {
            e.preventDefault()
            void handleSubmit()
          }}
        >
          <div>
            <label
              htmlFor="pin-auth-dni"
              className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-zinc-400"
            >
              DNI del Agente
            </label>
            <input
              id="pin-auth-dni"
              ref={dniRef}
              value={dni}
              onChange={(e) => handleDniChange(e.target.value)}
              inputMode="numeric"
              maxLength={8}
              placeholder="Ej: 12345678"
              autoComplete="off"
              disabled={loading}
              className={`${fieldClasses} text-center tracking-widest`}
            />
          </div>
          <div>
            <label
              htmlFor="pin-auth-pin"
              className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-zinc-400"
            >
              PIN de Seguridad
            </label>
            <input
              id="pin-auth-pin"
              ref={pinRef}
              type="password"
              value={pin}
              onChange={(e) => handlePinChange(e.target.value)}
              inputMode="numeric"
              maxLength={4}
              placeholder="••••"
              autoComplete="off"
              disabled={loading}
              className={`${fieldClasses} text-center text-lg text-indigo-600 dark:text-indigo-400`}
              style={{ letterSpacing: '8px' }}
            />
          </div>

          {error && (
            <p role="alert" className="text-center text-xs font-medium text-red-500 dark:text-red-400">
              {error}
            </p>
          )}

          <div className="mt-2 flex w-full items-center justify-center gap-3">
            <button
              type="button"
              onClick={onCancel}
              disabled={loading}
              className="inline-flex h-9 flex-1 items-center justify-center rounded-[10px] bg-[#3f3f46] px-4 text-sm font-medium text-zinc-100 transition-all duration-200 hover:brightness-125 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-zinc-500 focus:ring-offset-white disabled:opacity-50 dark:focus:ring-offset-zinc-950"
            >
              Cancelar
            </button>
            <Button type="submit" className="flex-1 gap-1.5" disabled={loading}>
              <ShieldCheck size={16} weight="bold" />
              {loading ? 'Verificando…' : 'Autorizar'}
            </Button>
          </div>
        </form>
      </div>
    </Dialog>
  )
}
