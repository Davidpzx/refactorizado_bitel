import { useState } from 'react'
import { Outlet, NavLink, Link } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import { useQuery } from '@tanstack/react-query'
import { dashboardApi } from '../services/dashboard.api'
import {
  LayoutDashboard, History, BarChart2, CreditCard, FileText,
  Users, Clock, DollarSign, Package, BookOpen, Settings,
  UserCog, Store, LogOut, Bell, ChevronLeft, ChevronRight,
  ClipboardList, TrendingUp, Menu, Receipt,
} from 'lucide-react'

interface NavItem {
  to: string
  label: string
  Icon: React.ComponentType<{ size?: number; className?: string }>
  roles: string[]
  soon?: boolean
}

const NAV_ITEMS: NavItem[] = [
  // ── Sección operativa (todos) ──
  { to: '/',              label: 'Dashboard',        Icon: LayoutDashboard, roles: ['admin', 'tienda'] },
  { to: '/historial',     label: 'Historial',        Icon: History,         roles: ['admin'] },
  { to: '/reportes/nuevo',label: 'Nuevo Cuadre',     Icon: ClipboardList,   roles: ['tienda'] },
  { to: '/mi-historial',  label: 'Mi Historial',     Icon: History,         roles: ['tienda'] },
  { to: '/estadisticas',  label: 'Estadísticas',     Icon: BarChart2,       roles: ['admin'] },
  { to: '/panel-bipay',   label: 'Panel Bipay',      Icon: CreditCard,      roles: ['admin'] },
  { to: '/reporte-bcp',   label: 'Reporte BCP',      Icon: FileText,        roles: ['admin', 'tienda'] },
  // ── Agentes y personal ──
  { to: '/agentes',       label: 'Agentes',          Icon: Users,           roles: ['admin'] },
  { to: '/asistencias',   label: 'Asistencias',      Icon: Clock,           roles: ['admin'] },
  { to: '/planilla',      label: 'Planilla',         Icon: DollarSign,      roles: ['admin'] },
  { to: '/comisiones',    label: 'Comisiones',       Icon: TrendingUp,      roles: ['admin'] },
  // ── Operaciones ──
  { to: '/inventario',    label: 'Inventario',       Icon: Package,         roles: ['admin', 'tienda'] },
  { to: '/bitacora-stock',label: 'Bitácora Stock',   Icon: BookOpen,        roles: ['admin', 'tienda'] },
  { to: '/clientes',      label: 'Clientes',         Icon: UserCog,         roles: ['admin', 'tienda'] },
  { to: '/crm',           label: 'CRM',              Icon: BarChart2,       roles: ['admin', 'tienda'] },
  { to: '/comprobantes',  label: 'Comprobantes',     Icon: Receipt,         roles: ['admin'] },
  // ── Admin ──
  { to: '/usuarios',      label: 'Usuarios',         Icon: Users,           roles: ['admin'] },
  { to: '/tiendas',       label: 'Tiendas',          Icon: Store,           roles: ['admin'] },
  { to: '/configuracion', label: 'Configuración',    Icon: Settings,        roles: ['admin'] },
]

export function AppLayout() {
  const { usuario, logout, isLoggingOut } = useAuth()
  const [collapsed, setCollapsed] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)

  const { data: anomaliasData } = useQuery({
    queryKey: ['dashboard-anomalias'],
    queryFn: () => dashboardApi.anomalias(),
    staleTime: 60_000,
    enabled: usuario?.rol === 'admin',
  })
  const anomaliasCount = anomaliasData?.count ?? 0

  const userRole = usuario?.rol ?? 'tienda'
  const visibleItems = NAV_ITEMS.filter((item) => item.roles.includes(userRole))

  function SidebarContent() {
    return (
      <div className="flex flex-col h-full">
        {/* Logo */}
        <div className={`flex items-center h-16 px-4 border-b border-slate-700 ${collapsed ? 'justify-center' : 'justify-between'}`}>
          {!collapsed && (
            <Link to="/" className="flex items-center gap-2">
              <span className="text-lg font-bold text-white tracking-tight">SIS-KYRO</span>
              {usuario?.tienda_id && (
                <span className="text-xs bg-blue-500/20 text-blue-300 px-1.5 py-0.5 rounded font-medium">
                  {usuario.tienda_id}
                </span>
              )}
            </Link>
          )}
          <button
            onClick={() => setCollapsed((c) => !c)}
            className="hidden lg:flex items-center justify-center w-7 h-7 rounded-md text-slate-400 hover:bg-slate-700 hover:text-white transition-colors"
          >
            {collapsed ? <ChevronRight size={14} /> : <ChevronLeft size={14} />}
          </button>
        </div>

        {/* Nav */}
        <nav className="flex-1 overflow-y-auto py-3 px-2 space-y-0.5">
          {visibleItems.map(({ to, label, Icon, soon }) => {
            if (soon) {
              return (
                <div
                  key={to}
                  title={collapsed ? label : undefined}
                  className={`flex items-center gap-3 px-3 py-2 rounded-md text-slate-500 cursor-not-allowed select-none ${collapsed ? 'justify-center' : ''}`}
                >
                  <Icon size={16} className="shrink-0" />
                  {!collapsed && (
                    <span className="text-xs font-medium truncate flex-1">{label}</span>
                  )}
                  {!collapsed && (
                    <span className="text-[10px] bg-slate-700 text-slate-400 px-1.5 py-0.5 rounded">Soon</span>
                  )}
                </div>
              )
            }

            const showBell = to === '/historial' && anomaliasCount > 0 && !collapsed

            return (
              <NavLink
                key={to}
                to={to}
                end={to === '/'}
                title={collapsed ? label : undefined}
                onClick={() => setMobileOpen(false)}
                className={({ isActive }) =>
                  [
                    'flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors',
                    collapsed ? 'justify-center' : '',
                    isActive
                      ? 'bg-blue-600 text-white'
                      : 'text-slate-300 hover:bg-slate-700 hover:text-white',
                  ].join(' ')
                }
              >
                {({ isActive }) => (
                  <>
                    <Icon size={16} className="shrink-0" />
                    {!collapsed && <span className="truncate flex-1">{label}</span>}
                    {showBell && (
                      <span className={`text-xs rounded-full px-1.5 py-0.5 font-bold ${isActive ? 'bg-white/20 text-white' : 'bg-red-500 text-white'}`}>
                        {anomaliasCount > 99 ? '99+' : anomaliasCount}
                      </span>
                    )}
                  </>
                )}
              </NavLink>
            )
          })}
        </nav>

        {/* User + logout */}
        <div className="border-t border-slate-700 p-3">
          {!collapsed ? (
            <div className="space-y-2">
              <div className="flex items-center gap-2">
                <div className="w-7 h-7 rounded-full bg-blue-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                  {(usuario?.nombre ?? 'U').charAt(0).toUpperCase()}
                </div>
                <div className="min-w-0">
                  <p className="text-xs font-medium text-white truncate">{usuario?.nombre}</p>
                  <p className="text-xs text-slate-400 uppercase">{usuario?.rol}</p>
                </div>
              </div>
              <button
                onClick={() => logout()}
                disabled={isLoggingOut}
                className="w-full flex items-center gap-2 px-3 py-1.5 rounded-md text-xs text-slate-400 hover:bg-slate-700 hover:text-white transition-colors disabled:opacity-50"
              >
                <LogOut size={13} />
                {isLoggingOut ? 'Saliendo...' : 'Cerrar sesión'}
              </button>
            </div>
          ) : (
            <button
              onClick={() => logout()}
              disabled={isLoggingOut}
              title="Cerrar sesión"
              className="w-full flex justify-center p-2 rounded-md text-slate-400 hover:bg-slate-700 hover:text-white transition-colors"
            >
              <LogOut size={16} />
            </button>
          )}
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-screen bg-slate-50">
      {/* Sidebar desktop */}
      <aside
        className={[
          'hidden lg:flex flex-col bg-slate-900 shrink-0 transition-all duration-200',
          collapsed ? 'w-16' : 'w-60',
        ].join(' ')}
      >
        <SidebarContent />
      </aside>

      {/* Sidebar mobile overlay */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden flex">
          <div className="absolute inset-0 bg-black/50" onClick={() => setMobileOpen(false)} />
          <aside className="relative w-64 bg-slate-900 flex flex-col z-10">
            <SidebarContent />
          </aside>
        </div>
      )}

      {/* Main content */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Mobile header */}
        <header className="lg:hidden bg-white border-b border-gray-200 px-4 py-3 flex items-center gap-3">
          <button
            onClick={() => setMobileOpen(true)}
            className="p-1.5 rounded-md text-gray-600 hover:bg-gray-100"
          >
            <Menu size={20} />
          </button>
          <span className="font-semibold text-gray-800">SIS-KYRO</span>
          {anomaliasCount > 0 && usuario?.rol === 'admin' && (
            <span className="ml-auto flex items-center gap-1 text-xs bg-red-500 text-white px-2 py-0.5 rounded-full">
              <Bell size={11} /> {anomaliasCount}
            </span>
          )}
        </header>

        <main className="flex-1 p-6 overflow-auto">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
