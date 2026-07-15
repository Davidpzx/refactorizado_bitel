import { lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider, keepPreviousData } from '@tanstack/react-query'
import { Capacitor } from '@capacitor/core'
import { ProtectedRoute } from './components/ProtectedRoute'
import { RolRoute } from './components/RolRoute'
import { ErrorBoundary } from './components/ErrorBoundary'

// AppLayout carga 39 iconos Phosphor + ControlCenterPanel: se difiere para no
// pagar ese peso en rutas públicas (login/terminal/postular/cpe) ni en el
// primer paint antes de que ProtectedRoute resuelva la sesión (OPT-11).
const AppLayout = lazy(() => import('./components/AppLayout').then(m => ({ default: m.AppLayout })))
const LoginPage  = lazy(() => import('./pages/auth/LoginPage').then(m => ({ default: m.LoginPage })))

// La app nativa (Capacitor) abre directo en el terminal de asistencia, no en el dashboard.
// Se reescribe la URL antes de que BrowserRouter monte para evitar un parpadeo del dashboard/login.
const isNativePlatform = Capacitor.isNativePlatform()
if (isNativePlatform && window.location.pathname === '/') {
  window.history.replaceState(null, '', '/terminal')
}

// DECISIÓN-APP-02: flag de emergencia para retirar el terminal web cuando la app
// nativa lo reemplace en todas las tiendas. Default true — mecanismo listo, sin activar.
const terminalWebHabilitado = import.meta.env.VITE_TERMINAL_WEB_HABILITADO !== 'false'

function TerminalWebDeshabilitado() {
  return (
    <div className="flex h-screen items-center justify-center bg-gray-950 px-4 text-center">
      <p className="text-lg text-gray-300">Usa la app de asistencia</p>
    </div>
  )
}

const DashboardPage      = lazy(() => import('./pages/DashboardPage').then(m => ({ default: m.DashboardPage })))
const AgentesPage        = lazy(() => import('./pages/agentes/AgentesPage').then(m => ({ default: m.AgentesPage })))
const ClientesPage       = lazy(() => import('./pages/clientes/ClientesPage').then(m => ({ default: m.ClientesPage })))
const InventarioPage     = lazy(() => import('./pages/inventario/InventarioPage').then(m => ({ default: m.InventarioPage })))
const ReportesPage       = lazy(() => import('./pages/reportes/ReportesPage').then(m => ({ default: m.ReportesPage })))
const NuevoReportePage   = lazy(() => import('./pages/reportes/NuevoReportePage').then(m => ({ default: m.NuevoReportePage })))
const ReporteDetallePage = lazy(() => import('./pages/reportes/ReporteDetallePage').then(m => ({ default: m.ReporteDetallePage })))
const PlanillaPage       = lazy(() => import('./pages/planilla/PlanillaPage').then(m => ({ default: m.PlanillaPage })))
const CrmPage            = lazy(() => import('./pages/crm/CrmPage').then(m => ({ default: m.CrmPage })))
const HistorialPage      = lazy(() => import('./pages/historial/HistorialPage').then(m => ({ default: m.HistorialPage })))
const MiHistorialPage    = lazy(() => import('./pages/reportes/MiHistorialPage').then(m => ({ default: m.MiHistorialPage })))
const EditarReportePage  = lazy(() => import('./pages/reportes/EditarReportePage').then(m => ({ default: m.EditarReportePage })))
const BitacoraStockPage  = lazy(() => import('./pages/inventario/BitacoraStockPage').then(m => ({ default: m.BitacoraStockPage })))
const VerAgentePage      = lazy(() => import('./pages/agentes/VerAgentePage').then(m => ({ default: m.VerAgentePage })))
const EstadisticasPage   = lazy(() => import('./pages/estadisticas/EstadisticasPage').then(m => ({ default: m.EstadisticasPage })))
const ReporteBcpPage     = lazy(() => import('./pages/bcp/ReporteBcpPage').then(m => ({ default: m.ReporteBcpPage })))
const UsuariosPage       = lazy(() => import('./pages/admin/UsuariosPage').then(m => ({ default: m.UsuariosPage })))
const TiendasPage        = lazy(() => import('./pages/admin/TiendasPage').then(m => ({ default: m.TiendasPage })))
const PanelBipayPage     = lazy(() => import('./pages/bipay/PanelBipayPage').then(m => ({ default: m.PanelBipayPage })))
const AsistenciasPage    = lazy(() => import('./pages/asistencias/AsistenciasPage').then(m => ({ default: m.AsistenciasPage })))
const ControlAsistenciasPage = lazy(() => import('./pages/asistencias/ControlAsistenciasPage').then(m => ({ default: m.ControlAsistenciasPage })))
const PresenciaPage      = lazy(() => import('./pages/asistencias/PresenciaPage').then(m => ({ default: m.PresenciaPage })))
const HistorialLiquidacionPage = lazy(() => import('./pages/asistencias/HistorialLiquidacionPage').then(m => ({ default: m.HistorialLiquidacionPage })))
const ComisionesPage     = lazy(() => import('./pages/comisiones/ComisionesPage').then(m => ({ default: m.ComisionesPage })))
const ConfiguracionPage  = lazy(() => import('./pages/admin/ConfiguracionPage').then(m => ({ default: m.ConfiguracionPage })))
const ComprobantesPage   = lazy(() => import('./pages/comprobantes/ComprobantesPage').then(m => ({ default: m.ComprobantesPage })))
const TerminalAsistenciaPage = lazy(() => import('./pages/asistencias/TerminalAsistenciaPage').then(m => ({ default: m.TerminalAsistenciaPage })))
const QrDisplayPage      = lazy(() => import('./pages/asistencias/QrDisplayPage').then(m => ({ default: m.QrDisplayPage })))
const TrasladosPage      = lazy(() => import('./pages/traslados/TrasladosPage').then(m => ({ default: m.TrasladosPage })))
const MatrizInventarioPage = lazy(() => import('./pages/inventario/MatrizInventarioPage').then(m => ({ default: m.MatrizInventarioPage })))
const ChipsGestionPage   = lazy(() => import('./pages/inventario/ChipsGestionPage').then(m => ({ default: m.ChipsGestionPage })))
const KardexInventarioPage = lazy(() => import('./pages/inventario/KardexInventarioPage').then(m => ({ default: m.KardexInventarioPage })))
const PanelFinancierasPage = lazy(() => import('./pages/admin/PanelFinancierasPage').then(m => ({ default: m.PanelFinancierasPage })))
const DiagnosticoPage    = lazy(() => import('./pages/admin/DiagnosticoPage').then(m => ({ default: m.DiagnosticoPage })))
const PostulacionesPage  = lazy(() => import('./pages/admin/PostulacionesPage').then(m => ({ default: m.PostulacionesPage })))
const TicketsPage        = lazy(() => import('./pages/tickets/TicketsPage').then(m => ({ default: m.TicketsPage })))
const PostulacionPublicaPage = lazy(() => import('./pages/PostulacionPublicaPage').then(m => ({ default: m.PostulacionPublicaPage })))
const TicketImpresionPage   = lazy(() => import('./pages/tickets/TicketImpresionPage').then(m => ({ default: m.TicketImpresionPage })))
const CpePublicoPage     = lazy(() => import('./pages/cpe/CpePublicoPage').then(m => ({ default: m.CpePublicoPage })))
const CpeImpresionPage   = lazy(() => import('./pages/cpe/CpeImpresionPage').then(m => ({ default: m.CpeImpresionPage })))
const RevisarStockPage   = lazy(() => import('./pages/admin/RevisarStockPage').then(m => ({ default: m.RevisarStockPage })))
const RevisarFotosPage   = lazy(() => import('./pages/admin/RevisarFotosPage').then(m => ({ default: m.RevisarFotosPage })))
const PostpagoPage       = lazy(() => import('./pages/postpago/PostpagoPage').then(m => ({ default: m.PostpagoPage })))
const MapaCalorPage      = lazy(() => import('./pages/analytics/MapaCalorPage'))
const IntegradorPage     = lazy(() => import('./pages/admin/IntegradorPage').then(m => ({ default: m.IntegradorPage })))
const ConfiguracionFacturacionPage = lazy(() => import('./pages/admin/ConfiguracionFacturacionPage').then(m => ({ default: m.ConfiguracionFacturacionPage })))

// Grupos de rol de la matriz congelada (plan/16-plan-roles.md). El agente NUNCA
// aparece en estos grupos: solo tiene acceso a las rutas "comunes" de más abajo
// (/reportes/nuevo, /reportes/:id[/editar], /mi-historial) — cualquier otra
// ruta lo redirige a /mi-historial (ver RolRoute).
const ADM_GER_JT = ['administrador', 'gerente', 'jefe_tienda']
const ADM_GER    = ['administrador', 'gerente']
const SOLO_ADMIN = ['administrador']

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
      // Al cambiar filtros/página, conserva los datos anteriores mientras
      // llega la respuesta nueva — evita el parpadeo a "Cargando" en tablas
      // paginadas/filtradas (equivalente a `keepPreviousData` de v4 en v5).
      placeholderData: keepPreviousData,
      // v5 ya trae `refetchOnWindowFocus: true` por defecto; se deja explícito
      // para que "volver a la pestaña" refresque todo sin depender de un
      // default implícito que alguien podría cambiar sin darse cuenta.
      refetchOnWindowFocus: true,
    },
  },
})

/** Skeleton sutil (mismo lenguaje visual que `KpiCard`: barras `animate-pulse`
 *  sobre `bg-kyro-border`) en vez de un texto "Cargando…" — percibido más
 *  rápido aunque tarde lo mismo, y no rompe de golpe el layout de la página
 *  siguiente mientras se resuelve el chunk lazy. */
function PageLoader() {
  return (
    <div className="flex h-64 w-full flex-col gap-3 px-4 py-6" aria-busy="true" aria-label="Cargando">
      <div className="h-6 w-1/3 animate-pulse rounded-md bg-kyro-border" />
      <div className="h-4 w-2/3 animate-pulse rounded-md bg-kyro-border/60" />
      <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className="h-24 animate-pulse rounded-[18px] bg-kyro-border/60" />
        <div className="h-24 animate-pulse rounded-[18px] bg-kyro-border/60" />
        <div className="h-24 animate-pulse rounded-[18px] bg-kyro-border/60" />
        <div className="h-24 animate-pulse rounded-[18px] bg-kyro-border/60" />
      </div>
      <div className="h-40 w-full animate-pulse rounded-xl bg-kyro-border/40" />
    </div>
  )
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<Suspense fallback={<PageLoader />}><LoginPage /></Suspense>} />

          {/* ── Rutas públicas ── */}
          <Route path="/terminal"           element={
            isNativePlatform || terminalWebHabilitado
              ? <Suspense fallback={<PageLoader />}><TerminalAsistenciaPage /></Suspense>
              : <TerminalWebDeshabilitado />
          } />
          <Route path="/postular"           element={<ErrorBoundary><Suspense fallback={<PageLoader />}><PostulacionPublicaPage /></Suspense></ErrorBoundary>} />
          <Route path="/tickets/imprimir/:id" element={<Suspense fallback={<PageLoader />}><TicketImpresionPage /></Suspense>} />
          <Route path="/cpe/:id"           element={<Suspense fallback={<PageLoader />}><CpePublicoPage /></Suspense>} />
          <Route path="/cpe/:id/imprimir"  element={<Suspense fallback={<PageLoader />}><CpeImpresionPage /></Suspense>} />

          <Route element={<ProtectedRoute />}>
            <Route element={<Suspense fallback={<PageLoader />}><AppLayout /></Suspense>}>
              {/* ── Rutas comunes a los 4 roles (el agente "opera lo suyo": su cuadre,
                  su historial, sus comisiones — estas últimas viven dentro de
                  MiHistorialPage) ── */}
              <Route path="/mi-historial"        element={<Suspense fallback={<PageLoader />}><MiHistorialPage /></Suspense>} />
              <Route path="/reportes/nuevo"      element={<Suspense fallback={<PageLoader />}><NuevoReportePage /></Suspense>} />
              <Route path="/reportes/:id"        element={<Suspense fallback={<PageLoader />}><ReporteDetallePage /></Suspense>} />
              <Route path="/reportes/:id/editar" element={<Suspense fallback={<PageLoader />}><EditarReportePage /></Suspense>} />
              {/* Ventas/Tickets: admin y gerente ven todas, jefe_tienda su tienda, agente crea los suyos */}
              <Route path="/tickets"             element={<Suspense fallback={<PageLoader />}><TicketsPage /></Suspense>} />

              {/* ── Administrador + Gerente + Jefe de Tienda (su tienda) — el agente NO ── */}
              <Route element={<RolRoute roles={ADM_GER_JT} />}>
                <Route index element={<Suspense fallback={<PageLoader />}><DashboardPage /></Suspense>} />
                <Route path="/historial"           element={<Suspense fallback={<PageLoader />}><HistorialPage /></Suspense>} />
                <Route path="/estadisticas"        element={<Suspense fallback={<PageLoader />}><EstadisticasPage /></Suspense>} />
                <Route path="/clientes"            element={<Suspense fallback={<PageLoader />}><ClientesPage /></Suspense>} />
                <Route path="/crm"                 element={<Suspense fallback={<PageLoader />}><CrmPage /></Suspense>} />
                <Route path="/inventario"          element={<Suspense fallback={<PageLoader />}><InventarioPage /></Suspense>} />
                <Route path="/inventario/matriz"   element={<Suspense fallback={<PageLoader />}><MatrizInventarioPage /></Suspense>} />
                <Route path="/inventario/kardex"   element={<Suspense fallback={<PageLoader />}><KardexInventarioPage /></Suspense>} />
                <Route path="/chips-gestion"       element={<Suspense fallback={<PageLoader />}><ChipsGestionPage /></Suspense>} />
                <Route path="/bitacora-stock"      element={<Suspense fallback={<PageLoader />}><BitacoraStockPage /></Suspense>} />
                <Route path="/traslados"           element={<Suspense fallback={<PageLoader />}><TrasladosPage /></Suspense>} />
                <Route path="/traslados-chips"     element={<Navigate to="/traslados" replace />} />
                <Route path="/reportes"            element={<Suspense fallback={<PageLoader />}><ReportesPage /></Suspense>} />
                <Route path="/panel-bipay"         element={<Suspense fallback={<PageLoader />}><PanelBipayPage /></Suspense>} />
                <Route path="/cuadre-bitel"        element={<Navigate to="/panel-bipay" replace />} />
                <Route path="/reporte-bcp"         element={<Suspense fallback={<PageLoader />}><ReporteBcpPage /></Suspense>} />
                <Route path="/comprobantes"        element={<Suspense fallback={<PageLoader />}><ComprobantesPage /></Suspense>} />
                <Route path="/postpago"            element={<Suspense fallback={<PageLoader />}><PostpagoPage /></Suspense>} />
                <Route path="/mapa-calor"          element={<Suspense fallback={<PageLoader />}><MapaCalorPage /></Suspense>} />
                {/* Asistencias VER: jefe_tienda es solo lectura (los botones de modificar
                    se ocultan dentro de cada página con esAdminOGerente) */}
                <Route path="/asistencias"         element={<Suspense fallback={<PageLoader />}><AsistenciasPage /></Suspense>} />
                <Route path="/asistencias/control" element={<Suspense fallback={<PageLoader />}><ControlAsistenciasPage /></Suspense>} />
                <Route path="/asistencias/presencia"   element={<Suspense fallback={<PageLoader />}><PresenciaPage /></Suspense>} />
                <Route path="/asistencias/qr"      element={<Suspense fallback={<PageLoader />}><QrDisplayPage /></Suspense>} />
                <Route path="/agentes"             element={<Suspense fallback={<PageLoader />}><AgentesPage /></Suspense>} />
                <Route path="/agentes/:id"         element={<Suspense fallback={<PageLoader />}><VerAgentePage /></Suspense>} />
              </Route>

              {/* ── Solo Administrador + Gerente (jefe_tienda y agente fuera: principio
                  anticorrupción del plan/16 — precios, planilla, aprobar, financieras) ── */}
              <Route element={<RolRoute roles={ADM_GER} />}>
                <Route path="/revisar-fotos"       element={<Suspense fallback={<PageLoader />}><RevisarFotosPage /></Suspense>} />
                <Route path="/revisar-stock"       element={<Suspense fallback={<PageLoader />}><RevisarStockPage /></Suspense>} />
                <Route path="/planilla"            element={<Suspense fallback={<PageLoader />}><PlanillaPage /></Suspense>} />
                {/* Liquidación de asistencias = descuentos de nómina → matriz: JT ❌ (Planilla/Liquidación) */}
                <Route path="/asistencias/liquidacion" element={<Suspense fallback={<PageLoader />}><HistorialLiquidacionPage /></Suspense>} />
                <Route path="/comisiones"          element={<Suspense fallback={<PageLoader />}><ComisionesPage /></Suspense>} />
                <Route path="/financieras"         element={<Suspense fallback={<PageLoader />}><PanelFinancierasPage /></Suspense>} />
                <Route path="/admin/postulaciones" element={<Suspense fallback={<PageLoader />}><PostulacionesPage /></Suspense>} />
                {/* Usuarios y Tiendas: gerente entra pero no puede crear Administradores
                    (gate en UsuariosPage, no en la ruta) */}
                <Route path="/usuarios"            element={<Suspense fallback={<PageLoader />}><UsuariosPage /></Suspense>} />
                <Route path="/tiendas"             element={<Suspense fallback={<PageLoader />}><TiendasPage /></Suspense>} />
                <Route path="/configuracion"       element={<Suspense fallback={<PageLoader />}><ConfiguracionPage /></Suspense>} />
              </Route>

              {/* ── Solo Administrador ── */}
              <Route element={<RolRoute roles={SOLO_ADMIN} />}>
                <Route path="/diagnostico"         element={<Suspense fallback={<PageLoader />}><DiagnosticoPage /></Suspense>} />
                <Route path="/integrador"          element={<Suspense fallback={<PageLoader />}><IntegradorPage /></Suspense>} />
                <Route path="/configuracion/facturacion" element={<Suspense fallback={<PageLoader />}><ConfiguracionFacturacionPage /></Suspense>} />
              </Route>
            </Route>
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
