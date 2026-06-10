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
  { to: '/',               label: 'Dashboard',      Icon: LayoutDashboard, roles: ['admin', 'tienda'] },
  { to: '/historial',      label: 'Historial',       Icon: History,         roles: ['admin'] },
  { to: '/reportes/nuevo', label: 'Nuevo Cuadre',    Icon: ClipboardList,   roles: ['tienda'] },
  { to: '/mi-historial',   label: 'Mi Historial',    Icon: History,         roles: ['tienda'] },
  { to: '/estadisticas',   label: 'Estadísticas',    Icon: BarChart2,       roles: ['admin'] },
  { to: '/panel-bipay',    label: 'Panel Bipay',     Icon: CreditCard,      roles: ['admin'] },
  { to: '/reporte-bcp',    label: 'Reporte BCP',     Icon: FileText,        roles: ['admin', 'tienda'] },
  { to: '/agentes',        label: 'Agentes',         Icon: Users,           roles: ['admin'] },
  { to: '/asistencias',    label: 'Asistencias',     Icon: Clock,           roles: ['admin'] },
  { to: '/planilla',       label: 'Planilla',        Icon: DollarSign,      roles: ['admin'] },
  { to: '/comisiones',     label: 'Comisiones',      Icon: TrendingUp,      roles: ['admin'] },
  { to: '/inventario',     label: 'Inventario',      Icon: Package,         roles: ['admin', 'tienda'] },
  { to: '/bitacora-stock', label: 'Bitácora Stock',  Icon: BookOpen,        roles: ['admin', 'tienda'] },
  { to: '/clientes',       label: 'Clientes',        Icon: UserCog,         roles: ['admin', 'tienda'] },
  { to: '/comprobantes',   label: 'Comprobantes',    Icon: Receipt,         roles: ['admin'] },
  { to: '/usuarios',       label: 'Usuarios',        Icon: Users,           roles: ['admin'] },
  { to: '/tiendas',        label: 'Tiendas',         Icon: Store,           roles: ['admin'] },
  { to: '/configuracion',  label: 'Configuración',   Icon: Settings,        roles: ['admin'] },
]

/* Separadores de sección por índice visual */
const SECTION_SEPARATORS: Record<number, string> = {
  0:  'Operaciones',
  7:  'Personal',
  11: 'Recursos',
  15: 'Administración',
}

export function AppLayout() {
  const { usuario, logout, isLoggingOut } = useAuth()
  const [collapsed, setCollapsed]   = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)

  const { data: anomaliasData } = useQuery({
    queryKey: ['dashboard-anomalias'],
    queryFn: () => dashboardApi.anomalias(),
    staleTime: 60_000,
    enabled: usuario?.rol === 'admin',
  })
  const anomaliasCount = anomaliasData?.count ?? 0

  const userRole     = usuario?.rol ?? 'tienda'
  const visibleItems = NAV_ITEMS.filter((item) => item.roles.includes(userRole))

  /* Mapa posición-real → índice-visible para separadores */
  function buildSeparatorMap() {
    const map: Record<number, string> = {}
    let visIdx = 0
    NAV_ITEMS.forEach((item, realIdx) => {
      if (!item.roles.includes(userRole)) return
      if (SECTION_SEPARATORS[realIdx]) map[visIdx] = SECTION_SEPARATORS[realIdx]
      visIdx++
    })
    return map
  }
  const separatorMap = buildSeparatorMap()

  function SidebarContent() {
    return (
      <div className="flex flex-col h-full">
        {/* ── Logo ─────────────────────────────────────────────────────── */}
        <div
          className={`flex items-center h-16 px-4 border-b shrink-0
            border-[rgba(255,255,255,0.06)]
            ${collapsed ? 'justify-center' : 'justify-between'}`}
        >
          {!collapsed && (
            <Link to="/" className="flex items-center gap-2 min-w-0">
              <span
                className="text-base font-bold tracking-widest uppercase text-white"
                style={{ fontFamily: "'Orbitron', sans-serif", letterSpacing: '0.18em' }}
              >
                SIS-KYRO
              </span>
              {usuario?.tienda_id && (
                <span className="text-[10px] px-1.5 py-0.5 rounded font-semibold shrink-0"
                  style={{ background: 'rgba(255,194,0,0.15)', color: '#ffc200' }}>
                  {usuario.tienda_id}
                </span>
              )}
            </Link>
          )}
          <button
            onClick={() => setCollapsed((c) => !c)}
            className="hidden lg:flex items-center justify-center w-7 h-7 rounded-md
              text-zinc-500 hover:text-zinc-200 hover:bg-zinc-800 transition-colors shrink-0"
          >
            {collapsed ? <ChevronRight size={14} /> : <ChevronLeft size={14} />}
          </button>
        </div>

        {/* ── Nav ──────────────────────────────────────────────────────── */}
        <nav className="flex-1 overflow-y-auto py-3 px-2 space-y-0.5">
          {visibleItems.map(({ to, label, Icon, soon }, idx) => {
            const sectionLabel = separatorMap[idx]

            return (
              <div key={to}>
                {/* Separador de sección */}
                {sectionLabel && !collapsed && idx !== 0 && (
                  <div className="pt-3 pb-1 px-3">
                    <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-600">
                      {sectionLabel}
                    </span>
                  </div>
                )}
                {sectionLabel && !collapsed && idx !== 0 && (
                  <div className="mx-3 mb-1 border-t border-[rgba(255,255,255,0.05)]" />
                )}

                {soon ? (
                  <div
                    title={collapsed ? label : undefined}
                    className={`flex items-center gap-3 px-3 py-2 rounded-md
                      text-zinc-700 cursor-not-allowed select-none
                      ${collapsed ? 'justify-center' : ''}`}
                  >
                    <Icon size={15} className="shrink-0" />
                    {!collapsed && (
                      <>
                        <span className="text-xs font-medium truncate flex-1">{label}</span>
                        <span className="text-[9px] bg-zinc-800 text-zinc-600 px-1.5 py-0.5 rounded">Soon</span>
                      </>
                    )}
                  </div>
                ) : (
                  <NavLink
                    to={to}
                    end={to === '/'}
                    title={collapsed ? label : undefined}
                    onClick={() => setMobileOpen(false)}
                    className={({ isActive }) =>
                      [
                        'flex items-center gap-3 px-3 py-2 rounded-md text-[13px] font-medium transition-all duration-150',
                        collapsed ? 'justify-center' : '',
                        isActive
                          ? 'bg-zinc-800 text-zinc-100 border border-[rgba(255,255,255,0.08)] shadow-sm'
                          : 'text-zinc-400 hover:bg-zinc-800/60 hover:text-zinc-200',
                      ].join(' ')
                    }
                  >
                    {({ isActive }) => {
                      const showBell = to === '/historial' && anomaliasCount > 0 && !collapsed
                      return (
                        <>
                          <Icon
                            size={15}
                            className={`shrink-0 ${isActive ? 'text-[#ffc200]' : ''}`}
                          />
                          {!collapsed && <span className="truncate flex-1">{label}</span>}
                          {showBell && (
                            <span className={`badge-pulse text-[10px] rounded-full px-1.5 py-0.5 font-bold shrink-0
                              ${isActive
                                ? 'bg-[rgba(255,194,0,0.2)] text-[#ffc200]'
                                : 'bg-[rgba(239,68,68,0.2)] text-red-400 border border-red-500/30'}`}
                            >
                              {anomaliasCount > 99 ? '99+' : anomaliasCount}
                            </span>
                          )}
                        </>
                      )
                    }}
                  </NavLink>
                )}
              </div>
            )
          })}
        </nav>

        {/* ── Usuario + logout ──────────────────────────────────────────── */}
        <div className="border-t border-[rgba(255,255,255,0.06)] p-3 shrink-0">
          {!collapsed ? (
            <div className="space-y-2">
              <div className="flex items-center gap-2">
                <div
                  className="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0"
                  style={{ background: 'linear-gradient(135deg, #6366f1, #4f46e5)' }}
                >
                  {(usuario?.nombre ?? 'U').charAt(0).toUpperCase()}
                </div>
                <div className="min-w-0">
                  <p className="text-[12px] font-semibold text-zinc-200 truncate">{usuario?.nombre}</p>
                  <p className="text-[10px] text-zinc-600 uppercase tracking-wider">{usuario?.rol}</p>
                </div>
              </div>
              <button
                onClick={() => logout()}
                disabled={isLoggingOut}
                className="w-full flex items-center gap-2 px-3 py-1.5 rounded-md text-xs
                  text-zinc-600 hover:bg-zinc-800 hover:text-zinc-300 transition-colors disabled:opacity-40"
              >
                <LogOut size={12} />
                {isLoggingOut ? 'Saliendo...' : 'Cerrar sesión'}
              </button>
            </div>
          ) : (
            <button
              onClick={() => logout()}
              disabled={isLoggingOut}
              title="Cerrar sesión"
              className="w-full flex justify-center p-2 rounded-md text-zinc-600
                hover:bg-zinc-800 hover:text-zinc-300 transition-colors"
            >
              <LogOut size={15} />
            </button>
          )}
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-screen" style={{ background: '#09090b' }}>

      {/* ── Sidebar desktop ───────────────────────────────────────────── */}
      <aside
        style={{
          background: 'rgba(9, 9, 11, 0.85)',
          backdropFilter: 'blur(20px)',
          WebkitBackdropFilter: 'blur(20px)',
          borderRight: '1px solid rgba(255,255,255,0.06)',
        }}
        className={[
          'hidden lg:flex flex-col shrink-0 transition-all duration-200',
          collapsed ? 'w-16' : 'w-60',
        ].join(' ')}
      >
        <SidebarContent />
      </aside>

      {/* ── Sidebar mobile overlay ────────────────────────────────────── */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden flex">
          <div className="absolute inset-0 bg-black/70" onClick={() => setMobileOpen(false)} />
          <aside
            style={{
              background: 'rgba(9, 9, 11, 0.95)',
              backdropFilter: 'blur(20px)',
              borderRight: '1px solid rgba(255,255,255,0.06)',
            }}
            className="relative w-64 flex flex-col z-10"
          >
            <SidebarContent />
          </aside>
        </div>
      )}

      {/* ── Main content ─────────────────────────────────────────────── */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Mobile header */}
        <header
          style={{
            background: '#18181b',
            borderBottom: '1px solid rgba(255,255,255,0.06)',
          }}
          className="lg:hidden px-4 py-3 flex items-center gap-3"
        >
          <button
            onClick={() => setMobileOpen(true)}
            className="p-1.5 rounded-md text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200 transition-colors"
          >
            <Menu size={18} />
          </button>
          <span
            className="font-bold text-white tracking-widest text-sm uppercase"
            style={{ fontFamily: "'Orbitron', sans-serif" }}
          >
            SIS-KYRO
          </span>
          {anomaliasCount > 0 && usuario?.rol === 'admin' && (
            <span className="ml-auto flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-full badge-pulse"
              style={{ background: 'rgba(239,68,68,0.2)', color: '#f87171', border: '1px solid rgba(239,68,68,0.3)' }}>
              <Bell size={10} /> {anomaliasCount}
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
