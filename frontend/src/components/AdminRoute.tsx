import { Navigate, Outlet } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'

export function AdminRoute() {
  const usuario = useAuthStore((s) => s.usuario)
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)

  // Token existe pero usuario aún no cargado → esperar (useAuth lo hidrata)
  if (isAuthenticated && !usuario) return null

  if (usuario?.rol !== 'admin') {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
