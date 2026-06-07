import { Outlet, NavLink } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'

const navItems = [
  { to: '/',         label: 'Dashboard' },
  { to: '/agentes',  label: 'Agentes' },
  { to: '/clientes', label: 'Clientes' },
]

export function AppLayout() {
  const { usuario, logout, isLoggingOut } = useAuth()

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">
      <header className="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between">
        <div className="flex items-center gap-6">
          <div className="flex items-center gap-3">
            <span className="text-lg font-semibold text-gray-800">SIS-KYRO</span>
            {usuario?.tienda_id && (
              <span className="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded font-medium">
                {usuario.tienda_id}
              </span>
            )}
          </div>

          <nav className="flex items-center gap-1">
            {navItems.map(({ to, label }) => (
              <NavLink
                key={to}
                to={to}
                end={to === '/'}
                className={({ isActive }) =>
                  [
                    'px-3 py-1.5 rounded text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-blue-50 text-blue-700'
                      : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100',
                  ].join(' ')
                }
              >
                {label}
              </NavLink>
            ))}
          </nav>
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
