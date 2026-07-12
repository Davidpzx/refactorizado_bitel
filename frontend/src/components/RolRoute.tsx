import { Navigate, Outlet } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'
import { esAgente, tieneAlgunRol } from '../utils/roles'

/**
 * Guard de ruta por rol (evolución de `AdminRoute`, que solo protegía "admin").
 * `roles` acepta los 4 roles canónicos (o alias legacy 'admin'/'tienda') —
 * `tieneAlgunRol` normaliza antes de comparar.
 *
 * Si no se pasa `redirectTo`, el destino se calcula por rol: el agente (cuyo
 * mundo son 3 rutas: /reportes/nuevo, /mi-historial, /reportes/:id) siempre
 * cae a `/mi-historial` al pisar una ruta ajena; el resto cae a `/`.
 */
export function RolRoute({ roles, redirectTo }: { roles: string[]; redirectTo?: string }) {
  const usuario = useAuthStore((s) => s.usuario)
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)

  // Token existe pero usuario aún no cargado → esperar (useAuth lo hidrata)
  if (isAuthenticated && !usuario) return null

  if (!tieneAlgunRol(usuario, roles)) {
    const destino = redirectTo ?? (esAgente(usuario) ? '/mi-historial' : '/')
    return <Navigate to={destino} replace />
  }

  return <Outlet />
}
