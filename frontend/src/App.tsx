import { lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ProtectedRoute } from './components/ProtectedRoute'
import { AppLayout } from './components/AppLayout'

import { LoginPage } from './pages/auth/LoginPage'

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
const ComisionesPage     = lazy(() => import('./pages/comisiones/ComisionesPage').then(m => ({ default: m.ComisionesPage })))
const ConfiguracionPage  = lazy(() => import('./pages/admin/ConfiguracionPage').then(m => ({ default: m.ConfiguracionPage })))
const ComprobantesPage   = lazy(() => import('./pages/comprobantes/ComprobantesPage').then(m => ({ default: m.ComprobantesPage })))
const TerminalAsistenciaPage = lazy(() => import('./pages/asistencias/TerminalAsistenciaPage').then(m => ({ default: m.TerminalAsistenciaPage })))
const QrDisplayPage      = lazy(() => import('./pages/asistencias/QrDisplayPage').then(m => ({ default: m.QrDisplayPage })))
const TrasladosPage      = lazy(() => import('./pages/traslados/TrasladosPage').then(m => ({ default: m.TrasladosPage })))
const TrasladoChipsPage  = lazy(() => import('./pages/traslados/TrasladoChipsPage').then(m => ({ default: m.TrasladoChipsPage })))
const MatrizInventarioPage = lazy(() => import('./pages/inventario/MatrizInventarioPage').then(m => ({ default: m.MatrizInventarioPage })))
const ChipsGestionPage   = lazy(() => import('./pages/inventario/ChipsGestionPage').then(m => ({ default: m.ChipsGestionPage })))
const KardexInventarioPage = lazy(() => import('./pages/inventario/KardexInventarioPage').then(m => ({ default: m.KardexInventarioPage })))
const PanelFinancierasPage = lazy(() => import('./pages/admin/PanelFinancierasPage').then(m => ({ default: m.PanelFinancierasPage })))
const DiagnosticoPage    = lazy(() => import('./pages/admin/DiagnosticoPage').then(m => ({ default: m.DiagnosticoPage })))
const PostulacionesPage  = lazy(() => import('./pages/admin/PostulacionesPage').then(m => ({ default: m.PostulacionesPage })))
const TicketsPage        = lazy(() => import('./pages/tickets/TicketsPage').then(m => ({ default: m.TicketsPage })))
const PostulacionPublicaPage = lazy(() => import('./pages/PostulacionPublicaPage').then(m => ({ default: m.PostulacionPublicaPage })))
const TicketImpresionPage   = lazy(() => import('./pages/tickets/TicketImpresionPage').then(m => ({ default: m.TicketImpresionPage })))

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: 1, staleTime: 30_000 } },
})

function PageLoader() {
  return (
    <div className="flex items-center justify-center h-64 text-sm text-gray-400">
      Cargando...
    </div>
  )
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />

          {/* ── Rutas públicas ── */}
          <Route path="/terminal"           element={<Suspense fallback={<PageLoader />}><TerminalAsistenciaPage /></Suspense>} />
          <Route path="/postular"           element={<Suspense fallback={<PageLoader />}><PostulacionPublicaPage /></Suspense>} />
          <Route path="/tickets/imprimir/:id" element={<Suspense fallback={<PageLoader />}><TicketImpresionPage /></Suspense>} />

          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route index element={<Suspense fallback={<PageLoader />}><DashboardPage /></Suspense>} />
              <Route path="/historial" element={<Suspense fallback={<PageLoader />}><HistorialPage /></Suspense>} />
              <Route path="/mi-historial"   element={<Suspense fallback={<PageLoader />}><MiHistorialPage /></Suspense>} />
              <Route path="/bitacora-stock" element={<Suspense fallback={<PageLoader />}><BitacoraStockPage /></Suspense>} />
              <Route path="/agentes"        element={<Suspense fallback={<PageLoader />}><AgentesPage /></Suspense>} />
              <Route path="/agentes/:id"    element={<Suspense fallback={<PageLoader />}><VerAgentePage /></Suspense>} />
              <Route path="/clientes" element={<Suspense fallback={<PageLoader />}><ClientesPage /></Suspense>} />
              <Route path="/inventario" element={<Suspense fallback={<PageLoader />}><InventarioPage /></Suspense>} />
              <Route path="/reportes" element={<Suspense fallback={<PageLoader />}><ReportesPage /></Suspense>} />
              <Route path="/reportes/nuevo" element={<Suspense fallback={<PageLoader />}><NuevoReportePage /></Suspense>} />
              <Route path="/reportes/:id" element={<Suspense fallback={<PageLoader />}><ReporteDetallePage /></Suspense>} />
              <Route path="/reportes/:id/editar" element={<Suspense fallback={<PageLoader />}><EditarReportePage /></Suspense>} />
              <Route path="/planilla" element={<Suspense fallback={<PageLoader />}><PlanillaPage /></Suspense>} />
              <Route path="/crm" element={<Suspense fallback={<PageLoader />}><CrmPage /></Suspense>} />
              <Route path="/estadisticas"  element={<Suspense fallback={<PageLoader />}><EstadisticasPage /></Suspense>} />
              <Route path="/reporte-bcp"   element={<Suspense fallback={<PageLoader />}><ReporteBcpPage /></Suspense>} />
              <Route path="/usuarios"      element={<Suspense fallback={<PageLoader />}><UsuariosPage /></Suspense>} />
              <Route path="/tiendas"       element={<Suspense fallback={<PageLoader />}><TiendasPage /></Suspense>} />
              <Route path="/panel-bipay"   element={<Suspense fallback={<PageLoader />}><PanelBipayPage /></Suspense>} />
              <Route path="/asistencias"    element={<Suspense fallback={<PageLoader />}><AsistenciasPage /></Suspense>} />
              <Route path="/asistencias/qr" element={<Suspense fallback={<PageLoader />}><QrDisplayPage /></Suspense>} />
              <Route path="/comisiones"    element={<Suspense fallback={<PageLoader />}><ComisionesPage /></Suspense>} />
              <Route path="/configuracion" element={<Suspense fallback={<PageLoader />}><ConfiguracionPage /></Suspense>} />
              <Route path="/comprobantes"  element={<Suspense fallback={<PageLoader />}><ComprobantesPage /></Suspense>} />
              <Route path="/traslados"     element={<Suspense fallback={<PageLoader />}><TrasladosPage /></Suspense>} />
              <Route path="/traslados-chips"   element={<Suspense fallback={<PageLoader />}><TrasladoChipsPage /></Suspense>} />
              <Route path="/inventario/matriz"  element={<Suspense fallback={<PageLoader />}><MatrizInventarioPage /></Suspense>} />
              <Route path="/inventario/kardex" element={<Suspense fallback={<PageLoader />}><KardexInventarioPage /></Suspense>} />
              <Route path="/chips-gestion"     element={<Suspense fallback={<PageLoader />}><ChipsGestionPage /></Suspense>} />
              <Route path="/financieras"        element={<Suspense fallback={<PageLoader />}><PanelFinancierasPage /></Suspense>} />
              <Route path="/diagnostico"        element={<Suspense fallback={<PageLoader />}><DiagnosticoPage /></Suspense>} />
              <Route path="/admin/postulaciones" element={<Suspense fallback={<PageLoader />}><PostulacionesPage /></Suspense>} />
              <Route path="/tickets"            element={<Suspense fallback={<PageLoader />}><TicketsPage /></Suspense>} />
            </Route>
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
