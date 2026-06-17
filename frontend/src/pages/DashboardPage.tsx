import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Bell, TrendingDown, TrendingUp, Pencil, X, Eye } from 'lucide-react'
import { dashboardApi } from '../services/dashboard.api'
import { reportesApi } from '../services/reportes.api'
import { useAuth } from '../hooks/useAuth'
import { Button } from '../components/ui/button'
import { PageHeader } from '../components/PageHeader'
import { ListToolbar } from '../components/ListToolbar'
import { apiErrorData } from '../lib/httpError'

// ── Helpers ──────────────────────────────────────────────────────────────────────────

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

function diferenciaClass(val: number) {
  if (val === 0) return 'bg-gray-100 text-gray-600 dark:bg-zinc-800 dark:text-zinc-400'
  return val < 0
    ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400'
    : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300'
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

const DESTINOS = ['TIENDA', 'BANCO', 'GERENCIA', 'AGENTE']

// ── Sub-components ─────────────────────────────────────────────────────────────────

function KpiCard({
  title, value, colorClass = 'text-blue-600 dark:text-blue-400', borderClass, highlight = false,
}: {
  title: string
  value: number | string | null | undefined
  colorClass?: string
  borderClass: string
  highlight?: boolean
}) {
  return (
    <div className={`group relative overflow-hidden premium-kpi rounded-kyro-lg p-3 border-l-4 ${borderClass} transition-all duration-200 hover:-translate-y-0.5`}>
      <div aria-hidden className={`absolute inset-x-0 top-0 h-px ${highlight ? 'bg-gradient-to-r from-emerald-400 via-emerald-300/40 to-transparent' : 'bg-gradient-to-r from-indigo-500/70 via-amber-400/35 to-transparent'}`} />
      <div aria-hidden className="absolute -right-8 -top-8 h-20 w-20 rounded-full bg-indigo-500/[0.05] blur-2xl dark:bg-indigo-500/[0.10]" />
      <p className="relative text-[0.68rem] font-semibold uppercase leading-tight tracking-[0.08em] text-gray-500 dark:text-zinc-400">{title}</p>
      <p className={`relative mt-2 text-xl font-bold tracking-tight ${colorClass}`}>
        {value === null ? <span className="text-gray-300 animate-pulse">···</span> : fmt(value)}
      </p>
      {highlight && <div className="relative mt-2 h-0.5 w-8 rounded bg-emerald-400 shadow-[0_0_10px_rgba(52,211,153,0.55)]" />}
    </div>
  )
}

function KpiCardDiferencia({ value }: { value: number | string | null | undefined }) {
  const num = Number(value ?? 0)
  const colorClass = num === 0
    ? 'text-gray-600 dark:text-zinc-400'
    : num < 0
    ? 'text-red-600 dark:text-red-400'
    : 'text-yellow-600 dark:text-yellow-400'
  const borderClass = num === 0 ? 'border-l-kpi-neutral' : num < 0 ? 'border-l-kyro-danger' : 'border-l-kyro-warning'
  const Icon = num < 0 ? TrendingDown : TrendingUp

  return (
    <div className={`group relative overflow-hidden premium-kpi rounded-kyro-lg p-3 border-l-4 ${borderClass} transition-all duration-200 hover:-translate-y-0.5`}>
      <div aria-hidden className={`absolute inset-x-0 top-0 h-px ${num < 0 ? 'bg-gradient-to-r from-red-500/80 to-transparent' : 'bg-gradient-to-r from-amber-400/80 to-transparent'}`} />
      <p className="text-[0.68rem] font-semibold uppercase leading-tight tracking-[0.08em] text-gray-500 dark:text-zinc-400">Diferencia Física</p>
      <div className="mt-2 flex items-center gap-2">
        {value !== null && <Icon size={18} className={colorClass} />}
        <p className={`text-xl font-bold tracking-tight ${colorClass}`}>
          {value === null ? <span className="text-gray-300 animate-pulse">···</span> : (num > 0 ? '+' : '') + fmt(num)}
        </p>
      </div>
    </div>
  )
}

function EstadoBadge({ estado, estadoEdicion }: { estado: string; estadoEdicion?: string }) {
  if (estadoEdicion === 'SOLICITADO') {
    return <span className="inline-block px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300 font-medium">Edición solicitada</span>
  }
  if (estadoEdicion === 'APROBADO') {
    return <span className="inline-block px-2 py-0.5 text-xs rounded-full font-medium border bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-900/40 dark:text-indigo-300 dark:border-indigo-700">Edición aprobada</span>
  }
  const map: Record<string, string> = {
    borrador: 'bg-gray-100 text-gray-600 dark:bg-zinc-800 dark:text-zinc-300',
    enviado:  'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    editado:  'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    aprobado: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
  }
  return (
    <span className={`inline-block px-2 py-0.5 text-xs rounded-full font-medium ${map[estado] ?? 'bg-gray-100 text-gray-500 dark:bg-zinc-800 dark:text-zinc-400'}`}>
      {estado}
    </span>
  )
}

function ModalEditarDestino({
  reporteId, current, onClose,
}: {
  reporteId: number; current: string; onClose: () => void
}) {
  const [destino, setDestino] = useState(current ?? 'TIENDA')
  const qc = useQueryClient()

  const { mutate, isPending, error } = useMutation({
    mutationFn: () => reportesApi.cambiarDestino(reporteId, destino),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['dashboard-kpis'] }); onClose() },
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/65 backdrop-blur-sm" onClick={onClose} />
      <div className="relative w-full max-w-xs space-y-4 overflow-hidden rounded-2xl border border-white/10 bg-white/95 p-6 shadow-2xl backdrop-blur-2xl dark:bg-zinc-900/95">
        <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500 via-amber-400/60 to-transparent" />
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-gray-900 dark:text-zinc-100 text-sm">Modificar Destino Efectivo</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600"><X size={16} /></button>
        </div>
        <p className="text-xs text-gray-500 dark:text-zinc-400">
          Reporte <span className="font-mono font-semibold">#{String(reporteId).padStart(4, '0')}</span>
        </p>
        <select
          value={destino}
          onChange={(e) => setDestino(e.target.value)}
          className="w-full border border-gray-300 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-100 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          {DESTINOS.map((d) => <option key={d} value={d}>{d}</option>)}
        </select>
        {error && (
          <p className="text-xs text-red-600">{apiErrorData(error).error ?? 'Error al actualizar'}</p>
        )}
        <div className="flex gap-2 justify-end">
          <Button variant="outline" onClick={onClose}>Cancelar</Button>
          <Button disabled={isPending || destino === (current ?? 'TIENDA')} onClick={() => mutate()}>
            {isPending ? 'Guardando...' : 'Guardar'}
          </Button>
        </div>
      </div>
    </div>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────────────────────────────

export function DashboardPage() {
  const { usuario } = useAuth()
  const todayStr = new Date().toISOString().slice(0, 10)

  const [rawFilters, setRawFilters] = useState({ fecha_desde: todayStr, fecha_hasta: todayStr, tienda: '' })
  const [appliedFilters, setAppliedFilters] = useState({ fecha_desde: todayStr, fecha_hasta: todayStr })
  const [showAnomalias, setShowAnomalias] = useState(false)
  const [editingDestino, setEditingDestino] = useState<{ id: number; current: string } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['dashboard-kpis', appliedFilters],
    queryFn: () => dashboardApi.kpis(appliedFilters),
  })

  const { data: anomaliasData } = useQuery({
    queryKey: ['dashboard-anomalias'],
    queryFn: () => dashboardApi.anomalias(),
    staleTime: 60_000,
    enabled: usuario?.rol === 'admin',
  })

  const totales  = data?.totales
  const reportes = data?.reportes ?? []
  const anomaliasCount = anomaliasData?.count ?? 0
  const anomaliasList  = anomaliasData?.anomalias ?? []

  function applyFilters() {
    setAppliedFilters({
      fecha_desde: rawFilters.fecha_desde,
      fecha_hasta: rawFilters.fecha_hasta,
      ...(rawFilters.tienda ? { tienda: rawFilters.tienda } : {}),
    } as typeof appliedFilters)
  }

  function resetToToday() {
    const reset = { fecha_desde: todayStr, fecha_hasta: todayStr, tienda: '' }
    setRawFilters(reset)
    setAppliedFilters({ fecha_desde: todayStr, fecha_hasta: todayStr })
  }

  const tableHeaders = ['ID', 'Fecha', 'Agente / Tienda', 'Total', 'F. Entregado', 'Diferencia', 'Destino', 'Estado', 'Acciones']
  const rightAligned = new Set(['Total', 'F. Entregado', 'Diferencia'])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Dashboard Gerencial"
        description="Visión consolidada de caja, canales digitales y reportes operativos."
        actions={usuario?.rol === 'admin' ? (
          <button
            onClick={() => setShowAnomalias(true)}
            className="relative flex h-9 items-center gap-2 rounded-lg border border-gray-200/80 bg-white/80 px-3 text-xs font-semibold text-gray-600 shadow-sm backdrop-blur-xl transition-all hover:border-red-300 hover:text-red-600 dark:border-white/10 dark:bg-zinc-900/65 dark:text-zinc-300"
            title="Reportes con anomalías"
          >
            <Bell size={18} />
            <span>Anomalías</span>
            {anomaliasCount > 0 && (
              <span className="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-0.5">
                {anomaliasCount > 99 ? '99+' : anomaliasCount}
              </span>
            )}
          </button>
        ) : undefined}
      />

      <ListToolbar description="Define el período operativo y, para administradores, limita la lectura a una tienda.">
        <div>
          <label className="block text-xs text-gray-500 dark:text-zinc-400 mb-1">Desde</label>
          <input
            type="date"
            value={rawFilters.fecha_desde}
            onChange={(e) => setRawFilters((f) => ({ ...f, fecha_desde: e.target.value }))}
            className="h-9 w-40 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
          />
        </div>
        <div>
          <label className="block text-xs text-gray-500 dark:text-zinc-400 mb-1">Hasta</label>
          <input
            type="date"
            value={rawFilters.fecha_hasta}
            onChange={(e) => setRawFilters((f) => ({ ...f, fecha_hasta: e.target.value }))}
            className="h-9 w-40 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
          />
        </div>
        {usuario?.rol === 'admin' && (
          <div>
            <label className="block text-xs text-gray-500 dark:text-zinc-400 mb-1">Tienda (código)</label>
            <input
              type="text"
              placeholder="Todas"
              value={rawFilters.tienda}
              onChange={(e) => setRawFilters((f) => ({ ...f, tienda: e.target.value }))}
              className="h-9 w-32 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
            />
          </div>
        )}
        <Button onClick={applyFilters}>Filtrar</Button>
        <Button variant="outline" onClick={resetToToday}>Hoy</Button>
      </ListToolbar>

      {/* KPIs principales */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard title="Total General (Inc. Digital)" value={isLoading ? null : totales?.total_general}     colorClass="text-blue-700 dark:text-blue-400"    borderClass="border-l-kpi-total" />
        <KpiCard title="Físico Esperado (Sist.)"       value={isLoading ? null : totales?.fisico_esperado}   colorClass="text-green-700 dark:text-green-400"   borderClass="border-l-kpi-esperado" />
        <KpiCard title="Físico Declarado (Agente)"     value={isLoading ? null : totales?.fisico_declarado}  colorClass="text-indigo-700 dark:text-indigo-400"  borderClass="border-l-kpi-declarado" />
        <KpiCardDiferencia                              value={isLoading ? null : totales?.diferencia_fisica} />
      </div>

      {/* KPIs digitales */}
      <div className={`grid gap-4 ${usuario?.rol === 'admin' ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-3'}`}>
        <KpiCard title="Total Yape"          value={isLoading ? null : totales?.total_yape}          colorClass="text-purple-700 dark:text-purple-400" borderClass="border-l-kpi-yape" />
        <KpiCard title="Total Bipay"         value={isLoading ? null : totales?.total_bipay}         colorClass="text-orange-600 dark:text-orange-400" borderClass="border-l-kpi-bipay" />
        <KpiCard title="Total Transferencia" value={isLoading ? null : totales?.total_transferencia} colorClass="text-teal-600 dark:text-teal-400"     borderClass="border-l-kpi-transfer" />
        {usuario?.rol === 'admin' && (
          <KpiCard
            title="Ganancia Total del Período"
            value={isLoading ? null : data?.ganancia_total}
            colorClass="text-emerald-700 dark:text-emerald-400"
            borderClass="border-l-kpi-ganancia"
            highlight
          />
        )}
      </div>

      {/* Tabla últimos reportes */}
      <div className="relative overflow-hidden rounded-xl border border-gray-200/80 bg-white/80 shadow-[0_14px_35px_-24px_rgba(15,23,42,0.45)] backdrop-blur-xl dark:border-white/[0.08] dark:bg-zinc-900/65 dark:shadow-[0_20px_45px_-28px_rgba(0,0,0,0.95)]">
        <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-amber-400/70 via-indigo-500/35 to-transparent" />
        <div className="flex items-center justify-between border-b border-gray-200/80 p-4 dark:border-white/[0.07]">
          <h2 className="font-semibold text-gray-900 dark:text-zinc-100 text-sm">Últimos Reportes del Período</h2>
          <span className="text-xs text-gray-400">
            {isLoading ? '—' : `${totales?.total_reportes ?? 0} reportes`}
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 bg-gray-50/80 dark:border-white/[0.07] dark:bg-zinc-800/50">
                {tableHeaders.map((h) => (
                  <th
                    key={h}
                    className={`px-4 py-3 text-[0.68rem] font-semibold uppercase tracking-[0.06em] text-gray-500 dark:text-zinc-400
                      ${rightAligned.has(h) ? 'text-right' : 'text-left'}`}
                  >
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr><td colSpan={9} className="px-4 py-8 text-center text-gray-400 text-sm">Cargando datos...</td></tr>
              )}
              {!isLoading && reportes.length === 0 && (
                <tr><td colSpan={9} className="px-4 py-8 text-center text-gray-400 text-sm">Sin reportes en el período seleccionado</td></tr>
              )}
              {reportes.map((r) => {
                const dif = Number(r.diferencia)
                const isSolicitado = r.estado_edicion === 'SOLICITADO'
                const isNegative   = dif < 0
                const rowCls = isSolicitado
                  ? 'border-b border-l-4 border-l-yellow-400 border-yellow-200 bg-yellow-50/80 animate-pulse dark:border-l-yellow-400 dark:border-yellow-400/20 dark:bg-yellow-400/[0.06]'
                  : isNegative
                  ? 'border-b border-l-4 border-l-red-400 border-red-100 bg-red-50/50 dark:border-l-red-400 dark:border-red-400/20 dark:bg-red-400/[0.06]'
                  : 'border-b border-gray-100 transition-colors hover:bg-amber-50/40 dark:border-white/[0.05] dark:hover:bg-amber-400/[0.04]'

                const canEdit    = usuario?.rol === 'admin' || r.estado_edicion === 'APROBADO'
                const editPending = r.estado_edicion === 'SOLICITADO' && usuario?.rol !== 'admin'

                return (
                  <tr key={r.id} className={`group ${rowCls}`}>
                    <td className="px-4 py-3 font-mono text-gray-600 dark:text-zinc-400 text-xs">
                      #{String(r.id).padStart(4, '0')}
                    </td>
                    <td className="px-4 py-3 text-gray-700 dark:text-zinc-300">
                      {new Date(r.fecha + 'T00:00:00').toLocaleDateString('es-PE')}
                    </td>
                    <td className="px-4 py-3">
                      <div className="font-medium text-gray-800 dark:text-zinc-200">{r.agente_nombre}</div>
                      <div className="text-xs text-gray-400 dark:text-zinc-500">{r.tienda_id}</div>
                    </td>
                    <td className="px-4 py-3 text-right font-mono text-gray-800 dark:text-zinc-200">{fmt(r.total_calculado)}</td>
                    <td className="px-4 py-3 text-right font-mono text-gray-800 dark:text-zinc-200">{fmt(r.efectivo_entregado)}</td>
                    <td className="px-4 py-3 text-right">
                      <span className={`inline-block px-2 py-0.5 rounded-md text-xs font-bold ${diferenciaClass(dif)}`}>
                        {dif > 0 ? '+' : ''}{fmt(dif)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1.5">
                        <span className={`inline-block px-2 py-0.5 text-xs rounded-full font-semibold ${destinoBadgeClass(r.destino_efectivo ?? 'TIENDA')}`}>
                          {r.destino_efectivo ?? 'TIENDA'}
                        </span>
                        {usuario?.rol === 'admin' && (
                          <button
                            onClick={() => setEditingDestino({ id: r.id, current: r.destino_efectivo ?? 'TIENDA' })}
                            className="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                            title="Modificar destino"
                          >
                            <Pencil size={11} />
                          </button>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <EstadoBadge estado={r.estado} estadoEdicion={r.estado_edicion} />
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1">
                        <Link
                          to={`/reportes/${r.id}`}
                          className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-gray-400 transition-all hover:border-cyan-500/40 hover:bg-cyan-500/10 hover:text-cyan-600 dark:text-zinc-500 dark:hover:text-cyan-400"
                          title="Ver detalle"
                        >
                          <Eye size={14} />
                        </Link>
                        {canEdit ? (
                          <Link
                            to={`/reportes/${r.id}/editar`}
                            className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-gray-400 transition-all hover:border-amber-500/40 hover:bg-amber-500/10 hover:text-amber-600 dark:text-zinc-500 dark:hover:text-amber-400"
                            title="Editar reporte"
                          >
                            <Pencil size={14} />
                          </Link>
                        ) : editPending ? (
                          <span
                            className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-yellow-300/40 text-yellow-400 opacity-60 cursor-default dark:border-yellow-500/20"
                            title="Solicitud de edición pendiente de aprobación"
                          >
                            <Pencil size={14} />
                          </span>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-center border-t border-gray-200/80 p-3 dark:border-white/[0.07]">
          <Link
            to={usuario?.rol === 'admin' ? '/historial' : '/mi-historial'}
            className="inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-xs font-semibold text-cyan-600 transition-all hover:bg-cyan-500/10 dark:text-cyan-400 dark:hover:bg-cyan-500/[0.08]"
          >
            Ver Historial de Movimientos →
          </Link>
        </div>
      </div>

      {/* Offcanvas Anomalías */}
      {showAnomalias && (
        <div className="fixed inset-0 z-50 flex justify-end">
          <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={() => setShowAnomalias(false)} />
          <div className="relative flex w-full max-w-sm flex-col border-l border-white/10 bg-white/95 shadow-2xl backdrop-blur-2xl dark:bg-zinc-950/95">
            <div className="flex items-center justify-between border-b border-red-200/70 bg-red-50/80 p-4 dark:border-red-400/15 dark:bg-red-500/[0.08]">
              <h3 className="font-semibold text-gray-900 dark:text-zinc-100 flex items-center gap-2 text-sm">
                <AlertTriangle size={16} className="text-red-500" />
                Anomalías — últimos 30 días
                {anomaliasCount > 0 && (
                  <span className="bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full font-bold">
                    {anomaliasCount}
                  </span>
                )}
              </h3>
              <button onClick={() => setShowAnomalias(false)} className="text-gray-400 hover:text-gray-600 dark:hover:text-zinc-200 text-xl leading-none">×</button>
            </div>
            <div className="flex-1 overflow-y-auto p-4 space-y-3">
              {anomaliasList.length === 0 ? (
                <p className="text-center text-gray-400 text-sm py-8">Sin anomalías recientes</p>
              ) : (
                anomaliasList.map((a) => {
                  const dif = Number(a.diferencia)
                  return (
                    <div key={a.id} className="space-y-1 rounded-xl border border-gray-200/80 bg-white/60 p-3 shadow-sm transition-colors hover:border-red-300 dark:border-white/[0.08] dark:bg-white/[0.03] dark:hover:border-red-400/30">
                      <div className="flex items-center justify-between">
                        <span className="font-mono text-sm text-gray-700 dark:text-zinc-300 font-semibold">
                          #{String(a.id).padStart(4, '0')}
                        </span>
                        <span className={`text-xs px-2 py-0.5 rounded-full font-bold ${diferenciaClass(dif)}`}>
                          {dif > 0 ? '+' : ''}{fmt(dif)}
                        </span>
                      </div>
                      <div className="text-xs text-gray-500 dark:text-zinc-400 space-x-1">
                        <span>{new Date(a.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</span>
                        <span>·</span>
                        <span>{a.tienda_id}</span>
                        <span>·</span>
                        <span className="capitalize">{a.estado}</span>
                      </div>
                    </div>
                  )
                })
              )}
            </div>
          </div>
        </div>
      )}

      {/* Modal destino efectivo */}
      {editingDestino && (
        <ModalEditarDestino
          reporteId={editingDestino.id}
          current={editingDestino.current}
          onClose={() => setEditingDestino(null)}
        />
      )}
    </div>
  )
}
