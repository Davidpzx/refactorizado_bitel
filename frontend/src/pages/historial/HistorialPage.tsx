import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { historialApi } from '../../services/historial.api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { ChevronLeft, ChevronRight, Eye, FileSpreadsheet, CheckCircle, Pencil } from 'lucide-react'
import { api } from '../../services/api'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

function diferenciaClass(val: number) {
  if (val === 0) return 'bg-kpi-neutral/15 text-kyro-muted'
  return val < 0 ? 'bg-kyro-danger/15 text-kyro-danger' : 'bg-kyro-warning/15 text-kyro-warning'
}

function destinoBadgeClass(destino: string) {
  const map: Record<string, string> = {
    BANCO:     'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    ENTREGADO: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    EN_CAJA:   'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    GERENCIA:  'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
    AGENTE:    'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    TIENDA:    'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
  }
  return map[destino] ?? 'bg-gray-100 text-gray-600 dark:bg-zinc-800 dark:text-zinc-300'
}

const ESTADOS = [
  { value: '', label: 'Todos', tone: 'indigo' as const },
  { value: 'borrador', label: 'Borrador', tone: 'indigo' as const },
  { value: 'enviado', label: 'Enviado', tone: 'info' as const },
  { value: 'editado', label: 'Editado', tone: 'warning' as const },
  { value: 'aprobado', label: 'Aprobado', tone: 'success' as const },
]

export function HistorialPage() {
  const { usuario } = useAuth()
  const navigate = useNavigate()

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

  const aprobarEdicion = useMutation({
    mutationFn: (id: number) =>
      api.post(`/v1/reportes/${id}/aprobar-edicion`).then(r => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['historial'] }),
  })

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

  function exportarCSV() {
    const params = new URLSearchParams()
    if (applied.fecha_desde) params.set('fecha_desde', applied.fecha_desde)
    if (applied.fecha_hasta) params.set('fecha_hasta', applied.fecha_hasta)
    if (applied.tienda) params.set('tienda', applied.tienda)
    if (applied.agente_id) params.set('agente_id', String(applied.agente_id))
    if (applied.estado) params.set('estado', applied.estado)
    const token = localStorage.getItem('auth_token')
    const base = (api.defaults.baseURL ?? '').replace(/\/$/, '')
    const url = `${base}/v1/historial/exportar?${params.toString()}`
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then(r => r.blob())
      .then(blob => {
        const burl = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = burl
        link.download = `Historial_Global_Bloques_${new Date().toISOString().slice(0, 10)}.xlsx`
        link.click()
        URL.revokeObjectURL(burl)
      })
  }

  const tableHeaders = ['ID', 'Fecha', 'Agente / Tienda', 'Total', 'F. Esperado', 'F. Entregado', 'Diferencia', 'Destino', 'Estado', '']
  const rightAligned = new Set(['Total', 'F. Esperado', 'F. Entregado', 'Diferencia'])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Historial Completo de Reportes"
        description="Audita reportes, diferencias y estados con trazabilidad por período."
        actions={
          <Button variant="glassSuccess" size="sm" onClick={exportarCSV} className="gap-1.5">
            <FileSpreadsheet size={14} /> Exportar Excel
          </Button>
        }
      />

      <ListToolbar description="Combina fechas, tienda, agente y estado para localizar reportes específicos.">
        <div>
          <label className="block text-xs text-gray-500 dark:text-zinc-400 mb-1">Desde</label>
          <input
            type="date"
            value={filters.fecha_desde}
            onChange={(e) => setFilters((f) => ({ ...f, fecha_desde: e.target.value }))}
            className="kyro-input h-9 w-40"
          />
        </div>
        <div>
          <label className="block text-xs text-gray-500 dark:text-zinc-400 mb-1">Hasta</label>
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
              <label className="block text-xs text-gray-500 dark:text-zinc-400 mb-1">Tienda</label>
              <input
                type="text"
                placeholder="Todas"
                value={filters.tienda}
                onChange={(e) => setFilters((f) => ({ ...f, tienda: e.target.value }))}
                className="kyro-input h-9 w-32"
              />
            </div>
            <div>
              <label className="block text-xs text-gray-500 dark:text-zinc-400 mb-1">ID Agente</label>
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
          <label className="block text-xs text-gray-500 dark:text-zinc-400 mb-1">Estado</label>
          <SegmentedToggle
            ariaLabel="Filtrar historial por estado"
            size="sm"
            options={ESTADOS}
            value={filters.estado}
            onChange={(estado) => setFilters((f) => ({ ...f, estado }))}
          />
        </div>
        <Button variant="gold" onClick={applyFilters}>Buscar</Button>
        <Button variant="ghost" onClick={resetFilters}>Limpiar</Button>
      </ListToolbar>

      <div className="kyro-card overflow-hidden">
        <div className="flex items-center justify-between border-b border-kyro-border p-4">
          <h2 className="text-sm font-semibold text-kyro-text">
            {isLoading ? 'Cargando...' : `${meta?.total ?? 0} registros encontrados`}
          </h2>
          <span className="text-xs text-gray-400 dark:text-zinc-500">
            Página {meta?.current_page ?? 1} de {meta?.last_page ?? 1}
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="kyro-table-head">
                {tableHeaders.map((h) => (
                  <th
                    key={h}
                    className={`px-4 py-3 ${rightAligned.has(h) ? 'text-right' : 'text-left'}`}
                  >
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr>
                  <td colSpan={10} className="px-4 py-10 text-center text-gray-400 text-sm">
                    Cargando...
                  </td>
                </tr>
              )}
              {!isLoading && reportes.length === 0 && (
                <tr>
                  <td colSpan={10} className="px-4 py-10 text-center text-gray-400 text-sm">
                    Sin resultados para los filtros aplicados
                  </td>
                </tr>
              )}
              {reportes.map((r) => {
                const dif = Number(r.diferencia)
                const isSolicitado = r.estado_edicion === 'SOLICITADO'
                const isNegative   = dif < 0
                const rowCls = isSolicitado
                  ? 'border-b border-l-4 border-l-yellow-400 border-yellow-200 bg-yellow-50/80 animate-pulse dark:border-l-yellow-400 dark:border-yellow-400/20 dark:bg-yellow-400/[0.06]'
                  : isNegative
                  ? 'border-b border-l-4 border-l-red-400 border-red-100 bg-red-50/40 dark:border-l-red-400 dark:border-red-400/20 dark:bg-red-400/[0.06]'
                  : 'border-b border-kyro-border transition-colors hover:bg-kyro-gold/5'

                const canEdit    = usuario?.rol === 'admin' || r.estado_edicion === 'APROBADO'
                const editPending = r.estado_edicion === 'SOLICITADO' && usuario?.rol !== 'admin'

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
                      <span className={`inline-block px-2 py-0.5 text-xs rounded-full font-semibold ${destinoBadgeClass(r.destino_efectivo ?? 'TIENDA')}`}>
                        {r.destino_efectivo ?? 'TIENDA'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <EstadoBadge estado={r.estado} estadoEdicion={r.estado_edicion} />
                    </td>
                    <td className="px-4 py-3">
                      <TableActions>
                        <ActionIconButton
                          tone="view"
                          label="Ver detalle"
                          icon={<Eye size={15} />}
                          onClick={() => navigate(`/reportes/${r.id}`)}
                        />
                        {canEdit ? (
                          <ActionIconButton
                            tone="edit"
                            label="Editar reporte"
                            icon={<Pencil size={15} />}
                            onClick={() => navigate(`/reportes/${r.id}/editar`)}
                          />
                        ) : editPending ? (
                          <span
                            className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-yellow-300/40 text-yellow-400 opacity-60 cursor-default dark:border-yellow-500/20"
                            title="Solicitud de edición pendiente de aprobación"
                          >
                            <Pencil size={15} />
                          </span>
                        ) : null}
                        {isSolicitado && usuario?.rol === 'admin' && (
                          <Button
                            variant="gold"
                            size="sm"
                            disabled={aprobarEdicion.isPending && aprobarEdicion.variables === r.id}
                            onClick={() => {
                              if (confirm('¿Aprobar la solicitud de edición de este reporte?')) {
                                aprobarEdicion.mutate(r.id)
                              }
                            }}
                            className="h-8 gap-1 px-2 text-xs"
                          >
                            <CheckCircle size={12} /> Aprobar
                          </Button>
                        )}
                      </TableActions>
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
              <ChevronLeft size={14} /> Anterior
            </Button>
            <span className="text-xs text-gray-500 dark:text-zinc-400">
              {meta.from}–{meta.to} de {meta.total}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={page >= meta.last_page}
              onClick={() => goToPage(page + 1)}
            >
              Siguiente <ChevronRight size={14} />
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}

function EstadoBadge({ estado, estadoEdicion }: { estado: string; estadoEdicion?: string }) {
  if (estadoEdicion === 'SOLICITADO') {
    return <span className="inline-block px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300 font-medium">Ed. solicitada</span>
  }
  const map: Record<string, string> = {
    borrador: 'bg-kpi-neutral/15 text-kyro-muted',
    enviado:  'bg-kyro-info/15 text-kyro-info',
    editado:  'bg-kyro-warning/15 text-kyro-warning',
    aprobado: 'bg-kyro-success/15 text-kyro-success',
  }
  return (
    <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${map[estado] ?? 'bg-kpi-neutral/15 text-kyro-muted'}`}>
      {estado}
    </span>
  )
}
