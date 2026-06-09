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

          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route index element={<Suspense fallback={<PageLoader />}><DashboardPage /></Suspense>} />
              <Route path="/historial" element={<Suspense fallback={<PageLoader />}><HistorialPage /></Suspense>} />
              <Route path="/agentes" element={<Suspense fallback={<PageLoader />}><AgentesPage /></Suspense>} />
              <Route path="/clientes" element={<Suspense fallback={<PageLoader />}><ClientesPage /></Suspense>} />
              <Route path="/inventario" element={<Suspense fallback={<PageLoader />}><InventarioPage /></Suspense>} />
              <Route path="/reportes" element={<Suspense fallback={<PageLoader />}><ReportesPage /></Suspense>} />
              <Route path="/reportes/nuevo" element={<Suspense fallback={<PageLoader />}><NuevoReportePage /></Suspense>} />
              <Route path="/reportes/:id" element={<Suspense fallback={<PageLoader />}><ReporteDetallePage /></Suspense>} />
              <Route path="/planilla" element={<Suspense fallback={<PageLoader />}><PlanillaPage /></Suspense>} />
              <Route path="/crm" element={<Suspense fallback={<PageLoader />}><CrmPage /></Suspense>} />
            </Route>
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
