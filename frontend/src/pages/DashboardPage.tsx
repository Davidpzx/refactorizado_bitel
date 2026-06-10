import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Bell, TrendingDown, TrendingUp, Pencil, X } from 'lucide-react'
import { dashboardApi } from '../services/dashboard.api'
import { reportesApi } from '../services/reportes.api'
import { useAuth } from '../hooks/useAuth'
import { Button } from '../components/ui/button'

// ── Helpers ────────────────────────────────────────────────────────────────

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

function diferenciaClass(val: number) {
  if (val === 0) return 'bg-gray-100 text-gray-600'
  return val < 0 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'
}

const DESTINOS = ['TIENDA', 'BANCO', 'GERENCIA', 'AGENTE']

// ── Sub-components ─────────────────────────────────────────────────────────

function KpiCard({
  title, value, colorClass = 'text-blue-600', highlight = false,
}: {
  title: string
  value: number | string | null | undefined
  colorClass?: string
  highlight?: boolean
}) {
  return (
    <div className={`bg-white rounded-xl border ${highlight ? 'border-emerald-300 shadow-md' : 'border-gray-200'} p-4 space-y-2`}>
      <p className="text-xs font-medium text-gray-500 leading-tight">{title}</p>
      <p className={`text-xl font-bold ${colorClass}`}>
        {value === null ? <span className="text-gray-300 animate-pulse">···</span> : fmt(value)}
      </p>
      {highlight && <div className="w-6 h-0.5 bg-emerald-400 rounded" />}
    </div>
  )
}

function KpiCardDiferencia({ value }: { value: number | string | null | undefined }) {
  const num = Number(value ?? 0)
  const colorClass = num === 0 ? 'text-gray-600' : num < 0 ? 'text-red-600' : 'text-yellow-600'
  const bgClass    = num === 0 ? 'border-gray-200' : num < 0 ? 'border-red-300' : 'border-yellow-300'
  const Icon = num < 0 ? TrendingDown : TrendingUp

  return (
    <div className={`bg-white rounded-xl border ${bgClass} p-4 space-y-2`}>
      <p className="text-xs font-medium text-gray-500 leading-tight">Diferencia Física</p>
      <div className="flex items-center gap-2">
        {value !== null && <Icon size={18} className={colorClass} />}
        <p className={`text-xl font-bold ${colorClass}`}>
          {value === null ? <span className="text-gray-300 animate-pulse">···</span> : (num > 0 ? '+' : '') + fmt(num)}
        </p>
      </div>
    </div>
  )
}

function EstadoBadge({ estado, estadoEdicion }: { estado: string; estadoEdicion?: string }) {
  if (estadoEdicion === 'SOLICITADO') {
    return <span className="inline-block px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-700 font-medium">Edición solicitada</span>
  }
  if (estadoEdicion === 'APROBADO') {
    return <span className="inline-block px-2 py-0.5 text-xs rounded-full font-medium border bg-indigo-50 text-indigo-700 border-indigo-200">Edición aprobada</span>
  }
  const map: Record<string, string> = {
    borrador: 'bg-gray-100 text-gray-600',
    enviado:  'bg-blue-100 text-blue-700',
    editado:  'bg-orange-100 text-orange-700',
    aprobado: 'bg-green-100 text-green-700',
  }
  return (
    <span className={`inline-block px-2 py-0.5 text-xs rounded-full font-medium ${map[estado] ?? 'bg-gray-100 text-gray-500'}`}>
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
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />
      <div className="relative bg-white rounded-xl shadow-2xl w-full max-w-xs p-6 space-y-4">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-gray-900 text-sm">Modificar Destino Efectivo</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600"><X size={16} /></button>
        </div>
        <p className="text-xs text-gray-500">
          Reporte <span className="font-mono font-semibold">#{String(reporteId).padStart(4, '0')}</span>
        </p>
        <select
          value={destino}
          onChange={(e) => setDestino(e.target.value)}
          className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          {DESTINOS.map((d) => <option key={d} value={d}>{d}</option>)}
        </select>
        {error && (
          <p className="text-xs text-red-600">{(error as any)?.response?.data?.error ?? 'Error al actualizar'}</p>
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

// ── Page ───────────────────────────────────────────────────────────────────

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

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <h1 className="text-xl font-semibold text-gray-900">Dashboard Gerencial</h1>
        {usuario?.rol === 'admin' && (
          <button
            onClick={() => setShowAnomalias(true)}
            className="relative p-2 rounded-lg bg-white border border-gray-200 text-gray-600 hover:border-red-300 hover:text-red-600 transition-colors"
            title="Reportes con anomalías"
          >
            <Bell size={18} />
            {anomaliasCount > 0 && (
              <span className="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-0.5">
                {anomaliasCount > 99 ? '99+' : anomaliasCount}
              </span>
            )}
          </button>
        )}
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap items-end gap-3">
        <div>
          <label className="block text-xs text-gray-500 mb-1">Desde</label>
          <input
            type="date"
            value={rawFilters.fecha_desde}
            onChange={(e) => setRawFilters((f) => ({ ...f, fecha_desde: e.target.value }))}
            className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">Hasta</label>
          <input
            type="date"
            value={rawFilters.fecha_hasta}
            onChange={(e) => setRawFilters((f) => ({ ...f, fecha_hasta: e.target.value }))}
            className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        {usuario?.rol === 'admin' && (
          <div>
            <label className="block text-xs text-gray-500 mb-1">Tienda (código)</label>
            <input
              type="text"
              placeholder="Todas"
              value={rawFilters.tienda}
              onChange={(e) => setRawFilters((f) => ({ ...f, tienda: e.target.value }))}
              className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-28"
            />
          </div>
        )}
        <Button onClick={applyFilters}>Filtrar</Button>
        <Button variant="outline" onClick={resetToToday}>Hoy</Button>
      </div>

      {/* KPIs principales */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard title="Total General (Inc. Digital)" value={isLoading ? null : totales?.total_general}     colorClass="text-blue-700" />
        <KpiCard title="Físico Esperado (Sist.)"       value={isLoading ? null : totales?.fisico_esperado}   colorClass="text-green-700" />
        <KpiCard title="Físico Declarado (Agente)"     value={isLoading ? null : totales?.fisico_declarado}  colorClass="text-indigo-700" />
        <KpiCardDiferencia                              value={isLoading ? null : totales?.diferencia_fisica} />
      </div>

      {/* KPIs digitales */}
      <div className={`grid gap-4 ${usuario?.rol === 'admin' ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-3'}`}>
        <KpiCard title="Total Yape"          value={isLoading ? null : totales?.total_yape}          colorClass="text-purple-700" />
        <KpiCard title="Total Bipay"         value={isLoading ? null : totales?.total_bipay}         colorClass="text-orange-600" />
        <KpiCard title="Total Transferencia" value={isLoading ? null : totales?.total_transferencia} colorClass="text-teal-600" />
        {usuario?.rol === 'admin' && (
          <KpiCard
            title="Ganancia Total del Período"
            value={isLoading ? null : data?.ganancia_total}
            colorClass="text-emerald-700"
            highlight
          />
        )}
      </div>

      {/* Tabla últimos reportes */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div className="p-4 border-b border-gray-200 flex items-center justify-between">
          <h2 className="font-semibold text-gray-900 text-sm">Últimos Reportes del Período</h2>
          <span className="text-xs text-gray-400">
            {isLoading ? '—' : `${totales?.total_reportes ?? 0} reportes`}
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200">
                {['ID', 'Fecha', 'Agente / Tienda', 'Total', 'F. Entregado', 'Diferencia', 'Destino', 'Estado'].map((h) => (
                  <th
                    key={h}
                    className={`px-4 py-3 text-xs font-semibold text-gray-500
                      ${['Total', 'F. Entregado', 'Diferencia'].includes(h) ? 'text-right' : 'text-left'}`}
                  >
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr><td colSpan={8} className="px-4 py-8 text-center text-gray-400 text-sm">Cargando datos...</td></tr>
              )}
              {!isLoading && reportes.length === 0 && (
                <tr><td colSpan={8} className="px-4 py-8 text-center text-gray-400 text-sm">Sin reportes en el período seleccionado</td></tr>
              )}
              {reportes.map((r) => {
                const dif = Number(r.diferencia)
                const isSolicitado = r.estado_edicion === 'SOLICITADO'
                const rowCls = isSolicitado
                  ? 'border-b border-yellow-300 bg-yellow-50 animate-pulse'
                  : dif < 0
                  ? 'border-b border-red-100 bg-red-50/50'
                  : 'border-b border-gray-100 hover:bg-gray-50/60'

                return (
                  <tr key={r.id} className={`group ${rowCls}`}>
                    <td className="px-4 py-3 font-mono text-gray-600 text-xs">
                      #{String(r.id).padStart(4, '0')}
                    </td>
                    <td className="px-4 py-3 text-gray-700">
                      {new Date(r.fecha + 'T00:00:00').toLocaleDateString('es-PE')}
                    </td>
                    <td className="px-4 py-3">
                      <div className="font-medium text-gray-800">{r.agente_nombre}</div>
                      <div className="text-xs text-gray-400">{r.tienda_id}</div>
                    </td>
                    <td className="px-4 py-3 text-right font-mono text-gray-800">{fmt(r.total_calculado)}</td>
                    <td className="px-4 py-3 text-right font-mono text-gray-800">{fmt(r.efectivo_entregado)}</td>
                    <td className="px-4 py-3 text-right">
                      <span className={`inline-block px-2 py-0.5 rounded-md text-xs font-bold ${diferenciaClass(dif)}`}>
                        {dif > 0 ? '+' : ''}{fmt(dif)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1.5 text-xs text-gray-600">
                        <span>{r.destino_efectivo ?? 'TIENDA'}</span>
                        {usuario?.rol === 'admin' && (
                          <button
                            onClick={() => setEditingDestino({ id: r.id, current: r.destino_efectivo ?? 'TIENDA' })}
                            className="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-indigo-600 transition-all"
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
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>

      {/* Offcanvas Anomalías */}
      {showAnomalias && (
        <div className="fixed inset-0 z-50 flex justify-end">
          <div className="absolute inset-0 bg-black/40" onClick={() => setShowAnomalias(false)} />
          <div className="relative bg-white w-full max-w-sm shadow-2xl flex flex-col">
            <div className="p-4 border-b border-gray-200 flex items-center justify-between bg-red-50">
              <h3 className="font-semibold text-gray-900 flex items-center gap-2 text-sm">
                <AlertTriangle size={16} className="text-red-500" />
                Anomalías — últimos 30 días
                {anomaliasCount > 0 && (
                  <span className="bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full font-bold">
                    {anomaliasCount}
                  </span>
                )}
              </h3>
              <button onClick={() => setShowAnomalias(false)} className="text-gray-400 hover:text-gray-600 text-xl leading-none">×</button>
            </div>
            <div className="flex-1 overflow-y-auto p-4 space-y-3">
              {anomaliasList.length === 0 ? (
                <p className="text-center text-gray-400 text-sm py-8">Sin anomalías recientes</p>
              ) : (
                anomaliasList.map((a) => {
                  const dif = Number(a.diferencia)
                  return (
                    <div key={a.id} className="rounded-lg border border-gray-200 p-3 space-y-1 hover:border-gray-300">
                      <div className="flex items-center justify-between">
                        <span className="font-mono text-sm text-gray-700 font-semibold">
                          #{String(a.id).padStart(4, '0')}
                        </span>
                        <span className={`text-xs px-2 py-0.5 rounded-full font-bold ${diferenciaClass(dif)}`}>
                          {dif > 0 ? '+' : ''}{fmt(dif)}
                        </span>
                      </div>
                      <div className="text-xs text-gray-500 space-x-1">
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
