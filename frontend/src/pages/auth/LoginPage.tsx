import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../../hooks/useAuth'
import { useAuthStore } from '../../store/authStore'
import { apiErrorData } from '../../lib/httpError'

const schema = z.object({
  email:    z.string().email('Correo inválido'),
  password: z.string().min(1, 'La contraseña es requerida'),
})

type FormData = z.infer<typeof schema>

export function LoginPage() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const { login, isLoggingIn, loginError } = useAuth()

  const { register, handleSubmit, formState: { errors } } = useForm<FormData>({
    resolver: zodResolver(schema),
  })

  if (isAuthenticated) {
    return <Navigate to="/" replace />
  }

  const errorMessage = (() => {
    if (!loginError) return null
    const data = apiErrorData(loginError)
    return data.errors?.email?.[0]
      ?? data.message
      ?? 'Error al iniciar sesión. Verifica tus credenciales.'
  })()

  return (
    <div className="public-premium-shell flex min-h-screen items-center justify-center p-4 sm:p-6">
      <div className="public-premium-card w-full max-w-sm rounded-3xl p-7 sm:p-8">
        {/* Logo / título */}
        <div className="mb-8 text-center">
          <div className="relative mb-5 inline-flex h-14 w-14 items-center justify-center rounded-2xl border border-indigo-300/40 bg-gradient-to-br from-indigo-500 to-indigo-700 shadow-[0_16px_35px_-16px_rgba(79,70,229,0.9)]">
            <span className="absolute inset-0 rounded-2xl bg-white/10" />
            <svg className="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
              />
            </svg>
          </div>
          <h1
            className="text-2xl font-bold tracking-[0.14em] text-gray-900 dark:text-zinc-50"
            style={{ fontFamily: "'Orbitron', sans-serif" }}
          >
            SIS-KYRO
          </h1>
          <p className="mt-2 text-sm text-gray-500 dark:text-zinc-400">Acceso seguro al sistema ERP Bitel</p>
        </div>

        <form onSubmit={handleSubmit((d) => login(d))} className="space-y-5">
          {/* Email */}
          <div>
            <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-zinc-300">
              Correo electrónico
            </label>
            <input
              id="email"
              type="email"
              autoComplete="email"
              placeholder="usuario@ejemplo.com"
              className="h-11 w-full rounded-xl border border-gray-300/90 bg-white/75 px-3.5 text-sm text-gray-800 shadow-sm transition-all placeholder:text-gray-400 hover:border-indigo-300 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 disabled:opacity-60 dark:border-white/10 dark:bg-black/20 dark:text-zinc-100 dark:placeholder:text-zinc-600"
              disabled={isLoggingIn}
              {...register('email')}
            />
            {errors.email && (
              <p className="mt-1 text-xs text-red-500">{errors.email.message}</p>
            )}
          </div>

          {/* Password */}
          <div>
            <label htmlFor="password" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-zinc-300">
              Contraseña
            </label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              placeholder="••••••••"
              className="h-11 w-full rounded-xl border border-gray-300/90 bg-white/75 px-3.5 text-sm text-gray-800 shadow-sm transition-all placeholder:text-gray-400 hover:border-indigo-300 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 disabled:opacity-60 dark:border-white/10 dark:bg-black/20 dark:text-zinc-100 dark:placeholder:text-zinc-600"
              disabled={isLoggingIn}
              {...register('password')}
            />
            {errors.password && (
              <p className="mt-1 text-xs text-red-500">{errors.password.message}</p>
            )}
          </div>

          {/* Error API */}
          {errorMessage && (
            <div className="rounded-xl border border-red-200/80 bg-red-50/80 px-3.5 py-2.5 dark:border-red-400/20 dark:bg-red-500/10">
              <p className="text-sm text-red-600 dark:text-red-400">{errorMessage}</p>
            </div>
          )}

          {/* Submit */}
          <button
            type="submit"
            disabled={isLoggingIn}
            className="h-11 w-full rounded-xl border border-indigo-700 bg-gradient-to-b from-indigo-500 to-indigo-700 px-4 text-sm font-semibold text-white shadow-[0_12px_28px_-14px_rgba(79,70,229,0.9)] transition-all hover:-translate-y-px hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:translate-y-0 disabled:opacity-50"
          >
            {isLoggingIn ? (
              <span className="flex items-center justify-center gap-2">
                <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                </svg>
                Ingresando...
              </span>
            ) : (
              'Ingresar'
            )}
          </button>
        </form>
      </div>
    </div>
  )
}
