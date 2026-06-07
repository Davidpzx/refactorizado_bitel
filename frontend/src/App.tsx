import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ProtectedRoute } from './components/ProtectedRoute'
import { AppLayout } from './components/AppLayout'
import { LoginPage } from './pages/auth/LoginPage'
import { DashboardPage } from './pages/DashboardPage'
import { AgentesPage } from './pages/agentes/AgentesPage'
import { ClientesPage } from './pages/clientes/ClientesPage'
import { InventarioPage } from './pages/inventario/InventarioPage'
import { ReportesPage } from './pages/reportes/ReportesPage'
import { NuevoReportePage } from './pages/reportes/NuevoReportePage'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
    },
  },
})

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Routes>
          {/* Pública */}
          <Route path="/login" element={<LoginPage />} />

          {/* Protegidas */}
          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route index element={<DashboardPage />} />
              <Route path="/agentes"         element={<AgentesPage />} />
              <Route path="/clientes"        element={<ClientesPage />} />
              <Route path="/inventario"      element={<InventarioPage />} />
              <Route path="/reportes"        element={<ReportesPage />} />
              <Route path="/reportes/nuevo"  element={<NuevoReportePage />} />
            </Route>
          </Route>

          {/* Fallback */}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
