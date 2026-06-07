import { Outlet } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'

export function AppLayout() {
  const { usuario, logout, isLoggingOut } = useAuth()

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">
      <header className="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <span className="text-lg font-semibold text-gray-800">SIS-KYRO</span>
          {usuario?.tienda_id && (
            <span className="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded font-medium">
              {usuario.tienda_id}
            </span>
          )}
        </div>
        <div className="flex items-center gap-4">
          {usuario && (
            <span className="text-sm text-gray-600">
              {usuario.nombre}{' '}
              <span className="text-xs text-gray-400 uppercase">[{usuario.rol}]</span>
            </span>
          )}
          <button
            onClick={() => logout()}
            disabled={isLoggingOut}
            className="text-sm text-red-500 hover:text-red-700 disabled:opacity-50 transition-colors"
          >
            {isLoggingOut ? 'Saliendo...' : 'Cerrar sesión'}
          </button>
        </div>
      </header>

      <main className="flex-1 p-6">
        <Outlet />
      </main>
    </div>
  )
}
