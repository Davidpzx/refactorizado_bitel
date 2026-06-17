import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { historialApi } from '../../services/historial.api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { ChevronLeft, ChevronRight, Eye, Download, CheckCircle } from 'lucide-react'
import { api } from '../../services/api'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

function diferenciaClass(val: number) {
  if (val === 0) return 'bg-kpi-neutral/15 text-kyro-muted'
  return val < 0 ? 'bg-kyro-danger/15 text-kyro-danger' : 'bg-kyro-warning/15 text-kyro-warning'
}

const ESTADOS = ['', 'borrador', 'enviado', 'editado', 'aprobado']

export function HistorialPage() {
  const { usuario } = useAuth()

  const [filters, setFilters] = useState({
    fecha_desde: '',
    fecha_hasta: '',
    tienda: '',
    agente_id: '',
    estado: '',
  })
  const [page, setPage] = useState(1)
  const [applied, setApplied] = useState({ ...filters, page: 1 })

  const qc = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['historial', applied],
    queryFn: () =>
      historialApi.listar({
        ...applied,
        page: applied.page,
        per_page: 15,
        agente_id: applied.agente_id ? Number(applied.agente_id) : undefined,
        tienda: applied.tienda || undefined,
        estado: applied.estado || undefined,
        fecha_desde: applied.fecha_desde || undefined,
        fecha_hasta: applied.fecha_hasta || undefined,
      }),
  })

  const aprobarEdicion = useMutation({
    mutationFn: (id: number) =>
      api.post(`/v1/reportes/${id}/aprobar-edicion`).then(r => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['historial'] }),
  })

  const reportes = data?.data ?? []
  const meta = data

  function applyFilters() {
    setPage(1)
    setApplied({ ...filters, page: 1 })
  }

  function resetFilters() {
    const empty = { fecha_desde: '', fecha_hasta: '', tienda: '', agente_id: '', estado: '' }
    setFilters(empty)
    setPage(1)
    setApplied({ ...empty, page: 1 })
  }

  function goToPage(p: number) {
    setPage(p)
    setApplied((a) => ({ ...a, page: p }))
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Historial Completo de Reportes"
        description="Audita reportes, diferencias y estados con trazabilidad por período."
        actions={<Button
          variant="outline"
          size="sm"
          onClick={() => {
            const params = new URLSearchParams()
            if (applied.fecha_desde) params.set('fecha_desde', applied.fecha_desde)
            if (applied.fecha_hasta) params.set('fecha_hasta', applied.fecha_hasta)
            if (applied.tienda) params.set('tienda', applied.tienda)
            if (applied.agente_id) params.set('agente_id', String(applied.agente_id))
            if (applied.estado) params.set('estado', applied.estado)
            const token = localStorage.getItem('auth_token')
            const base = (api.defaults.baseURL ?? '').replace(/\/$/, '')
            const url = `${base}/v1/historial/exportar?${params.toString()}`
            const a = document.createElement('a')
            a.href = url
            a.setAttribute('data-auth', token ?? '')
            // Open in new tab — the browser will download the CSV
            fetch(url, { headers: { Authorization: `Bearer ${token}` } })
              .then(r => r.blob())
              .then(blob => {
                const burl = URL.createObjectURL(blob)
                const link = document.createElement('a')
                link.href = burl
                link.download = `historial_${new Date().toISOString().slice(0, 10)}.csv`
                link.click()
                URL.revokeObjectURL(burl)
              })
          }}
        >
          <Download size={14} /> Exportar CSV
        </Button>}
      />

      <ListToolbar description="Combina fechas, tienda, agente y estado para localizar reportes específicos.">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Desde</label>
            <input
              type="date"
              value={filters.fecha_desde}
              onChange={(e) => setFilters((f) => ({ ...f, fecha_desde: e.target.value }))}
              className="kyro-input h-9 w-40"
            />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Hasta</label>
            <input
              type="date"
              value={filters.fecha_hasta}
              onChange={(e) => setFilters((f) => ({ ...f, fecha_hasta: e.target.value }))}
              className="kyro-input h-9 w-40"
            />
          </div>
          {usuario?.rol === 'admin' && (
            <>
              <div>
                <label className="block text-xs text-gray-500 mb-1">Tienda</label>
                <input
                  type="text"
                  placeholder="Todas"
                  value={filters.tienda}
                  onChange={(e) => setFilters((f) => ({ ...f, tienda: e.target.value }))}
                  className="kyro-input h-9 w-32"
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">ID Agente</label>
                <input
                  type="number"
                  placeholder="Todos"
                  value={filters.agente_id}
                  onChange={(e) => setFilters((f) => ({ ...f, agente_id: e.target.value }))}
                  className="kyro-input h-9 w-28"
                />
              </div>
            </>
          )}
          <div>
            <label className="block text-xs text-gray-500 mb-1">Estado</label>
            <select
              value={filters.estado}
              onChange={(e) => setFilters((f) => ({ ...f, estado: e.target.value }))}
              className="kyro-input h-9 w-36"
            >
              {ESTADOS.map((e) => (
                <option key={e} value={e}>
                  {e === '' ? 'Todos' : e}
                </option>
              ))}
            </select>
          </div>
          <Button onClick={applyFilters}>Buscar</Button>
          <Button variant="outline" onClick={resetFilters}>Limpiar</Button>
      </ListToolbar>

      <div className="kyro-card overflow-hidden">
        <div className="flex items-center justify-between border-b border-kyro-border p-4">
          <h2 className="text-sm font-semibold text-kyro-text">
            {isLoading ? 'Cargando...' : `${meta?.total ?? 0} registros encontrados`}
          </h2>
          <span className="text-xs text-gray-400">
            Página {meta?.current_page ?? 1} de {meta?.last_page ?? 1}
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="kyro-table-head">
                {['ID', 'Fecha', 'Agente / Tienda', 'Total', 'F. Esperado', 'F. Entregado', 'Diferencia', 'Estado', ''].map((h) => (
                  <th
                    key={h}
                    className={`px-4 py-3 ${['Total', 'F. Esperado', 'F. Entregado', 'Diferencia'].includes(h) ? 'text-right' : 'text-left'}`}
                  >
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr>
                  <td colSpan={9} className="px-4 py-10 text-center text-gray-400 text-sm">
                    Cargando...
                  </td>
                </tr>
              )}
              {!isLoading && reportes.length === 0 && (
                <tr>
                  <td colSpan={9} className="px-4 py-10 text-center text-gray-400 text-sm">
                    Sin resultados para los filtros aplicados
                  </td>
                </tr>
              )}
              {reportes.map((r) => {
                const dif = Number(r.diferencia)
                const isSolicitado = r.estado_edicion === 'SOLICITADO'
                const rowCls = isSolicitado
                  ? 'border-b border-yellow-300 bg-yellow-50 animate-pulse'
                  : dif < 0
                  ? 'border-b border-red-100 bg-red-50/40'
                  : 'border-b border-kyro-border transition-colors hover:bg-kyro-gold/5'

                return (
                  <tr key={r.id} className={rowCls}>
                    <td className="px-4 py-3 font-mono text-xs text-kyro-muted">#{String(r.id).padStart(4, '0')}</td>
                    <td className="px-4 py-3 text-kyro-body">
                      {new Date(r.fecha + 'T00:00:00').toLocaleDateString('es-PE')}
                    </td>
                    <td className="px-4 py-3">
                      <div className="font-medium text-kyro-text">{r.agente_nombre ?? '—'}</div>
                      <div className="text-xs text-kyro-subtle">{r.tienda_id}</div>
                    </td>
                    <td className="px-4 py-3 text-right font-mono text-kyro-text">{fmt(r.total_calculado)}</td>
                    <td className="px-4 py-3 text-right font-mono text-kyro-body">{fmt(r.efectivo_esperado)}</td>
                    <td className="px-4 py-3 text-right font-mono text-kyro-text">{fmt(r.efectivo_entregado)}</td>
                    <td className="px-4 py-3 text-right">
                      <span className={`inline-block px-2 py-0.5 rounded-md text-xs font-bold ${diferenciaClass(dif)}`}>
                        {dif > 0 ? '+' : ''}{fmt(dif)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <EstadoBadge estado={r.estado} estadoEdicion={r.estado_edicion} />
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <Link
                          to={`/reportes/${r.id}`}
                          className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-indigo-600 transition-colors hover:bg-indigo-50 hover:text-indigo-800 dark:text-indigo-400 dark:hover:bg-indigo-400/10"
                        >
                          <Eye size={13} /> Ver
                        </Link>
                        {isSolicitado && usuario?.rol === 'admin' && (
                          <Button
                            size="sm"
                            disabled={aprobarEdicion.isPending && aprobarEdicion.variables === r.id}
                            onClick={() => {
                              if (confirm('¿Aprobar la solicitud de edición de este reporte?')) {
                                aprobarEdicion.mutate(r.id)
                              }
                            }}
                            className="h-7 gap-1 bg-kyro-success/15 px-2 text-xs text-kyro-success hover:bg-kyro-success/30"
                          >
                            <CheckCircle size={12} /> Aprobar edición
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {!isLoading && meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-kyro-border p-4">
            <Button
              variant="outline"
              size="sm"
              disabled={page <= 1}
              onClick={() => goToPage(page - 1)}
            >
              <ChevronLeft size={14} /> 