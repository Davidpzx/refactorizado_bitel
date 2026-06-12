import { useState } from 'react'
import { Outlet, NavLink, Link } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import { useQuery } from '@tanstack/react-query'
import { controlCenterApi } from '../services/controlCenter.api'
import { useTheme } from '../context/ThemeContext'
import { ControlCenterPanel } from './ControlCenterPanel'
import {
  LayoutDashboard, History, BarChart2, CreditCard, FileText,
  Users, Clock, DollarSign, Package, BookOpen, Settings,
  UserCog, Store, LogOut, Bell, ChevronLeft, ChevronRight,
  ClipboardList, TrendingUp, Menu, Receipt, Sun, Moon, ArrowLeftRight, Layers,
  Grid3x3, Cpu, Landmark, Stethoscope, UserCheck, Ticket, ScrollText,
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
  { to: '/revisar-fotos',  label: 'Revisar Fotos',   Icon: UserCheck,       roles: ['admin'] },
  { to: '/revisar-stock',  label: 'Revisar Stock',   Icon: Package,         roles: ['admin'] },
  { to: '/planilla',       label: 'Planilla',        Icon: DollarSign,      roles: ['admin'] },
  { to: '/comisiones',     label: 'Comisiones',      Icon: TrendingUp,      roles: ['admin'] },
  { to: '/inventario',     label: 'Inventario',      Icon: Package,          roles: ['admin', 'tienda'] },
  { to: '/bitacora-stock', label: 'Bitácora Stock',  Icon: BookOpen,         roles: ['admin', 'tienda'] },
  { to: '/traslados',      label: 'Traslados',       Icon: ArrowLeftRight,   roles: ['admin', 'tienda'] },
  { to: '/traslados-chips',  label: 'Traslados Chips',  Icon: Layers,          roles: ['admin', 'tienda'] },
  { to: '/inventario/matriz',  label: 'Matriz Inventario', Icon: Grid3x3,        roles: ['admin', 'tienda'] },
  { to: '/inventario/kardex', label: 'Kardex',            Icon: ScrollText,     roles: ['admin'] },
  { to: '/chips-gestion',     label: 'Gestión Chips',     Icon: Cpu,            roles: ['admin', 'tienda'] },
  { to: '/financieras',      label: 'Financieras',      Icon: Landmark,        roles: ['admin'] },
  { to: '/diagnostico',      label: 'Diagnóstico',      Icon: Stethoscope,     roles: ['admin'] },
  { to: '/clientes',       label: 'Clientes',        Icon: UserCog,          roles: ['admin', 'tienda'] },
  { to: '/tickets',        label: 'Tickets',         Icon: Ticket,           roles: ['admin', 'tienda'] },
  { to: '/comprobantes',   label: 'Comprobantes',    Icon: Receipt,         roles: ['admin'] },
  { to: '/admin/postulaciones', label: 'Postulantes', Icon: UserCheck,      roles: ['admin'] },
  { to: '/usuarios',       label: 'Usuarios',        Icon: Users,           roles: ['admin'] },
  { to: '/tiendas',        label: 'Tiendas',         Icon: Store,           roles: ['admin'] },
  { to: '/configuracion',  label: 'Configuración',   Icon: Settings,        roles: ['admin'] },
]

const SECTION_SEPARATORS: Record<number, string> = {
  0:  'Operaciones',
  7:  'Personal',
  11: 'Recursos',
  17: 'Administración',
}

export function AppLayout() {
  const { usuario, logout, isLoggingOut } = useAuth()
  const { isDark, toggleTheme } = useTheme()
  const [collapsed, setCollapsed]   = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const [ccOpen, setCcOpen]         = useState(false)

  const { data: cc } = useQuery({
    queryKey: ['control-center'],
    queryFn: () => controlCenterApi.get(),
    staleTime: 25_000,
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
    enabled: usuario?.rol === 'admin',
  })
  const anomaliasCount = cc?.anomalias_caja.count ?? 0

  /* Conteo de badge por ruta del sidebar (Control Center vivo) */
  const badgeByRoute: Record<string, number> = {
    '/historial':            cc?.anomalias_caja.count ?? 0,
    '/revisar-stock':        cc?.precios_pendientes.count ?? 0,
    '/traslados':            cc?.traslados_pendientes.count ?? 0,
    '/financieras':          cc?.financieras_pendientes.count ?? 0,
    '/admin/postulaciones':  cc?.postulantes_pendientes.count ?? 0,
    '/reporte-bcp':          cc?.alertas_bcp.count ?? 0,
    '/panel-bipay':          cc?.alertas_bipay.count ?? 0,
  }
  const notifCount = cc?.notificaciones_sistema.count ?? 0
  const ccAlertCount = anomaliasCount + (cc?.traslados_pendientes.count ?? 0) + notifCount
  const isAdmin = usuario?.rol === 'admin'

  const userRole     = usuario?.rol ?? 'tienda'
  const visibleItems = NAV_ITEMS.filter((item) => item.roles.includes(userRole))

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

  /* ── Theme-conditional styles ── */
  const sidebarBg = isDark
    ? { background: 'rgba(9,9,11,0.88)', backdropFilter: 'blur(20px)', WebkitBackdropFilter: 'blur(20px)', borderRight: '1px solid rgba(255,255,255,0.06)' }
    : { background: 'rgba(255,255,255,0.93)', backdropFilter: 'blur(20px)', WebkitBackdropFilter: 'blur(20px)', borderRight: '1px solid rgba(0,0,0,0.07)', boxShadow: '2px 0 16px -4px rgba(0,0,0,0.07)' }

  const mobileBg = isDark
    ? { background: '#18181b', borderBottom: '1px solid rgba(255,255,255,0.06)' }
    : { background: '#ffffff', borderBottom: '1px solid rgba(0,0,0,0.07)', boxShadow: '0 1px 8px rgba(0,0,0,0.06)' }

  const headerBorder = isDark ? 'border-[rgba(255,255,255,0.06)]' : 'border-gray-100'
  const footerBorder = isDark ? 'border-[rgba(255,255,255,0.06)]' : 'border-gray-100'

  const navActive = isDark
    ? 'bg-zinc-800 text-zinc-100 border border-[rgba(255,255,255,0.08)] shadow-sm'
    : 'bg-gray-100 text-gray-900 border border-gray-200 shadow-sm'
  const navInactive = isDark
    ? 'text-zinc-400 hover:bg-zinc-800/60 hover:text-zinc-200'
    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800'

  const sectionLabelCls = isDark ? 'text-zinc-600' : 'text-gray-400'
  const dividerCls      = isDark ? 'border-[rgba(255,255,255,0.05)]' : 'border-gray-100'
  const logoTextCls     = isDark ? 'text-white' : 'text-gray-900'
  const collapseBtnCls  = isDark
    ? 'text-zinc-500 hover:text-zinc-200 hover:bg-zinc-800'
    : 'text-gray-400 hover:text-gray-700 hover:bg-gray-100'
  const userNameCls     = isDark ? 'text-zinc-200' : 'text-gray-800'
  const userRoleCls     = isDark ? 'text-zinc-600' : 'text-gray-400'
  const logoutCls       = isDark
    ? 'text-zinc-600 hover:bg-zinc-800 hover:text-zinc-300'
    : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700'
  const soonCls         = isDark ? 'text-zinc-700' : 'text-gray-300'
  const soonBadgeCls    = isDark ? 'bg-zinc-800 text-zinc-600' : 'bg-gray-100 text-gray-400'
  const mobileMenuCls   = isDark
    ? 'text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200'
    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700'

  function SidebarContent() {
    return (
      <div className="flex flex-col h-full">
        {/* Logo ─────────────────────────────────────────────────────────── */}
        <div
          className={`flex items-center h-16 px-4 border-b shrink-0 ${headerBorder}
            ${collapsed ? 'justify-center' : 'justify-between'}`}
        >
          {!collapsed && (
            <Link to="/" className="flex items-center gap-2 min-w-0">
              <span
                className={`text-base font-bold tracking-widest uppercase ${logoTextCls}`}
                style={{ fontFamily: "'Orbitron', sans-serif", letterSpacing: '0.18em' }}
              >
                SIS-KYRO
              </span>
              {usuario?.tienda_id && (
                <span
                  className="text-[10px] px-1.5 py-0.5 rounded font-semibold shrink-0"
                  style={{ background: 'rgba(255,194,0,0.15)', color: isDark ? '#ffc200' : '#d97706' }}
                >
                  {usuario.tienda_id}
                </span>
              )}
            </Link>
          )}
          <div className={`flex items-center gap-1 ${collapsed ? '' : 'ml-auto'}`}>
            {/* Centro de Control (campana) — solo admin */}
            {isAdmin && (
              <div className="relative">
                <button
                  onClick={() => setCcOpen((o) => !o)}
                  title="Centro de Control"
                  className={`relative flex items-center justify-center w-7 h-7 rounded-md transition-colors ${collapseBtnCls}`}
                >
                  <Bell size={14} />
                  {ccAlertCount > 0 && (
                    <span className="badge-pulse absolute -top-0.5 -right-0.5 min-w-[14px] h-[14px] px-0.5 flex items-center justify-center text-[8px] font-bold rounded-full text-white"
                      style={{ background: '#ef4444' }}>
                      {ccAlertCount > 99 ? '99+' : ccAlertCount}
                    </span>
                  )}
                </button>
                {ccOpen && (
                  <div className="absolute right-0 top-full mt-2">
                    <ControlCenterPanel cc={cc} isDark={isDark} onClose={() => setCcOpen(false)} />
                  </div>
                )}
              </div>
            )}
            {/* Theme toggle */}
            <button
              onClick={toggleTheme}
              title={isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
              className={`flex items-center justify-center w-7 h-7 rounded-md transition-colors ${collapseBtnCls}`}
            >
              {isDark ? <Sun size={13} /> : <Moon size={13} />}
            </button>
            {/* Collapse toggle (desktop only) */}
            <button
              onClick={() => setCollapsed((c) => !c)}
              className={`hidden lg:flex items-center justify-center w-7 h-7 rounded-md transition-colors ${collapseBtnCls}`}
            >
              {collapsed ? <ChevronRight size={14} /> : <ChevronLeft size={14} />}
            </button>
          </div>
        </div>

        {/* Nav ─────────────────────────────────────────────────────────── */}
        <nav className="flex-1 overflow-y-auto py-3 px-2 space-y-0.5">
          {visibleItems.map(({ to, label, Icon, soon }, idx) => {
            const sectionLabel = separatorMap[idx]

            return (
              <div key={to}>
                {sectionLabel && !collapsed && idx !== 0 && (
                  <div className="pt-3 pb-1 px-3">
                    <span className={`text-[10px] font-semibold uppercase tracking-widest ${sectionLabelCls}`}>
                      {sectionLabel}
                    </span>
                  </div>
                )}
                {sectionLabel && !collapsed && idx !== 0 && (
                  <div className={`mx-3 mb-1 border-t ${dividerCls}`} />
                )}

                {soon ? (
                  <div
                    title={collapsed ? label : undefined}
                    className={`flex items-center gap-3 px-3 py-2 rounded-md cursor-not-allowed select-none ${soonCls}
                      ${collapsed ? 'justify-center' : ''}`}
                  >
                    <Icon size={15} className="shrink-0" />
                    {!collapsed && (
                      <>
                        <span className="text-xs font-medium truncate flex-1">{label}</span>
                        <span className={`text-[9px] px-1.5 py-0.5 rounded ${soonBadgeCls}`}>Soon</span>
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
                        isActive ? navActive : navInactive,
                      ].join(' ')
                    }
                  >
                    {({ isActive }) => {
                      const badgeCount = badgeByRoute[to] ?? 0
                      const iconColor = isActive
                        ? isDark ? 'text-[#ffc200]' : 'text-amber-600'
                        : ''
                      return (
                        <>
                          <span className="relative shrink-0">
                            <Icon size={15} className={iconColor} />
                            {badgeCount > 0 && collapsed && (
                              <span className="badge-pulse absolute -top-1 -right-1 w-2 h-2 rounded-full bg-red-500" />
                            )}
                          </span>
                          {!collapsed && <span className="truncate flex-1">{label}</span>}
                          {badgeCount > 0 && !collapsed && (
                            <span className={`badge-pulse text-[10px] rounded-full px-1.5 py-0.5 font-bold shrink-0
                              ${isActive
                                ? isDark ? 'bg-[rgba(255,194,0,0.2)] text-[#ffc200]' : 'bg-amber-100 text-amber-700'
                                : isDark ? 'bg-[rgba(239,68,68,0.2)] text-red-400 border border-red-500/30' : 'bg-red-100 text-red-600 border border-red-200'
                              }`}
                            >
                              {badgeCount > 99 ? '99+' : badgeCount}
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

        {/* Usuario ─────────────────────────────────────────────────────── */}
        <div className={`border-t ${footerBorder} p-3 shrink-0`}>
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
                  <p className={`text-[12px] font-semibold truncate ${userNameCls}`}>{usuario?.nombre}</p>
                  <p className={`text-[10px] uppercase tracking-wider ${userRoleCls}`}>{usuario?.rol}</p>
                </div>
              </div>
              <button
                onClick={() => logout()}
                disabled={isLoggingOut}
                className={`w-full flex items-center gap-2 px-3 py-1.5 rounded-md text-xs transition-colors disabled:opacity-40 ${logoutCls}`}
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
              className={`w-full flex justify-center p-2 rounded-md transition-colors ${logoutCls}`}
            >
              <LogOut size={15} />
            </button>
          )}
        </div>
      </div>
    )
  }

  return (
    <div
      className="flex h-screen overflow-hidden"
      style={{ background: isDark ? '#09090b' : '#f8fafc' }}
    >
      {/* Sidebar desktop ────────────────────────────────────────────────── */}
      <aside
        style={sidebarBg}
        className={[
          'hidden lg:flex flex-col shrink-0 transition-all duration-200',
          collapsed ? 'w-16' : 'w-60',
        ].join(' ')}
      >
        <SidebarContent />
      </aside>

      {/* Sidebar mobile overlay ──────────────────────────────────────────── */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden flex">
          <div
            className="absolute inset-0 bg-black/60"
            onClick={() => setMobileOpen(false)}
          />
          <aside
            style={{ ...sidebarBg, background: isDark ? 'rgba(9,9,11,0.97)' : 'rgba(255,255,255,0.97)' }}
            className="relative w-64 flex flex-col z-10"
          >
            <SidebarContent />
          </aside>
        </div>
      )}

      {/* Main content ────────────────────────────────────────────────────── */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Mobile header */}
        <header
          style={mobileBg}
          className="lg:hidden px-4 py-3 flex items-center gap-3"
        >
          <button
            onClick={() => setMobileOpen(true)}
            className={`p-1.5 rounded-md transition-colors ${mobileMenuCls}`}
          >
            <Menu size={18} />
          </button>
          <span
            className={`font-bold tracking-widest text-sm uppercase ${logoTextCls}`}
            style={{ fontFamily: "'Orbitron', sans-serif" }}
          >
            SIS-KYRO
          </span>
          <div className="ml-auto flex items-center gap-2">
            <button
              onClick={toggleTheme}
              title={isDark ? 'Modo claro' : 'Modo oscuro'}
              className={`p-1.5 rounded-md transition-colors ${mobileMenuCls}`}
            >
              {isDark ? <Sun size={16} /> : <Moon size={16} />}
            </button>
            {isAdmin && (
              <div className="relative">
                <button
                  onClick={() => setCcOpen((o) => !o)}
                  title="Centro de Control"
                  className={`relative p-1.5 rounded-md transition-colors ${mobileMenuCls}`}
                >
                  <Bell size={16} />
                  {ccAlertCount > 0 && (
                    <span className="badge-pulse absolute -top-0.5 -right-0.5 min-w-[15px] h-[15px] px-0.5 flex items-center justify-center text-[8px] font-bold rounded-full text-white"
                      style={{ background: '#ef4444' }}>
                      {ccAlertCount > 99 ? '99+' : ccAlertCount}
                    </span>
                  )}
                </button>
                {ccOpen && (
                  <div className="absolute right-0 top-full mt-2">
                    <ControlCenterPanel cc={cc} isDark={isDark} onClose={() => setCcOpen(false)} />
                  </div>
                )}
              </div>
            )}
          </div>
        </header>

        <main className="flex-1 p-6 overflow-auto">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
