import { useEffect, useRef, useState } from 'react'
import { Outlet, NavLink, Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import { useQuery } from '@tanstack/react-query'
import { controlCenterApi } from '../services/controlCenter.api'
import { useTheme } from '../hooks/useTheme'
import { ControlCenterPanel } from './ControlCenterPanel'
import { SquaresFour as LayoutDashboard, ClockCounterClockwise as History, ChartBar as BarChart2, CurrencyCircleDollar as CircleDollarSign, QrCode, CalendarCheck, Users, CurrencyDollar as DollarSign, Package, BookOpen, Buildings as Building2, IdentificationCard as IdCard, Handshake, Wallet, Faders as Settings2, Storefront as Store, SignOut as LogOut, Bell, CaretLeft as ChevronLeft, CaretRight as ChevronRight, CaretDown, ClipboardText as ClipboardList, List as Menu, Receipt, Files, Sun, Moon, ArrowsLeftRight as ArrowLeftRight, Bank as Landmark, Ticket, Megaphone, CellSignalFull as Signal, MapPin, Plugs as Plug, MagnifyingGlass } from '@phosphor-icons/react'
import { api } from '../services/api'

interface NavItem {
  to: string
  label: string
  Icon: React.ComponentType<{ size?: number; className?: string }>
  roles: string[]
  section: string
  soon?: boolean
  /** Color de acento propio (calca legacy `style="color:#c084fc"` en `sidebar-link`), p.ej. CRM. */
  accent?: string
}

// Orden y agrupación calcan las 5 secciones del sidebar legacy sis_bipay
// (includes/header.php): Gerencia / Administración / Inventario / Operaciones /
// Configuración. Cada item declara su `section`; el separador visual se calcula
// comparando con el item anterior VISIBLE (no por índice fijo), así el agrupado
// no se rompe según el rol del usuario. Los ítems del legacy lideran cada
// sección en su orden y label originales; los que solo existen en el refactor
// (sin equivalente en el sidebar legacy) van al final de la sección afín para
// no perder acceso. `Control Mensual`, `Liquidacion` y `Revisar Fotos` son
// pestañas dentro de la página `/asistencias` en el legacy, no entradas de
// menú: sus rutas siguen vivas, solo se accede a ellas desde la cabecera de
// Asistencias.
//
// Cada sección es colapsable (clic en el header) y el estado se persiste en
// localStorage por `section` (clave estable, independiente del label que
// cambia por rol, ver `sectionLabel`). Al navegar, la sección de la ruta
// activa se auto-expande si estaba colapsada, para no perder el highlight.
const NAV_ITEMS: NavItem[] = [
  // ── Gerencia ─────────────────────────────────────────────────────────────
  { to: '/',               label: 'Dashboard',       Icon: LayoutDashboard, roles: ['admin', 'tienda'], section: 'Gerencia' },
  { to: '/estadisticas',   label: 'Productividad',   Icon: BarChart2,       roles: ['admin', 'tienda'], section: 'Gerencia' },
  { to: '/crm',            label: 'CRM y Marketing', Icon: Megaphone,       roles: ['admin'],           section: 'Gerencia', accent: '#c084fc' },
  { to: '/revisar-stock',  label: 'Precios',         Icon: CircleDollarSign, roles: ['admin'],          section: 'Gerencia' },
  { to: '/historial',      label: 'Historial',       Icon: History,         roles: ['admin', 'tienda'], section: 'Gerencia' },
  { to: '/mi-historial',   label: 'Mi Historial',    Icon: History,         roles: ['tienda'],          section: 'Gerencia' },

  // ── Administración ───────────────────────────────────────────────────────
  { to: '/tiendas',        label: 'Tiendas',         Icon: Store,           roles: ['admin'],           section: 'Administración' },
  { to: '/usuarios',       label: 'Usuarios',        Icon: Users,           roles: ['admin'],           section: 'Administración' },
  { to: '/agentes',        label: 'Personal',        Icon: IdCard,          roles: ['admin'],           section: 'Administración' },
  { to: '/asistencias',    label: 'Asistencias',     Icon: CalendarCheck,   roles: ['admin'],           section: 'Administración' },
  { to: '/planilla',       label: 'Planilla',        Icon: DollarSign,      roles: ['admin'],           section: 'Administración' },
  { to: '/tickets',        label: 'Tickets',         Icon: Ticket,          roles: ['admin'],           section: 'Administración' },
  { to: '/comisiones',     label: 'Comisiones',      Icon: Settings2,       roles: ['admin'],           section: 'Administración' },
  { to: '/financieras',    label: 'Financieras',     Icon: Handshake,       roles: ['admin'],           section: 'Administración' },
  { to: '/reporte-bcp',    label: 'Reporte BCP',     Icon: Landmark,        roles: ['admin', 'tienda'], section: 'Administración' },
  { to: '/panel-bipay',    label: 'Bipay / Anypay',  Icon: Wallet,          roles: ['admin'],           section: 'Administración' },
  { to: '/postpago',       label: 'Churn / Postpago', Icon: Signal,         roles: ['admin'],           section: 'Administración' },
  { to: '/mapa-calor',     label: 'Mapa de Calor',   Icon: MapPin,          roles: ['admin'],           section: 'Administración' },
  { to: '/postular',       label: 'Registro de Datos', Icon: ClipboardList, roles: ['admin'],          section: 'Administración', accent: '#c084fc' },

  // ── Inventario ───────────────────────────────────────────────────────────
  { to: '/inventario',     label: 'Ver Inventario',  Icon: Package,         roles: ['admin', 'tienda'], section: 'Inventario' },
  { to: '/bitacora-stock', label: 'Bitácora Stock',  Icon: BookOpen,        roles: ['admin', 'tienda'], section: 'Inventario' },

  // ── Operaciones ──────────────────────────────────────────────────────────
  { to: '/reportes/nuevo', label: 'Reporte Diario',  Icon: ClipboardList,   roles: ['tienda', 'admin'], section: 'Operaciones' },
  { to: '/tickets',        label: 'Tickets Emitidos', Icon: Ticket,         roles: ['tienda'],          section: 'Operaciones' },
  { to: '/asistencias/qr', label: 'QR Asistencia',   Icon: QrCode,          roles: ['admin', 'tienda'], section: 'Operaciones' },

  // ── Configuración ────────────────────────────────────────────────────────
  { to: '/configuracion',  label: 'Perfil de Empresa', Icon: Building2,     roles: ['admin'],           section: 'Configuración' },
  { to: '/configuracion/facturacion', label: 'Facturación Electrónica', Icon: Receipt, roles: ['admin'], section: 'Configuración' },
  { to: '/comprobantes',   label: 'Comprobantes',    Icon: Files,           roles: ['admin'],           section: 'Configuración' },
  { to: '/integrador',     label: 'Integrador Bipay', Icon: Plug,           roles: ['admin', 'tienda'], section: 'Configuración' },
]

/** La 1ª sección del legacy se llama "Gerencia" para admin y "Mi Panel" para tienda. */
function sectionLabel(section: string, role: string) {
  return section === 'Gerencia' && role === 'tienda' ? 'Mi Panel' : section
}

const SIDEBAR_SECTIONS_KEY = 'kyro:sidebar-collapsed-sections'

function loadCollapsedSections(): Record<string, boolean> {
  try {
    const raw = localStorage.getItem(SIDEBAR_SECTIONS_KEY)
    return raw ? JSON.parse(raw) : {}
  } catch {
    return {}
  }
}

/** Cache local del logo de empresa (OPT-15) — ver comentario en el `useQuery` que lo usa. */
const LOGO_CACHE_KEY = 'kyro:logo-empresa-cache'

function leerCacheLogo(): { logo: string | null; ts: number } | null {
  try {
    const raw = localStorage.getItem(LOGO_CACHE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw)
    if (typeof parsed?.ts !== 'number') return null
    return parsed
  } catch {
    return null
  }
}

function escribirCacheLogo(logo: string | null | undefined) {
  try {
    if (logo === undefined) return
    localStorage.setItem(LOGO_CACHE_KEY, JSON.stringify({ logo, ts: Date.now() }))
  } catch {
    // localStorage lleno/inhabilitado (privado): degrada a pedir el logo cada sesión, sin romper.
  }
}

/**
 * Búsqueda global prominente del "Centro de Operaciones" (DIS-FX-01).
 *
 * Alcance elegido: filtro rápido de NAVEGACIÓN, no un buscador de datos.
 * Filtra los ítems de menú visibles por rol (label + sección) y navega al
 * seleccionar (Enter salta al primer resultado). No consulta agentes/reportes/
 * tickets del backend — el placeholder es aspiracional; el motor es el menú.
 * Glass tokenizado (dark + light) `h-10 rounded-xl`.
 */
function GlobalSearch({
  items,
  autoFocus = false,
  onNavigate,
}: {
  items: NavItem[]
  autoFocus?: boolean
  onNavigate?: () => void
}) {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [focused, setFocused] = useState(false)

  const term = q.trim().toLowerCase()
  const results = term
    ? items
        .filter((it) => it.label.toLowerCase().includes(term) || it.section.toLowerCase().includes(term))
        .slice(0, 8)
    : []
  const showResults = focused && term.length > 0

  function go(to: string) {
    navigate(to)
    setQ('')
    setFocused(false)
    onNavigate?.()
  }

  return (
    <div className="relative w-full">
      <MagnifyingGlass
        size={16}
        className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kyro-muted"
        aria-hidden
      />
      <input
        autoFocus={autoFocus}
        value={q}
        onChange={(e) => setQ(e.target.value)}
        onFocus={() => setFocused(true)}
        onBlur={() => setTimeout(() => setFocused(false), 120)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && results[0]) go(results[0].to)
          if (e.key === 'Escape') { setQ(''); e.currentTarget.blur() }
        }}
        placeholder="Buscar agente, reporte, ticket…"
        aria-label="Buscar en el menú"
        className="h-10 w-full rounded-xl border border-kyro-border bg-kyro-card pl-9 pr-3 text-sm text-kyro-text placeholder:text-kyro-muted shadow-kyro-card outline-none transition focus:border-kyro-indigo/60 focus:ring-2 focus:ring-kyro-indigo/20"
      />
      {showResults && (
        <div className="absolute left-0 right-0 top-full z-40 mt-2 overflow-hidden rounded-xl border border-kyro-border bg-kyro-card shadow-kyro-popover backdrop-blur-[18px]">
          {results.length === 0 ? (
            <p className="px-4 py-3 text-sm text-kyro-muted">Sin coincidencias en el menú.</p>
          ) : (
            results.map(({ to, label, section, Icon }) => (
              <button
                key={`${section}-${to}`}
                type="button"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => go(to)}
                className="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-kyro-body transition hover:bg-kyro-indigo/[0.08]"
              >
                <Icon size={16} className="shrink-0 text-kyro-indigo" />
                <span className="flex-1 truncate">{label}</span>
                <span className="shrink-0 text-[11px] uppercase tracking-wide text-kyro-muted">{section}</span>
              </button>
            ))
          )}
        </div>
      )}
    </div>
  )
}

export function AppLayout() {
  const { usuario, logout, isLoggingOut } = useAuth()
  const { isDark, toggleTheme } = useTheme()
  const location = useLocation()
  const [collapsed, setCollapsed]   = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const [mobileSearchOpen, setMobileSearchOpen] = useState(false)
  const [ccOpen, setCcOpen]         = useState(false)
  const [collapsedSections, setCollapsedSections] = useState<Record<string, boolean>>(loadCollapsedSections)
  const navItemRefs = useRef<Map<string, HTMLDivElement>>(new Map())

  useEffect(() => {
    localStorage.setItem(SIDEBAR_SECTIONS_KEY, JSON.stringify(collapsedSections))
  }, [collapsedSections])

  function toggleSection(section: string) {
    setCollapsedSections((prev) => ({ ...prev, [section]: !prev[section] }))
  }

  const { data: cc } = useQuery({
    queryKey: ['control-center'],
    queryFn: () => controlCenterApi.get(),
    staleTime: 25_000,
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
    enabled: usuario?.rol === 'admin',
  })
  const anomaliasCount = cc?.anomalias_caja.count ?? 0

  /* Logo de marca: logo de empresa configurable (ConfiguracionPage) si existe,
     SVG dorado del legacy como fallback. El endpoint con-logo es solo admin.
     OPT-15: el logo (data URI base64, ~cientos de KB) vive en BD/API — migrar a
     storage+URL con Cache-Control requiere tocar el filesystem read-only de
     producción (ver migración `create_configuracion_empresa_table`), así que
     queda para otra iteración. Mejora mínima aplicada aquí: persistir el
     último logo conocido en localStorage junto a su timestamp y usarlo como
     `initialData`, para que un reload completo de la SPA (que resetea el
     cache en memoria de React Query) no dispare una petición nueva si sigue
     dentro del staleTime — hoy cada F5 volvía a pedir el base64 aunque nada
     hubiera cambiado. Se invalida solo cuando cambia el valor (mutaciones de
     ConfiguracionPage ya invalidan esta queryKey). */
  const { data: logoEmpresa } = useQuery({
    queryKey: ['configuracion-con-logo'],
    queryFn: () => api.get<{ logo_base64?: string | null }>('/v1/configuracion/con-logo').then((r) => r.data.logo_base64 ?? null),
    staleTime: 5 * 60_000,
    enabled: usuario?.rol === 'admin',
    initialData: () => leerCacheLogo()?.logo,
    initialDataUpdatedAt: () => leerCacheLogo()?.ts,
  })

  useEffect(() => {
    if (logoEmpresa !== undefined) escribirCacheLogo(logoEmpresa)
  }, [logoEmpresa])

  /* Conteo de badge por ruta del sidebar (Control Center vivo) */
  const badgeByRoute: Record<string, number> = {
    '/historial':            cc?.anomalias_caja.count ?? 0,
    '/revisar-stock':        cc?.precios_pendientes.count ?? 0,
    '/financieras':          cc?.financieras_pendientes.count ?? 0,
    '/agentes':              cc?.postulantes_pendientes.count ?? 0,
    '/reporte-bcp':          cc?.alertas_bcp.count ?? 0,
    '/panel-bipay':          cc?.alertas_bipay.count ?? 0,
  }
  const notifCount = cc?.notificaciones_sistema.count ?? 0
  const ccAlertCount = anomaliasCount + (cc?.traslados_pendientes.count ?? 0) + notifCount
  const isAdmin = usuario?.rol === 'admin'

  const userRole     = usuario?.rol ?? 'tienda'
  const visibleItems = NAV_ITEMS.filter((item) => item.roles.includes(userRole))

  /* Sección de la ruta activa: se calcula en cada render (no en un efecto) para
     poder forzarla siempre expandida sin necesitar setState — así el usuario
     nunca pierde el highlight de dónde está parado, aunque haya colapsado esa
     sección antes. */
  const activeSection = (() => {
    const path = location.pathname
    let found: NavItem | null = null
    for (const item of visibleItems) {
      const matches = item.to === '/' ? path === '/' : (path === item.to || path.startsWith(item.to + '/'))
      if (matches && (!found || item.to.length > found.to.length)) found = item
    }
    return found?.section ?? null
  })()

  /* Auto-scroll al item activo del sidebar (como el legacy) al cambiar de ruta. */
  useEffect(() => {
    const path = location.pathname
    let activeTo: string | null = null
    for (const item of visibleItems) {
      const matches = item.to === '/' ? path === '/' : (path === item.to || path.startsWith(item.to + '/'))
      if (matches && (!activeTo || item.to.length > activeTo.length)) activeTo = item.to
    }
    if (activeTo) {
      navItemRefs.current.get(activeTo)?.scrollIntoView({ block: 'nearest' })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname, userRole])

  /* ── Theme-conditional styles ── */
  const mobileBg = isDark
    ? { background: '#18181b', borderBottom: '1px solid rgba(255,255,255,0.06)' }
    : { background: '#ffffff', borderBottom: '1px solid rgba(0,0,0,0.07)', boxShadow: '0 1px 8px rgba(0,0,0,0.06)' }

  /* Sidebar claro = azul corporativo Bitel rgba(0,53,128,0.95); solo el logo de
     marca conserva el dorado (DIS-FX-01), la navegación activa pasa a indigo.
     Estos tonos solo aplican dentro del sidebar (.kyro-sidebar) — el resto del
     tema claro no cambia. Contraste AA verificado sobre el navy efectivo
     (~rgb(12,63,134)): blanco 10.1:1, dorado #ffc200 (logo) 6.3:1, indigo
     activo con texto blanco AA, red-300 5.3:1, white/75 5.7:1, white/70 5.0:1. */
  const headerBorder = isDark ? 'border-[rgba(255,255,255,0.06)]' : 'border-[rgba(255,255,255,0.14)]'
  const footerBorder = isDark ? 'border-[rgba(255,255,255,0.06)]' : 'border-[rgba(255,255,255,0.14)]'

  const navActive = isDark
    ? 'bg-kyro-indigo/12 text-kyro-text border-l-[3px] border-kyro-indigo'
    : 'bg-kyro-indigo/25 text-white border-l-[3px] border-kyro-indigo'
  const navInactive = isDark
    ? 'text-kyro-body hover:bg-kyro-elevated/50'
    : 'text-white/85 hover:bg-white/10 hover:text-white'
  const collapseBtnCls  = isDark
    ? 'text-zinc-500 hover:text-zinc-200 hover:bg-zinc-800'
    : 'text-white/70 hover:text-white hover:bg-white/10'
  const userNameCls     = isDark ? 'text-zinc-200' : 'text-white'
  const userRoleCls     = isDark ? 'text-zinc-600' : 'text-white/70'
  const logoutCls       = isDark
    ? 'text-red-400 hover:bg-red-500/10'
    : 'text-red-300 hover:bg-white/10'
  const soonCls         = isDark ? 'text-zinc-700' : 'text-white/35'
  const soonBadgeCls    = isDark ? 'bg-zinc-800 text-zinc-600' : 'bg-white/10 text-white/50'
  const mobileMenuCls   = isDark
    ? 'text-zinc-500 hover:bg-zinc-800 hover:text-zinc-200'
    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700'

  function renderSidebarContent() {
    return (
      <div className="flex flex-col h-full">
        {/* Logo ─────────────────────────────────────────────────────────── */}
        <div
          className={`flex items-center h-16 px-4 border-b shrink-0 ${headerBorder}
            ${collapsed ? 'justify-center' : 'justify-between'}`}
        >
          {!collapsed && (
            <Link to="/" className="flex items-center gap-2 min-w-0 flex-1">
              {logoEmpresa ? (
                <img
                  src={logoEmpresa}
                  alt="Logo empresa"
                  className="w-8 h-8 rounded-md object-contain shrink-0"
                  style={{ background: 'rgba(255,194,0,0.15)' }}
                />
              ) : (
                <span
                  className="w-8 h-8 rounded-md flex items-center justify-center shrink-0"
                  style={{ background: 'rgba(255,194,0,0.15)' }}
                >
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M5 12.5C5 7.5 8 5 12 5C16 5 18 7 18 9.5C18 11.5 16.5 13 14 13H5V12.5Z" fill="#ffc200" />
                    <path d="M5 21.5C5 16.5 8 14 12 14C16 14 18 16 18 18.5C18 20.5 16.5 22 14 22H5V21.5Z" fill="#ffc200" />
                  </svg>
                </span>
              )}
              <span className="flex flex-col min-w-0 flex-1 leading-tight">
                <span className="font-orbitron text-sm font-bold tracking-wide uppercase text-kyro-gold truncate">
                  SIS-KYRO
                </span>
                <span className={`text-[9px] uppercase tracking-wide truncate ${isDark ? 'text-zinc-500' : 'text-white/70'}`}>
                  Panel de Gestión
                </span>
              </span>
            </Link>
          )}
          <div className={`flex items-center gap-1 shrink-0 ${collapsed ? '' : 'ml-auto'}`}>
            {/* Toggle de tema claro/oscuro — único lugar, junto a las acciones globales */}
            <button
              onClick={toggleTheme}
              title={isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
              className={`flex items-center justify-center w-7 h-7 rounded-md transition-colors ${collapseBtnCls}`}
            >
              {isDark ? <Sun size={14} /> : <Moon size={14} />}
            </button>
            {/* Centro de Control (campana) — solo admin */}
            {isAdmin && (
              <div className="relative">
                <button
                  onClick={() => setCcOpen((o) => !o)}
                  title="Centro de Control"
                  className={`relative flex items-center justify-center w-7 h-7 rounded-md transition-colors ${collapseBtnCls}`}
                >
                  <Bell size={14} weight={ccAlertCount > 0 ? 'fill' : 'regular'} />
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
          {visibleItems.map(({ to, label, Icon, soon, section, accent }, idx) => {
            const showSeparator = idx === 0 || section !== visibleItems[idx - 1].section
            const sectionIsCollapsed = !!collapsedSections[section] && section !== activeSection

            return (
              <div key={to} ref={(el) => { if (el) navItemRefs.current.set(to, el); else navItemRefs.current.delete(to) }}>
                {showSeparator && !collapsed && (
                  <button
                    type="button"
                    onClick={() => toggleSection(section)}
                    className="w-full flex items-center justify-between gap-2 pt-3 pb-1 px-3"
                  >
                    <span className={`block border-l-[3px] border-kyro-indigo pl-2 text-[0.70rem] font-extrabold uppercase tracking-wide ${isDark ? 'text-kyro-muted' : 'text-white/75'}`}>
                      {sectionLabel(section, userRole)}
                    </span>
                    <CaretDown
                      size={11}
                      weight="bold"
                      className={`shrink-0 mr-1 transition-transform duration-150 ${sectionIsCollapsed ? '-rotate-90' : ''} ${isDark ? 'text-kyro-muted' : 'text-white/60'}`}
                    />
                  </button>
                )}

                {(collapsed || !sectionIsCollapsed) && (soon ? (
                  <div
                    title={collapsed ? label : undefined}
                    className={`flex items-center gap-3 px-3 py-2 rounded-kyro text-[0.9rem] cursor-not-allowed select-none ${soonCls}
                      ${collapsed ? 'justify-center' : ''}`}
                  >
                    <Icon size={18} className="shrink-0" />
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
                    end={to === '/' || NAV_ITEMS.some(other => other.to !== to && other.to.startsWith(to + '/'))}
                    title={collapsed ? label : undefined}
                    onClick={() => setMobileOpen(false)}
                    className={({ isActive }) =>
                      [
                        'flex items-center gap-3 px-3 py-2 rounded-kyro text-[0.9rem] font-medium transition-all duration-150',
                        collapsed ? 'justify-center' : '',
                        isActive ? navActive : navInactive,
                      ].join(' ')
                    }
                    style={({ isActive }) =>
                      accent
                        ? isActive
                          ? { borderLeftColor: accent, color: accent, background: `${accent}1a` }
                          : { color: accent }
                        : undefined
                    }
                  >
                    {({ isActive }) => {
                      const badgeCount = badgeByRoute[to] ?? 0
                      const iconColor = accent ? '' : isActive ? 'text-kyro-indigo' : ''
                      return (
                        <>
                          <span className="relative shrink-0" style={accent ? { color: accent } : undefined}>
                            <Icon size={18} className={iconColor} />
                            {badgeCount > 0 && collapsed && (
                              <span className="badge-pulse absolute -top-1 -right-1 w-2 h-2 rounded-full bg-red-500" />
                            )}
                          </span>
                          {!collapsed && <span className="truncate flex-1">{label}</span>}
                          {badgeCount > 0 && !collapsed && (
                            <span className={`badge-pulse text-[10px] rounded-full px-1.5 py-0.5 font-bold shrink-0
                              ${isActive
                                ? isDark ? 'bg-kyro-indigo/20 text-kyro-indigo' : 'bg-indigo-100 text-indigo-700'
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
                ))}
              </div>
            )
          })}
        </nav>

        {/* Usuario ─────────────────────────────────────────────────────── */}
        <div className={`border-t ${footerBorder} p-3 shrink-0`}>
          {!collapsed ? (
            <div className="space-y-2">
              {/* Tarjeta de usuario */}
              <div
                className="rounded-kyro p-2 space-y-2"
                style={{ background: isDark ? 'rgba(255,255,255,0.03)' : 'rgba(255,255,255,0.08)' }}
              >
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
                    <span
                      className="inline-block text-[9px] px-1.5 py-0.5 rounded font-semibold mt-0.5"
                      style={{
                        background: isDark ? 'rgba(99,102,241,0.18)' : 'rgba(199,210,254,0.25)',
                        color: isDark ? '#a5b4fc' : '#eef2ff',
                      }}
                    >
                      {usuario?.tienda_id ?? 'CENTRAL'}
                    </span>
                  </div>
                </div>
              </div>

              {/* Aprobar Traslados — solo admin */}
              {isAdmin && (
                <Link
                  to="/traslados"
                  onClick={() => setMobileOpen(false)}
                  className="w-full flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-semibold text-white transition-opacity hover:opacity-90"
                  style={{ background: 'linear-gradient(135deg, #0ea5e9, #06b6d4)' }}
                >
                  <ArrowLeftRight size={13} />
                  Aprobar Traslados
                </Link>
              )}

              <button
                onClick={() => logout()}
                disabled={isLoggingOut}
                className={`w-full flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-semibold transition-colors disabled:opacity-40 ${logoutCls}`}
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
        className={[
          'kyro-sidebar hidden lg:flex flex-col shrink-0 transition-all duration-200',
          collapsed ? 'w-16' : 'w-[260px]',
        ].join(' ')}
      >
        {renderSidebarContent()}
      </aside>

      {/* Sidebar mobile overlay ──────────────────────────────────────────── */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden flex">
          <div
            className="absolute inset-0 bg-black/60"
            onClick={() => setMobileOpen(false)}
          />
          <aside
            className="kyro-sidebar relative z-10 flex w-[260px] flex-col"
          >
            {renderSidebarContent()}
          </aside>
        </div>
      )}

      {/* Main content ────────────────────────────────────────────────────── */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Barra superior desktop: búsqueda global prominente y centrada (glass
            light válido en ambos temas). Consume tokens kyro-* (no dark-only). */}
        <header className="hidden lg:flex h-16 shrink-0 items-center border-b border-kyro-border px-6 lg:px-8 kyro-glass">
          <div className="mx-auto w-full max-w-[560px]">
            <GlobalSearch items={visibleItems} />
          </div>
        </header>

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
            className="font-orbitron text-sm font-bold uppercase tracking-widest text-kyro-gold"
          >
            SIS-KYRO
          </span>
          <div className="ml-auto flex items-center gap-2">
            {/* Disparador de búsqueda de 40px (DIS-FX-01) */}
            <button
              onClick={() => setMobileSearchOpen((o) => !o)}
              title="Buscar"
              aria-label="Buscar"
              aria-expanded={mobileSearchOpen}
              className={`flex h-10 w-10 items-center justify-center rounded-md transition-colors ${mobileMenuCls}`}
            >
              <MagnifyingGlass size={18} />
            </button>
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
                  <Bell size={16} weight={ccAlertCount > 0 ? 'fill' : 'regular'} />
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

        {/* Panel de búsqueda móvil (se despliega desde el disparador de 40px) */}
        {mobileSearchOpen && (
          <div style={mobileBg} className="lg:hidden px-4 py-3">
            <GlobalSearch items={visibleItems} autoFocus onNavigate={() => setMobileSearchOpen(false)} />
          </div>
        )}

        <main className="app-canvas flex-1 overflow-auto px-4 py-5 md:px-6 lg:px-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
