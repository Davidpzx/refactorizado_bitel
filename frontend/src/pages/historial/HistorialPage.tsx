import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { historialApi } from '../../services/historial.api'
import { reportesApi } from '../../services/reportes.api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { CaretLeft as ChevronLeft, CaretRight as ChevronRight, Eye, FileXls as FileSpreadsheet, CheckCircle, XCircle, PencilSimple as Pencil, Trash as Trash2, Wallet, ChartLineUp as TrendingUp, Scales as Scale, Money as Banknote } from '@phosphor-icons/react'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'
import { api } from '../../services/api'
import { Select } from '../../components/ui/select'
import { useTiendasSelect } from '../../hooks/useTiendasSelect'
import { useAgentesSelect } from '../../hooks/useAgentesSelect'

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
  const { tiendas } = useTiendasSelect()
  const { agentes } = useAgentesSelect()
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
  const confirmDialog = useConfirmDialog()

  const aprobarEdicion = useMutation({
    mutationFn: (id: number) =>
      api.post(`/v1/reportes/${id}/aprobar-edicion`).then(r => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['historial'] }),
  })

  const denegarEdicion = useMutation({
    mutationFn: ({ id, motivo }: { id: number; motivo?: string }) => reportesApi.denegarEdicion(id, motivo),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['historial'] }),
  })

  const eliminarReporte = useMutation({
    mutationFn: (id: number) => reportesApi.eliminar(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['historial'] })
      qc.invalidateQueries({ queryKey: ['historial-kpis'] })
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
      alert(msg || 'No se pudo eliminar el reporte.')
    },
  })

  async function handleEliminar(r: { id: number; fecha: string; tienda_id: string }) {
    const ok = await confirmDialog({
      title: `¿Eliminar el cuadre #${String(r.id).padStart(4, '0')} (${r.fecha?.slice(0, 10)} - ${r.tienda_id})?`,
      description: 'Todo el stock usado (equipos, accesorios, chips) regresará a la tienda y se anularán las comisiones generadas.\nEsta acción no se puede deshacer.',
      intent: 'danger',
      icon: Trash2,
      confirmLabel: 'Eliminar',
    })
    if (ok) eliminarReporte.mutate(r.id)
  }

  const filtrosQuery = {
    tienda: applied.tienda || undefined,
    agente_id: applied.agente_id ? Number(applied.agente_id) : undefined,
    estado: applied.estado || undefined,
    fecha_desde: applied.fecha_desde || undefined,
    fecha_hasta: applied.fecha_hasta || undefined,
  }

  const { data: kpisData } = useQuery({
    queryKey: ['historial-kpis', filtrosQuery],
    queryFn: () => historialApi.kpis(filtrosQuery),
  })

  const { data, isLoading } = useQuery({
    queryKey: ['historial', applied],
    queryFn: () =>
      historialApi.listar({
        ...applied,
        page: applied.page,
        per_page: 15,
        ...filtrosQuery,
      }),
  })

  const reportes = data?.data ?? []
  const meta = data
  const totales = kpisData?.totales

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
        link.download = `Historial_Global_${new Date().toISOString().slice(0, 10)}.xlsx`
        link.click()
        URL.revokeObjectURL(burl)
      })
  }

  const esAdmin = usuario?.rol === 'admin'
  const tableHeaders = esAdmin
    ? ['ID', 'Fecha', 'Agente / Tienda', 'Total', 'Ganancia', 'F. Esperado', 'F. Entregado', 'Diferencia', 'Destino', 'Estado', '']
    : ['ID', 'Fecha', 'Agente / Tienda', 'Total', 'F. Esperado', 'F. Entregado', 'Diferencia', 'Destino', 'Estado', '']
  const rightAligned = new Set(['Total', 'Ganancia', 'F. Esperado', 'F. Entregado', 'Diferencia'])

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

      {totales && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div className="kyro-card border-l-4 border-l-kpi-total p-4">
            <div className="flex items-center gap-2 mb-1">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-kyro-border bg-kyro-elevated text-kyro-info"><Wallet size={15} /></span>
              <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted">Total General</p>
            </div>
            <p className="mt-2 font-mono text-xl font-bold text-kyro-info">{fmt(totales.total_general)}</p>
          </div>
          <div className="kyro-card border-l-4 border-l-kyro-success p-4">
            <div className="flex items-center gap-2 mb-1">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-kyro-border bg-kyro-elevated text-kyro-success"><Scale size={15} /></span>
              <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted">Físico Esperado</p>
            </div>
            <p className="mt-2 font-mono text-xl font-bold text-kyro-success">{fmt(totales.fisico_esperado)}</p>
          </div>
          <div className="kyro-card border-l-4 border-l-kpi-neutral p-4">
            <div className="flex items-center gap-2 mb-1">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-kyro-border bg-kyro-elevated text-kyro-muted"><Banknote size={15} /></span>
              <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted">Físico Declarado</p>
            </div>
            <p className="mt-2 font-mono text-xl font-bold text-kyro-text">{fmt(totales.fisico_declarado)}</p>
          </div>
          <div className="kyro-card border-l-4 border-l-kyro-warning p-4">
            <div className="flex items-center gap-2 mb-1">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-kyro-border bg-kyro-elevated text-kyro-warning"><Scale size={15} /></span>
              <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted">Diferencia Física</p>
            </div>
            <p className={`mt-2 font-mono text-xl font-bold ${Number(totales.diferencia_fisica) === 0 ? 'text-kyro-muted' : Number(totales.diferencia_fisica) < 0 ? 'text-kyro-danger' : 'text-kyro-warning'}`}>
              {fmt(totales.diferencia_fisica)}
            </p>
          </div>
        </div>
      )}

      {usuario?.rol === 'admin' && kpisData?.ganancia_total != null && (
        <div className="kyro-card flex items-center justify-between border-l-4 border-l-kyro-success p-4">
          <div className="flex items-center gap-2">
            <span className="flex h-9 w-9 items-center justify-center rounded-lg border border-kyro-border bg-kyro-elevated text-kyro-success"><TrendingUp size={18} /></span>
            <div>
              <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted">Ganancia Total del Período</p>
              <p className="text-xs text-kyro-subtle">Suma de comisiones y ganancias registradas con los filtros actuales.</p>
            </div>
          </div>
          <p className="font-mono text-2xl font-bold text-kyro-success">{fmt(kpisData.ganancia_total)}</p>
        </div>
      )}

      {totales && (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
          <div className="kyro-card border-l-4 border-l-purple-400 p-4">
            <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted">Total Yape</p>
            <p className="mt-1 font-mono text-lg font-bold text-purple-500">{fmt(totales.total_yape)}</p>
          </div>
          <div className="kyro-card border-l-4 border-l-sky-400 p-4">
            <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted">Total Bipay</p>
            <p className="mt-1 font-mono text-lg font-bold text-sky-500">{fmt(totales.total_bipay)}</p>
          </div>
          <div className="kyro-card border-l-4 border-l-emerald-400 p-4">
            <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted">Total Transferencia</p>
            <p className="mt-1 font-mono text-lg font-bold text-emerald-500">{fmt(totales.total_transferencia)}</p>
          </div>
        </div>
      )}

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
              <Select value={filters.tienda} onChange={(e) => setFilters((f) => ({ ...f, tienda: e.target.value }))} className="h-9 w-44">
                <option value="">Todas</option>
                {tiendas.map(t => <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>)}
              </Select>
            </div>
            <div>
              <label className="block text-xs text-gray-500 dark:text-zinc-400 mb-1">ID Agente</label>
              <Select value={filters.agente_id} onChange={(e) => setFilters((f) => ({ ...f, agente_id: e.target.value }))} className="h-9 w-44">
                <option value="">Todos</option>
                {agentes.map(a => <option key={a.id} value={a.id}>{a.nombres}</option>)}
              </Select>
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
                  <td colSpan={tableHeaders.length} className="px-4 py-10 text-center text-gray-400 text-sm">
                    Cargando...
                  </td>
                </tr>
              )}
              {!isLoading && reportes.length === 0 && (
                <tr>
                  <td colSpan={tableHeaders.length} className="px-4 py-10 text-center text-gray-400 text-sm">
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

                const canEdit    = usuario?.rol === 'admin' || r.estado_edicion === 'APROBADO' || r.estado === 'borrador'
                const editPending = r.estado_edicion === 'SOLICITADO' && usuario?.rol !== 'admin'

                return (
                  <tr key={r.id} className={rowCls}>
                    <td className="px-4 py-3 font-mono text-xs text-kyro-muted">#{String(r.id).padStart(4, '0')}</td>
                    <td className="px-4 py-3 text-kyro-body">
                      {r.fecha ? new Date(r.fecha.slice(0, 10) + 'T00:00:00').toLocaleDateString('es-PE') : '—'}
                    </td>
                    <td className="px-4 py-3">
                      <div className="font-medium text-kyro-text">{r.agente_nombre ?? '—'}</div>
                      <div className="text-xs text-kyro-subtle">{r.tienda_id}</div>
                    </td>
                    <td className="px-4 py-3 text-right font-mono text-kyro-text">{fmt(r.total_calculado)}</td>
                    {esAdmin && (
                      <td className="px-4 py-3 text-right font-mono font-semibold text-kyro-success">{fmt(r.ganancia)}</td>
                    )}
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
                          <>
                            <Button
                              variant="gold"
                              size="sm"
                              disabled={aprobarEdicion.isPending && aprobarEdicion.variables === r.id}
                              onClick={async () => {
                                const ok = await confirmDialog({
                                  title: '¿Aprobar la solicitud de edición de este reporte?',
                                  intent: 'success',
                                  icon: CheckCircle,
                                  confirmLabel: 'Aprobar',
                                })
                                if (ok) aprobarEdicion.mutate(r.id)
                              }}
                              className="h-8 gap-1 px-2 text-xs"
                            >
                              <CheckCircle size={12} /> Aprobar
                            </Button>
                            <Button
                              variant="outline"
                              size="sm"
                              disabled={denegarEdicion.isPending && denegarEdicion.variables?.id === r.id}
                              onClick={() => {
                                const motivo = prompt('Motivo de la denegación (opcional):') ?? undefined
                                denegarEdicion.mutate({ id: r.id, motivo })
                              }}
                              className="h-8 gap-1 px-2 text-xs text-red-600 hover:bg-red-500/10 dark:text-red-400"
                            >
                              <XCircle size={12} /> Denegar
                            </Button>
                          </>
                        )}
                        {usuario?.rol === 'admin' && (
                          <ActionIconButton
                            tone="delete"
                            label="Eliminar reporte (revierte stock y comisiones)"
                            icon={<Trash2 size={15} />}
                            disabled={eliminarReporte.isPending && eliminarReporte.variables === r.id}
                            onClick={() => handleEliminar(r)}
                          />
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
