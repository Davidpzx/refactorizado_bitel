import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  BarChart, Bar, LineChart, Line,
  XAxis, YAxis, Tooltip, Legend, CartesianGrid,
  ResponsiveContainer, Cell,
} from 'recharts'
import { api } from '../../services/api'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { PageTabs } from '../../components/ui/PageTabs'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'
import { StatCard } from '../../components/ui/StatCard'
import { FileSpreadsheet, AlertTriangle, TrendingUp, Phone, RefreshCw, Zap, Star, Signal } from 'lucide-react'

// ── Tipos ─────────────────────────────────────────────────────────────────────

interface ResumenTotales {
  total_activaciones: number
  portabilidades: number
  altas_nuevas: number
  renovaciones: number
  upgrades: number
  paquetes: number
  remates: number
  comision_activa: string
  comision_anulada: string
  monto_total: string
}

interface PostpagoResumen {
  totales: ResumenTotales
  por_tipo: { tipo_alta: string; total: number }[]
  top_planes: { plan: string; total: number }[]
  tendencia: { fecha: string; activaciones: number; portabilidades: number; remates: number }[]
}

interface PostpagoVenta {
  id: number
  fecha: string
  tienda_id: string
  cliente_nombre: string | null
  dni_ruc: string | null
  cliente_telefono: string | null
  agente_nombres: string | null
  plan_nombre_snap: string
  tipo_alta: string
  cantidad: number
  cobrado_unitario: string
  es_migracion: 0 | 1
  es_upgrade: 0 | 1
  es_esim: 0 | 1
  comision_generada: string
  comision_estado: string
  es_remate: 0 | 1
  monto_total: string
}

interface PaginatedVentas {
  data: PostpagoVenta[]
  total: number
  current_page: number
  last_page: number
}

// ── Constantes visuales ───────────────────────────────────────────────────────

const TIPO_COLOR: Record<string, string> = {
  PORTABILIDAD: 'var(--color-kpi-total)',
  ALTA_NUEVA:   'var(--color-kyro-success)',
  RENOVACION:   'var(--color-kyro-indigo)',
  UPGRADE:      'var(--color-kyro-gold)',
  PAQUETE:      'var(--color-kyro-warning)',
}

const TIPO_LABEL: Record<string, string> = {
  PORTABILIDAD: 'Portabilidad',
  ALTA_NUEVA:   'Alta Nueva',
  RENOVACION:   'Renovación',
  UPGRADE:      'Upgrade',
  PAQUETE:      'Paquete',
}

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN', minimumFractionDigits: 2 })

function tipoAltaBadge(tipo: string) {
  const map: Record<string, 'default' | 'success' | 'warning' | 'destructive' | 'outline'> = {
    PORTABILIDAD: 'default',
    ALTA_NUEVA:   'success',
    RENOVACION:   'outline',
    UPGRADE:      'warning',
    PAQUETE:      'warning',
  }
  return (
    <Badge variant={map[tipo] ?? 'outline'}>
      {TIPO_LABEL[tipo] ?? tipo}
    </Badge>
  )
}

// ── Tabla de activaciones ─────────────────────────────────────────────────────

function TablaActivaciones({
  params,
  esChurn = false,
}: {
  params: Record<string, string>
  esChurn?: boolean
}) {
  const [page, setPage] = useState(1)

  const query = {
    ...params,
    ...(esChurn ? { es_remate: '1' } : {}),
    page: String(page),
    per_page: '50',
  }

  const { data, isLoading } = useQuery<PaginatedVentas>({
    queryKey: ['postpago-ventas', query],
    queryFn:  () => api.get('/v1/postpago/ventas', { params: query }).then(r => r.data),
    staleTime: 30_000,
  })

  const ventas = data?.data ?? []

  return (
    <div className="kyro-card overflow-hidden">
      <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/70 via-amber-400/40 to-transparent" />
      {isLoading && (
        <div className="py-16 text-center text-sm text-kyro-muted">Cargando activaciones...</div>
      )}
      {!isLoading && (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="kyro-table-head">
                  {['Fecha', 'Tienda', 'Cliente', 'DNI', 'Plan', 'Tipo Alta', 'Cant.', 'Cobrado', 'Agente', 'Comisión', 'Estado', 'Flags'].map(h => (
                    <th key={h} className="px-3 py-2.5 text-left text-xs">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {ventas.map(v => (
                  <tr
                    key={`${v.id}-${v.tipo_alta}`}
                    className={`border-b border-kyro-border transition-colors ${v.es_remate ? 'bg-kyro-danger/5' : 'hover:bg-kyro-gold/5'}`}
                  >
                    <td className="px-3 py-2.5 text-xs text-kyro-muted">{v.fecha}</td>
                    <td className="px-3 py-2.5 font-mono text-xs text-kyro-subtle">{v.tienda_id}</td>
                    <td className="px-3 py-2.5 font-medium text-kyro-text">{v.cliente_nombre ?? '—'}</td>
                    <td className="px-3 py-2.5 font-mono text-xs text-kyro-subtle">{v.dni_ruc ?? '—'}</td>
                    <td className="max-w-[180px] px-3 py-2.5 text-xs text-kyro-body" title={v.plan_nombre_snap}>
                      <span className="line-clamp-1">{v.plan_nombre_snap}</span>
                    </td>
                    <td className="px-3 py-2.5">{tipoAltaBadge(v.tipo_alta)}</td>
                    <td className="px-3 py-2.5 text-center font-bold text-kyro-text">{v.cantidad}</td>
                    <td className="px-3 py-2.5 font-mono text-xs text-kyro-body">
                      S/{Number(v.cobrado_unitario).toFixed(2)}
                    </td>
                    <td className="px-3 py-2.5 text-xs text-kyro-subtle">{v.agente_nombres ?? '—'}</td>
                    <td className="px-3 py-2.5 font-mono text-xs text-kyro-success">
                      S/{Number(v.comision_generada).toFixed(2)}
                    </td>
                    <td className="px-3 py-2.5">
                      <Badge variant={v.comision_estado === 'ACTIVA' ? 'success' : v.comision_estado === 'ANULADA' ? 'destructive' : 'warning'}>
                        {v.comision_estado}
                      </Badge>
                    </td>
                    <td className="px-3 py-2.5">
                      <div className="flex flex-wrap gap-1">
                        {Boolean(v.es_remate)    && <Badge variant="destructive" className="text-[0.6rem] px-1 py-0">Remate</Badge>}
                        {Boolean(v.es_esim)      && <Badge variant="outline"     className="text-[0.6rem] px-1 py-0">eSIM</Badge>}
                        {Boolean(v.es_migracion) && <Badge variant="warning"     className="text-[0.6rem] px-1 py-0">Migración</Badge>}
                        {Boolean(v.es_upgrade)   && <Badge variant="outline"     className="text-[0.6rem] px-1 py-0">Upgrade</Badge>}
                      </div>
                    </td>
                  </tr>
                ))}
                {ventas.length === 0 && (
                  <tr>
                    <td colSpan={12} className="px-3 py-12 text-center text-kyro-muted text-sm">
                      Sin activaciones en el período seleccionado
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          {/* Paginación */}
          {data && data.last_page > 1 && (
            <div className="flex items-center justify-between border-t border-kyro-border px-4 py-3">
              <p className="text-xs text-kyro-muted">Total: {data.total}</p>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={page === 1} onClick={() => setPage(p => p - 1)}>
                  ← Anterior
                </Button>
                <span className="flex items-center px-2 text-xs text-kyro-muted">
                  {page} / {data.last_page}
                </span>
                <Button variant="outline" size="sm" disabled={page === data.last_page} onClick={() => setPage(p => p + 1)}>
                  Siguiente →
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

const TABS = [
  { id: 'activaciones', label: 'Activaciones' },
  { id: 'churn',        label: 'Riesgo Churn' },
  { id: 'analytics',   label: 'Analytics' },
] as const

export function PostpagoPage() {
  const hoy  = new Date().toISOString().slice(0, 10)
  const mes1 = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10)

  const [filters, setFilters] = useState({ desde: mes1, hasta: hoy, tienda_id: '', tipo_alta: '' })
  const [applied, setApplied] = useState({ ...filters })
  const [tab, setTab]         = useState<'activaciones' | 'churn' | 'analytics'>('activaciones')
  const [exportando, setExportando] = useState(false)

  const resumenParams = {
    desde:    applied.desde,
    hasta:    applied.hasta,
    ...(applied.tienda_id ? { tienda_id: applied.tienda_id } : {}),
    ...(applied.tipo_alta ? { tipo_alta: applied.tipo_alta } : {}),
  }

  const { data: resumen, isLoading: loadingResumen } = useQuery<PostpagoResumen>({
    queryKey:  ['postpago-resumen', resumenParams],
    queryFn:   () => api.get('/v1/postpago/resumen', { params: resumenParams }).then(r => r.data),
    staleTime: 60_000,
  })

  const t = resumen?.totales

  async function exportarExcel() {
    setExportando(true)
    try {
      const token = localStorage.getItem('auth_token')
      const base  = (api.defaults.baseURL ?? '').replace(/\/$/, '')
      const p     = new URLSearchParams(resumenParams as Record<string, string>)
      const r     = await fetch(`${base}/v1/postpago/exportar?${p}`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      const blob = await r.blob()
      const a    = document.createElement('a')
      a.href     = URL.createObjectURL(blob)
      a.download = `postpago_${applied.desde}_${applied.hasta}.xlsx`
      a.click()
      URL.revokeObjectURL(a.href)
    } finally {
      setExportando(false)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Monitor Postpago"
        subtitle="Seguimiento de activaciones, portabilidades y riesgo de churn"
        Icon={Signal}
      >
        <Button variant="glassSuccess" size="sm" onClick={exportarExcel} disabled={exportando}>
          <FileSpreadsheet size={14} /> {exportando ? 'Exportando…' : 'Exportar Excel'}
        </Button>
      </PageHeader>

      {/* Filtros */}
      <ListToolbar>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Desde</label>
          <input type="date" value={filters.desde}
            onChange={e => setFilters(f => ({ ...f, desde: e.target.value }))}
            className="kyro-input h-9 w-40" />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Hasta</label>
          <input type="date" value={filters.hasta}
            onChange={e => setFilters(f => ({ ...f, hasta: e.target.value }))}
            className="kyro-input h-9 w-40" />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Tienda</label>
          <input type="text" placeholder="Todas" value={filters.tienda_id}
            onChange={e => setFilters(f => ({ ...f, tienda_id: e.target.value }))}
            className="kyro-input h-9 w-28" />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Tipo Alta</label>
          <select value={filters.tipo_alta}
            onChange={e => setFilters(f => ({ ...f, tipo_alta: e.target.value }))}
            className="kyro-input h-9 w-36">
            <option value="">Todos</option>
            {Object.entries(TIPO_LABEL).map(([v, l]) => (
              <option key={v} value={v}>{l}</option>
            ))}
          </select>
        </div>
        <Button variant="gold" size="sm" onClick={() => setApplied({ ...filters })}>Buscar</Button>
        <Button variant="outline" size="sm" onClick={() => {
          const r = { desde: mes1, hasta: hoy, tienda_id: '', tipo_alta: '' }
          setFilters(r); setApplied(r)
        }}>
          Este mes
        </Button>
      </ListToolbar>

      {/* KPI Strip */}
      {loadingResumen && <div className="py-6 text-center text-sm text-kyro-muted">Cargando resumen...</div>}
      {!loadingResumen && t && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {[
            { label: 'Activaciones',   value: t.total_activaciones, icon: <Zap size={14} />,          accent: '#0d6efd', text: 'text-kyro-text' },
            { label: 'Portabilidades', value: t.portabilidades,     icon: <Phone size={14} />,         accent: '#0d6efd', text: 'text-kyro-text' },
            { label: 'Altas Nuevas',   value: t.altas_nuevas,       icon: <Star size={14} />,          accent: '#22c55e', text: 'text-kyro-success' },
            { label: 'Renovaciones',   value: t.renovaciones,       icon: <RefreshCw size={14} />,     accent: '#6366f1', text: 'text-kyro-body' },
            { label: 'Remates ⚠️',    value: t.remates,            icon: <AlertTriangle size={14} />, accent: '#ef4444', text: 'text-kyro-danger' },
            { label: 'Comisión activa', value: pen.format(Number(t.comision_activa)), icon: <TrendingUp size={14} />, accent: '#22c55e', text: 'text-kyro-success' },
          ].map(k => (
            <StatCard key={k.label} title={k.label} accent={k.accent} icon={k.icon} value={k.value} valueColorClass={k.text} />
          ))}
        </div>
      )}

      {/* Tabs */}
      <PageTabs
        tabs={TABS.map(t => ({ id: t.id, label: t.label }))}
        active={tab}
        onChange={(id) => setTab(id as typeof tab)}
      />

      {/* ── Tab: Activaciones ──────────────────────────────────────────────── */}
      {tab === 'activaciones' && (
        <TablaActivaciones params={resumenParams} />
      )}

      {/* ── Tab: Riesgo Churn ──────────────────────────────────────────────── */}
      {tab === 'churn' && (
        <div className="space-y-4">
          <div className="kyro-card border border-kyro-danger/30 bg-kyro-danger/5 p-4">
            <div className="flex items-center gap-2 text-sm font-semibold text-kyro-danger">
              <AlertTriangle size={16} />
              Clientes en Riesgo de Churn
            </div>
            <p className="mt-1 text-xs text-kyro-muted">
              Se muestran las activaciones con tarifa de remate (cobrado &lt; S/20). Estos clientes tienen mayor
              probabilidad de solicitar portabilidad saliente o abandono del plan por insatisfacción.
            </p>
          </div>
          <TablaActivaciones params={resumenParams} esChurn />
        </div>
      )}

      {/* ── Tab: Analytics ────────────────────────────────────────────────── */}
      {tab === 'analytics' && resumen && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* Por tipo de alta */}
            <div className="kyro-card p-4">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/70 to-transparent" />
              <h3 className="mb-4 text-sm font-semibold text-kyro-text">Activaciones por Tipo</h3>
              <ResponsiveContainer width="100%" height={220}>
                <BarChart data={resumen.por_tipo} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-kyro-border)" />
                  <XAxis dataKey="tipo_alta" tick={{ fontSize: 10 }}
                    tickFormatter={v => TIPO_LABEL[v] ?? v} />
                  <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
                  <Tooltip formatter={(v) => [Number(v), 'Activaciones']}
                    labelFormatter={l => TIPO_LABEL[l as string] ?? l} />
                  <Bar dataKey="total" radius={[4, 4, 0, 0]}>
                    {resumen.por_tipo.map(p => (
                      <Cell key={p.tipo_alta} fill={TIPO_COLOR[p.tipo_alta] ?? 'var(--color-kyro-muted)'} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>

            {/* Top planes */}
            <div className="kyro-card overflow-hidden">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-amber-400/70 to-transparent" />
              <div className="border-b border-kyro-border p-4">
                <h3 className="text-sm font-semibold text-kyro-text">Top Planes Postpago</h3>
              </div>
              <div className="divide-y divide-kyro-border">
                {resumen.top_planes.map((p, i) => (
                  <div key={p.plan} className="flex items-center justify-between px-4 py-2.5 text-sm transition-colors hover:bg-kyro-gold/5">
                    <div className="flex items-center gap-2 min-w-0">
                      <span className="w-5 shrink-0 text-xs text-kyro-muted">
                        {i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i+1}°`}
                      </span>
                      <span className="truncate text-kyro-body">{p.plan}</span>
                    </div>
                    <span className="ml-2 shrink-0 font-bold text-kpi-total">{p.total}</span>
                  </div>
                ))}
                {resumen.top_planes.length === 0 && (
                  <p className="px-4 py-6 text-center text-sm text-kyro-muted">Sin datos</p>
                )}
              </div>
            </div>
          </div>

          {/* Tendencia diaria */}
          {resumen.tendencia.length > 0 && (
            <div className="kyro-card p-4">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-green-500/50 to-transparent" />
              <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-kyro-text">
                <TrendingUp size={15} className="text-kyro-success" />Tendencia Diaria
              </h3>
              <ResponsiveContainer width="100%" height={220}>
                <LineChart data={resumen.tendencia} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-kyro-border)" />
                  <XAxis dataKey="fecha" tick={{ fontSize: 10 }}
                    tickFormatter={v => v.slice(5)} />
                  <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
                  <Tooltip labelFormatter={v => `Fecha: ${v}`} />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Line type="monotone" dataKey="activaciones"   stroke="var(--color-kpi-total)"      strokeWidth={2} dot={false} name="Activaciones" />
                  <Line type="monotone" dataKey="portabilidades" stroke="var(--color-kyro-indigo)"    strokeWidth={2} dot={false} name="Portabilidades" />
                  <Line type="monotone" dataKey="remates"        stroke="var(--color-kyro-danger)"    strokeWidth={1.5} dot={false} name="Remates" strokeDasharray="4 2" />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
