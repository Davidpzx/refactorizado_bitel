import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Warning as AlertTriangle, Bell, ChartLineDown as TrendingDown, ChartLineUp as TrendingUp, PencilSimple as Pencil, X, Eye, CheckCircle, FileXls as FileSpreadsheet, SquaresFour as LayoutDashboard, Storefront as Store, Users, FilePlus as FilePlus2, Wallet, FileText, Lightning as Zap, CreditCard, Bank as Landmark, ArrowCounterClockwise as RotateCcw, MagnifyingGlass as Search } from '@phosphor-icons/react'
import { dashboardApi } from '../services/dashboard.api'
import { reportesApi } from '../services/reportes.api'
import { useAuth } from '../hooks/useAuth'
import { Button } from '../components/ui/button'
import { ActionIconButton, TableActions } from '../components/ui/ActionIconButton'
import { PageHeader } from '../components/PageHeader'
import { ListToolbar } from '../components/ListToolbar'
import { StatCard } from '../components/ui/StatCard'
import { MoneyGroup } from '../components/ui/MoneyGroup'
import { ProfitBanner } from '../components/ui/ProfitBanner'
import { useConfirmDialog } from '../components/ui/confirm-dialog'
import { apiErrorData } from '../lib/httpError'
import { api } from '../services/api'
import { Select } from '../components/ui/select'
import { useTiendasSelect } from '../hooks/useTiendasSelect'

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

const DESTINOS = [
  { value: 'BANCO',    label: 'Depositado en Banco' },
  { value: 'GERENCIA', label: 'Entregado a Supervisor/Gerencia' },
  { value: 'EN_CAJA',  label: 'Guardado en Caja Fuerte Central' },
  { value: 'AGENTE',   label: 'Usado para Pagos/Gastos (Agente)' },
  { value: 'TIENDA',   label: '⚠️ Revertir: Sigue en Tienda (no entregado)' },
]

// ── Sub-components ─────────────────────────────────────────────────────────────────
// Colores de acento de KPI (paridad legacy `panel_gerencia.php`).
const KPI_ACCENT = {
  total:      '#0d6efd',
  esperado:   '#0dcaf0',
  declarado:  '#198754',
  neutral:    '#6c757d',
  yape:       '#a855f7',
  bipay:      '#3b82f6',
  transfer:   '#14b8a6',
  ganancia:   '#10b981',
  danger:     '#ef4444',
  warning:    '#f59e0b',
} as const

function KpiCardDiferencia({ value }: { value: number | string | null | undefined }) {
  const num = Number(value ?? 0)
  const colorClass = num === 0
    ? 'text-gray-600 dark:text-zinc-400'
    : num < 0
    ? 'text-red-600 dark:text-red-400'
    : 'text-yellow-600 dark:text-yellow-400'
  const accent = num === 0 ? KPI_ACCENT.neutral : num < 0 ? KPI_ACCENT.danger : KPI_ACCENT.warning
  const Icon = num < 0 ? TrendingDown : TrendingUp

  return (
    <StatCard
      title="Diferencia Física"
      accent={accent}
      align="top"
      valueColorClass={colorClass}
      icon={value !== null ? <Icon size={18} /> : undefined}
      value={value === null ? null : (num > 0 ? '+' : '') + fmt(num)}
    />
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
  const [observacion, setObservacion] = useState('')
  const qc = useQueryClient()

  const { mutate, isPending, error } = useMutation({
    mutationFn: () => reportesApi.cambiarDestino(reporteId, destino, observacion || undefined),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['dashboard-kpis'] }); onClose() },
  })

  const esRevertir = destino === 'TIENDA'

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/65 backdrop-blur-sm" onClick={onClose} />
      <div className="relative w-full max-w-sm space-y-4 overflow-hidden rounded-2xl border border-white/10 bg-white/95 p-6 shadow-2xl backdrop-blur-2xl dark:bg-zinc-900/95">

        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-gray-900 dark:text-zinc-100 text-sm">Estado del Efectivo</h3>
          <button type="button" aria-label="Cerrar" onClick={onClose} className="text-gray-400 hover:text-gray-600"><X size={16} /></button>
        </div>
        <p className="text-xs text-gray-500 dark:text-zinc-400">
          Reporte <span className="font-mono font-semibold">#{String(reporteId).padStart(4, '0')}</span>
        </p>
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-zinc-400">Nuevo Destino / Acción</label>
          <select
            value={destino}
            onChange={(e) => setDestino(e.target.value)}
            className="w-full border border-gray-300 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-100 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            {DESTINOS.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
          </select>
          {esRevertir && (
            <p className="mt-1 text-[0.7rem] text-amber-600 dark:text-amber-400">
              Marca el efectivo como aún no entregado; vuelve a aparecer pendiente en tienda.
            </p>
          )}
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-zinc-400">Observación (opcional)</label>
          <textarea
            value={observacion}
            onChange={(e) => setObservacion(e.target.value)}
            rows={2}
            placeholder="Ej: Recibido conforme por el gerente..."
            className="w-full resize-none border border-gray-300 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-100 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        {error && (
          <p className="text-xs text-red-600">{apiErrorData(error).error ?? 'Error al actualizar'}</p>
        )}
        <div className="flex gap-2 justify-end">
          <Button variant="outline" onClick={onClose}>Cancelar</Button>
          <Button variant="gold" disabled={isPending || destino === (current ?? 'TIENDA')} onClick={() => mutate()}>
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
  const { tiendas } = useTiendasSelect()
  const navigate = useNavigate()
  const todayStr = new Date().toISOString().slice(0, 10)

  const [rawFilters, setRawFilters] = useState({ fecha_desde: todayStr, fecha_hasta: todayStr, tienda: '' })
  const [appliedFilters, setAppliedFilters] = useState<{ fecha_desde: string; fecha_hasta: string; tienda?: string }>({ fecha_desde: todayStr, fecha_hasta: todayStr })
  const [showAnomalias, setShowAnomalias] = useState(false)
  const [editingDestino, setEditingDestino] = useState<{ id: number; current: string } | null>(null)
  const qc = useQueryClient()
  const confirmDialog = useConfirmDialog()

  const aprobarEdicion = useMutation({
    mutationFn: (id: number) => api.post(`/v1/reportes/${id}/aprobar-edicion`).then(r => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['dashboard-kpis'] }),
  })

  const denegarEdicion = useMutation({
    mutationFn: ({ id, motivo }: { id: number; motivo?: string }) => reportesApi.denegarEdicion(id, motivo),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['dashboard-kpis'] }),
  })

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
    })
  }

  function resetToToday() {
    const reset = { fecha_desde: todayStr, fecha_hasta: todayStr, tienda: '' }
    setRawFilters(reset)
    setAppliedFilters({ fecha_desde: todayStr, fecha_hasta: todayStr })
  }

  function exportarExcel() {
    const params = new URLSearchParams()
    if (appliedFilters.fecha_desde) params.set('fecha_desde', appliedFilters.fecha_desde)
    if (appliedFilters.fecha_hasta) params.set('fecha_hasta', appliedFilters.fecha_hasta)
    if (appliedFilters.tienda) params.set('tienda', appliedFilters.tienda)
    const token = localStorage.getItem('auth_token')
    const base = (api.defaults.baseURL ?? '').replace(/\/$/, '')
    const url = `${base}/v1/dashboard/exportar?${params.toString()}`
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then(r => r.blob())
      .then(blob => {
        const burl = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = burl
        link.download = `Reporte_Ventas_Desglosado_${new Date().toISOString().slice(0, 10)}.xlsx`
        link.click()
        URL.revokeObjectURL(burl)
      })
  }

  const isAdmin = usuario?.rol === 'admin'
  const tableHeaders = isAdmin
    ? ['ID', 'Fecha', 'Tienda / Agente', 'Venta Total', 'Ganancia', 'Físico Entregado', 'Diferencia', 'Destino Efectivo', 'Estado', 'Acción']
    : ['ID', 'Fecha', 'Tienda / Agente', 'Venta Total', 'Físico Entregado', 'Diferencia', 'Destino Efectivo', 'Estado', 'Acción']
  const rightAligned = new Set(['Venta Total', 'Ganancia', 'Físico Entregado', 'Diferencia'])

  return (
    <div className="space-y-6">
      <PageHeader
        title="Dashboard Gerencial"
        description="Visión consolidada de caja, canales digitales y reportes operativos."
        Icon={LayoutDashboard}
        actions={usuario?.rol === 'admin' ? (
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => setShowAnomalias(true)}
              className="relative flex h-9 items-center gap-2 rounded-lg border border-red-200/70 bg-white/80 px-3 text-xs font-semibold text-red-600/90 shadow-sm backdrop-blur-xl transition-all hover:border-red-300 hover:text-red-600 dark:border-red-500/20 dark:bg-zinc-900/65 dark:text-red-400/90"
              title="Reportes con anomalías"
            >
              <Bell size={18} weight={anomaliasCount > 0 ? 'fill' : 'regular'} />
              <span>Anomalías</span>
              {anomaliasCount > 0 && (
                <span className="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-0.5">
                  {anomaliasCount > 99 ? '99+' : anomaliasCount}
                </span>
              )}
            </button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => navigate('/tiendas')}
              className="gap-1.5 border-kyro-gold/40 text-kyro-gold hover:bg-kyro-gold/10 dark:border-kyro-gold/30"
            >
              <Store size={14} /> Tiendas
            </Button>
            <Button variant="outline" size="sm" onClick={() => navigate('/usuarios')} className="gap-1.5">
              <Users size={14} /> Usuarios
            </Button>
            <Button size="sm" onClick={() => navigate('/reportes/nuevo')} className="gap-1.5">
              <FilePlus2 size={14} /> Registrar Cuadre
            </Button>
          </div>
        ) : undefined}
      />

      <ListToolbar description="Define el período operativo y, para administradores, limita la lectura a una tienda.">
        <div>
          <label className="block text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-zinc-400 mb-1">Desde</label>
          <input
            type="date"
            value={rawFilters.fecha_desde}
            onChange={(e) => setRawFilters((f) => ({ ...f, fecha_desde: e.target.value }))}
            className="h-9 w-40 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
          />
        </div>
        <div>
          <label className="block text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-zinc-400 mb-1">Hasta</label>
          <input
            type="date"
            value={rawFilters.fecha_hasta}
            onChange={(e) => setRawFilters((f) => ({ ...f, fecha_hasta: e.target.value }))}
            className="h-9 w-40 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
          />
        </div>
        {usuario?.rol === 'admin' && (
          <div>
            <label className="block text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-zinc-400 mb-1">Tienda</label>
            <Select
              value={rawFilters.tienda}
              onChange={(e) => setRawFilters((f) => ({ ...f, tienda: e.target.value }))}
              className="h-9 w-44"
            >
              <option value="">Todas las tiendas</option>
              {tiendas.map(t => <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>)}
            </Select>
          </div>
        )}
        <Button variant="gold" onClick={applyFilters} className="gap-1.5">
          <Search size={14} /> Filtrar
        </Button>
        <Button
          variant="outline"
          size="icon"
          onClick={resetToToday}
          title="Volver a hoy"
          aria-label="Volver a hoy"
        >
          <RotateCcw size={15} />
        </Button>
        {usuario?.rol === 'admin' && (
          <Button variant="glassSuccess" onClick={exportarExcel} className="gap-1.5">
            <FileSpreadsheet size={14} /> Exportar
          </Button>
        )}
      </ListToolbar>

      {/* KPIs principales */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard title="Total General (Inc. Digital)" value={isLoading ? null : totales?.total_general}    formatMoney align="top" icon={<Wallet size={18} />}      accent={KPI_ACCENT.total}     valueColorClass="text-blue-700 dark:text-blue-400" />
        <StatCard title="Físico Esperado (Sist.)"       value={isLoading ? null : totales?.fisico_esperado}  formatMoney align="top" icon={<CheckCircle size={18} />}  accent={KPI_ACCENT.esperado}  valueColorClass="text-green-700 dark:text-green-400" />
        <StatCard title="Físico Declarado (Agente)"     value={isLoading ? null : totales?.fisico_declarado} formatMoney align="top" icon={<FileText size={18} />}     accent={KPI_ACCENT.declarado} valueColorClass="text-indigo-700 dark:text-indigo-400" />
        <KpiCardDiferencia                               value={isLoading ? null : totales?.diferencia_fisica} />
      </div>

      {/* Dinero digital del período */}
      <MoneyGroup
        items={[
          {
            key: 'yape',
            title: 'Total Yape',
            value: isLoading ? null : totales?.total_yape,
            formatMoney: true,
            accent: KPI_ACCENT.yape,
            valueColorClass: 'text-purple-700 dark:text-purple-400',
            icon: <Zap size={18} />,
          },
          {
            key: 'bipay',
            title: 'Total Bipay',
            value: isLoading ? null : totales?.total_bipay,
            formatMoney: true,
            accent: KPI_ACCENT.bipay,
            valueColorClass: 'text-blue-700 dark:text-blue-400',
            icon: <CreditCard size={18} />,
          },
          {
            key: 'transferencia',
            title: 'Total Transferencia',
            value: isLoading ? null : totales?.total_transferencia,
            formatMoney: true,
            accent: KPI_ACCENT.transfer,
            valueColorClass: 'text-teal-700 dark:text-teal-400',
            icon: <Landmark size={18} />,
          },
        ]}
      />

      {/* Ganancia total del período */}
      {usuario?.rol === 'admin' && (
        <ProfitBanner
          title="Ganancia Total del Período"
          subtitle={`Suma de ganancias y comisiones registradas entre el ${new Date(appliedFilters.fecha_desde + 'T00:00:00').toLocaleDateString('es-PE')} y el ${new Date(appliedFilters.fecha_hasta + 'T00:00:00').toLocaleDateString('es-PE')}.`}
          value={isLoading ? '···' : fmt(data?.ganancia_total)}
          accent={KPI_ACCENT.ganancia}
        />
      )}

      {/* Tabla últimos reportes */}
      <div className="relative overflow-hidden rounded-xl border border-gray-200/80 bg-white/80 shadow-[0_14px_35px_-24px_rgba(15,23,42,0.45)] backdrop-blur-xl dark:border-white/[0.08] dark:bg-zinc-900/65 dark:shadow-[0_20px_45px_-28px_rgba(0,0,0,0.95)]">

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
                <tr><td colSpan={tableHeaders.length} className="px-4 py-8 text-center text-gray-400 text-sm">Cargando datos...</td></tr>
              )}
              {!isLoading && reportes.length === 0 && (
                <tr>
                  <td colSpan={tableHeaders.length} className="px-4 py-10 text-center text-gray-400 text-sm">
                    <span className="inline-flex flex-col items-center gap-2">
                      <span className="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/[0.04] dark:text-zinc-500">
                        <Search size={16} />
                      </span>
                      No se encontraron reportes con estos filtros.
                    </span>
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
                      <div className="font-medium text-gray-800 dark:text-zinc-200">{r.tienda_id}</div>
                      <div className="text-xs text-gray-400 dark:text-zinc-500">{r.agente_nombre}</div>
                    </td>
                    <td className="px-4 py-3 text-right font-mono text-gray-800 dark:text-zinc-200">{fmt(r.total_calculado)}</td>
                    {isAdmin && (
                      <td className="px-4 py-3 text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400">{fmt(r.ganancia)}</td>
                    )}
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
                          <ActionIconButton
                            tone="edit"
                            label="Modificar destino"
                            icon={<Pencil size={15} />}
                            onClick={() => setEditingDestino({ id: r.id, current: r.destino_efectivo ?? 'TIENDA' })}
                          />
                        )}
                      </div>
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
                            className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-yellow-300/40 text-yellow-400 opacity-60 cursor-default dark:border-yellow-500/20"
                            title="Solicitud de edición pendiente de aprobación"
                          >
                            <Pencil size={14} />
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
                              <X size={12} /> Denegar
                            </Button>
                          </>
                        )}
                      </TableActions>
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
              <button type="button" aria-label="Cerrar anomalias" onClick={() => setShowAnomalias(false)} className="text-gray-400 hover:text-gray-600 dark:hover:text-zinc-200 text-xl leading-none">×</button>
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
