import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  BarChart, Bar, LineChart, Line, XAxis, YAxis, Tooltip, Legend,
  ResponsiveContainer, CartesianGrid, Cell,
} from 'recharts'
import { api } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { PageTabs } from '../../components/ui/PageTabs'
import { Download, TrendingUp } from 'lucide-react'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

const COLORS = {
  postpago: 'var(--color-kpi-total)',
  prepago:  'var(--color-kpi-yape)',
  equipos:  'var(--color-kyro-warning)',
  accesorios: 'var(--color-kyro-success)',
  otros:    'var(--color-kyro-muted)',
}


interface Totales {
  postpago: number
  prepago: number
  eq_cuotas: number
  eq_contado: number
  accesorios: number
  total_ventas: number
  monto_total: string
}

interface TiendaStat {
  tienda_id: string
  postpago: number
  prepago: number
  equipos: number
  accesorios: number
  total: number
}

interface Serie {
  dia: string
  postpago: number
  prepago: number
  equipos: number
  accesorios: number
  total: number
}

interface TopItem {
  plan?: string
  producto?: string
  total: number
}

interface AgentRank {
  vendedor_id: number
  nombres: string
  tienda_base: string
  postpago?: number
  prepago?: number
  equipos?: number
  accesorios?: number
  comision_total: string
  total: number
}

export function EstadisticasPage() {
  const { usuario } = useAuth()

  const [filters, setFilters] = useState({
    fecha_desde: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
    fecha_hasta: new Date().toISOString().slice(0, 10),
    tienda: '',
  })
  const [applied, setApplied] = useState({ ...filters })
  const [tab, setTab] = useState<'resumen' | 'tiendas' | 'top' | 'ranking'>('resumen')

  // Subfiltros del ranking (paridad legacy obtener_ranking/subfiltros).
  const [rankCat, setRankCat] = useState<'todo' | 'equipos' | 'postpago' | 'chips'>('todo')
  const [rankSub, setRankSub] = useState('')

  const { data: statsData, isLoading } = useQuery({
    queryKey: ['estadisticas-ventas', applied],
    queryFn: () =>
      api.get('/v1/estadisticas/ventas', { params: applied }).then(r => r.data),
  })

  const { data: rankingData } = useQuery({
    queryKey: ['estadisticas-ranking', applied, rankCat, rankSub],
    queryFn: () =>
      api.get('/v1/estadisticas/ranking', {
        params: { ...applied, categoria: rankCat, subcategoria: rankSub },
      }).then(r => r.data),
  })

  // Opciones de subcategoría según la categoría elegida.
  const { data: subfiltrosData } = useQuery({
    queryKey: ['estadisticas-subfiltros', applied, rankCat],
    enabled: rankCat !== 'todo',
    queryFn: () =>
      api.get('/v1/estadisticas/ranking/subfiltros', {
        params: { ...applied, categoria: rankCat },
      }).then(r => r.data),
  })
  const subcategorias: string[] = subfiltrosData?.subcategorias ?? []
  const rankingFiltrado = rankCat !== 'todo'

  const totales: Totales | null = statsData?.totales ?? null
  const series: Serie[]         = statsData?.series ?? []
  const porTienda: TiendaStat[] = statsData?.por_tienda ?? []
  const topPlanes: TopItem[]    = statsData?.top_planes ?? []
  const topEquipos: TopItem[]   = statsData?.top_equipos ?? []
  const ranking: AgentRank[]    = rankingData?.ranking ?? []

  const [exportando, setExportando] = useState(false)

  const categoriaBar = totales
    ? [
        { name: 'Postpago',   value: Number(totales.postpago),   fill: COLORS.postpago },
        { name: 'Prepago',    value: Number(totales.prepago),    fill: COLORS.prepago },
        { name: 'Eq.Cuotas',  value: Number(totales.eq_cuotas),  fill: COLORS.equipos },
        { name: 'Eq.Contado', value: Number(totales.eq_contado), fill: COLORS.equipos },
        { name: 'Accesorios', value: Number(totales.accesorios), fill: COLORS.accesorios },
      ]
    : []

  async function exportarExcel() {
    setExportando(true)
    try {
      const token = localStorage.getItem('auth_token')
      const base  = (api.defaults.baseURL ?? '').replace(/\/$/, '')
      const params = new URLSearchParams(applied as Record<string, string>)
      const url = `${base}/v1/estadisticas/exportar?${params.toString()}`
      const r = await fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      const blob = await r.blob()
      const a = document.createElement('a')
      a.href = URL.createObjectURL(blob)
      a.download = `estadisticas_${applied.fecha_desde}_${applied.fecha_hasta}.xlsx`
      a.click()
      URL.revokeObjectURL(a.href)
    } finally {
      setExportando(false)
    }
  }

  const TABS = [
    { id: 'resumen',  label: 'Resumen' },
    { id: 'tiendas',  label: 'Por Tienda' },
    { id: 'top',      label: 'Top Productos' },
    { id: 'ranking',  label: 'Ranking Agentes' },
  ] as const

  return (
    <div className="space-y-6">
      <PageHeader
        title="Estadísticas de Ventas"
        description="Explora el rendimiento comercial por categoría, tienda, producto y agente."
        actions={<Button variant="outline" size="sm" onClick={exportarExcel} disabled={exportando}>
          <Download size={14} /> {exportando ? 'Exportando…' : 'Exportar'}
        </Button>}
      />

      <ListToolbar description="Ajusta el período de análisis y limita los resultados a una tienda.">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Desde</label>
            <input type="date" value={filters.fecha_desde}
              onChange={e => setFilters(f => ({ ...f, fecha_desde: e.target.value }))}
              className="kyro-input h-9 w-40"
            />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Hasta</label>
            <input type="date" value={filters.fecha_hasta}
              onChange={e => setFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
              className="kyro-input h-9 w-40"
            />
          </div>
          {usuario?.rol === 'admin' && (
            <div>
              <label className="block text-xs text-gray-500 mb-1">Tienda</label>
              <input type="text" placeholder="Todas" value={filters.tienda}
                onChange={e => setFilters(f => ({ ...f, tienda: e.target.value }))}
                className="kyro-input h-9 w-32"
              />
            </div>
          )}
          <Button onClick={() => setApplied({ ...filters })}>Buscar</Button>
          <Button variant="outline" onClick={() => {
            const reset = { fecha_desde: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10), fecha_hasta: new Date().toISOString().slice(0, 10), tienda: '' }
            setFilters(reset); setApplied(reset)
          }}>Hoy</Button>
      </ListToolbar>

      {/* KPI Cards */}
      {totales && (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
          {[
            { label: 'Total Ventas',  value: totales.total_ventas, sub: pen.format(Number(totales.monto_total)), border: 'border-l-kpi-total' },
            { label: 'Postpago',      value: totales.postpago,     sub: `${totales.total_ventas > 0 ? Math.round(totales.postpago * 100 / totales.total_ventas) : 0}%`, border: 'border-l-kpi-total' },
            { label: 'Prepago/Chips', value: totales.prepago,      sub: '', border: 'border-l-kpi-yape' },
            { label: 'Eq. Cuotas',   value: totales.eq_cuotas,    sub: '', border: 'border-l-kyro-warning' },
            { label: 'Eq. Contado',  value: totales.eq_contado,   sub: '', border: 'border-l-kyro-warning' },
            { label: 'Accesorios',   value: totales.accesorios,   sub: '', border: 'border-l-kyro-success' },
          ].map(kpi => (
            <div key={kpi.label} className={`kyro-card border-l-4 p-4 transition-all duration-200 hover:-translate-y-0.5 ${kpi.border}`}>
              <p className="mb-1 text-[0.68rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted">{kpi.label}</p>
              <p className="text-2xl font-bold tracking-tight text-kyro-text">{kpi.value}</p>
              {kpi.sub && <p className="mt-0.5 text-xs text-kyro-subtle">{kpi.sub}</p>}
            </div>
          ))}
        </div>
      )}

      {/* Tabs */}
      <PageTabs
        tabs={TABS.map(t => ({ id: t.id, label: t.label }))}
        active={tab}
        onChange={(id) => setTab(id as typeof tab)}
      />

      {isLoading && <div className="text-center py-10 text-gray-400 text-sm">Cargando estadísticas...</div>}

      {/* TAB: Resumen */}
      {!isLoading && tab === 'resumen' && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Bar por categoría */}
          <div className="kyro-card p-4">
            <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/70 to-transparent" />
            <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-kyro-text"><span className="h-2 w-2 rounded-full bg-kyro-indigo" />Ventas por Categoría</h3>
            <ResponsiveContainer width="100%" height={250}>
              <BarChart data={categoriaBar} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--color-kyro-border)" />
                <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip formatter={(v) => [Number(v ?? 0), 'Ventas']} />
                <Bar dataKey="value" radius={[4, 4, 0, 0]}>
                  {categoriaBar.map((entry, i) => <Cell key={i} fill={entry.fill} />)}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>

          {/* Line series de tiempo */}
          <div className="kyro-card p-4">
            <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-amber-400/70 to-transparent" />
            <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-kyro-text"><TrendingUp size={15} className="text-kyro-gold" />Tendencia Diaria</h3>
            <ResponsiveContainer width="100%" height={250}>
              <LineChart data={series} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--color-kyro-border)" />
                <XAxis dataKey="dia" tick={{ fontSize: 10 }}
                  tickFormatter={v => v.slice(5)} />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip labelFormatter={v => `Día: ${v}`} />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Line type="monotone" dataKey="postpago"  stroke={COLORS.postpago}   strokeWidth={2} dot={false} name="Postpago" />
                <Line type="monotone" dataKey="prepago"   stroke={COLORS.prepago}    strokeWidth={2} dot={false} name="Prepago" />
                <Line type="monotone" dataKey="equipos"   stroke={COLORS.equipos}    strokeWidth={2} dot={false} name="Equipos" />
                <Line type="monotone" dataKey="accesorios" stroke={COLORS.accesorios} strokeWidth={2} dot={false} name="Accesorios" />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </div>
      )}

      {/* TAB: Por Tienda */}
      {!isLoading && tab === 'tiendas' && (
        <div className="kyro-card overflow-hidden">
          <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/70 via-amber-400/40 to-transparent" />
          <div className="border-b border-kyro-border p-4">
            <h3 className="text-sm font-semibold text-kyro-text">Ranking por Tienda</h3>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="kyro-table-head">
                  {['#', 'Tienda', 'Postpago', 'Prepago', 'Equipos', 'Accesorios', 'Total'].map(h => (
                    <th key={h} className="px-4 py-3 text-left">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {porTienda.map((t, i) => (
                  <tr key={t.tienda_id} className="border-b border-kyro-border transition-colors hover:bg-kyro-gold/5">
                    <td className="px-4 py-3 text-gray-400 text-xs">{i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i + 1}°`}</td>
                    <td className="px-4 py-3 font-mono font-medium text-slate-700">{t.tienda_id}</td>
                    <td className="px-4 py-3 font-bold text-blue-700">{t.postpago}</td>
                    <td className="px-4 py-3 font-bold text-purple-700">{t.prepago}</td>
                    <td className="px-4 py-3 font-bold text-orange-700">{t.equipos}</td>
                    <td className="px-4 py-3 font-bold text-green-700">{t.accesorios}</td>
                    <td className="px-4 py-3 font-bold text-kyro-text">{t.total}</td>
                  </tr>
                ))}
                {porTienda.length === 0 && (
                  <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-400">Sin datos</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* TAB: Top Productos */}
      {!isLoading && tab === 'top' && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <TopList title="Top Planes Postpago"  items={topPlanes.map(p => ({ name: p.plan ?? '—', total: p.total }))}  color="blue" />
          <TopList title="Top Equipos"          items={topEquipos.map(p => ({ name: p.producto ?? '—', total: p.total }))} color="orange" />
        </div>
      )}

      {/* TAB: Ranking Agentes */}
      {!isLoading && tab === 'ranking' && (
        <div className="kyro-card overflow-hidden">
          <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-amber-400/70 via-indigo-500/40 to-transparent" />
          <div className="flex flex-col gap-3 border-b border-kyro-border p-4 sm:flex-row sm:items-end sm:justify-between">
            <h3 className="text-sm font-semibold text-kyro-text">Ranking de Productividad por Agente</h3>
            <div className="flex flex-wrap items-end gap-3">
              <div>
                <label className="block text-xs text-gray-500 mb-1">Categoría</label>
                <select
                  value={rankCat}
                  onChange={e => { setRankCat(e.target.value as typeof rankCat); setRankSub('') }}
                  className="kyro-input h-9 w-36"
                >
                  <option value="todo">Todas</option>
                  <option value="equipos">Equipos</option>
                  <option value="postpago">Postpago</option>
                  <option value="chips">Chips/Prepago</option>
                </select>
              </div>
              {rankingFiltrado && (
                <div>
                  <label className="block text-xs text-gray-500 mb-1">Subfiltro</label>
                  <select value={rankSub} onChange={e => setRankSub(e.target.value)} className="kyro-input h-9 w-48">
                    <option value="">Todos</option>
                    {subcategorias.map(s => <option key={s} value={s}>{s}</option>)}
                  </select>
                </div>
              )}
            </div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="kyro-table-head">
                  {(rankingFiltrado
                    ? ['#', 'Agente', 'Tienda', 'Ventas', 'Comisión']
                    : ['#', 'Agente', 'Tienda', 'Postpago', 'Prepago', 'Equipos', 'Accesorios', 'Comisión', 'Total']
                  ).map(h => (
                    <th key={h} className="px-4 py-3 text-left">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {ranking.map((a, i) => (
                  <tr key={a.vendedor_id} className={`border-b border-kyro-border transition-colors ${i < 3 ? 'bg-kyro-gold/5' : 'hover:bg-kyro-gold/5'}`}>
                    <td className="px-4 py-3 text-xs text-gray-400">{i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i + 1}°`}</td>
                    <td className="px-4 py-3 font-medium text-kyro-text">{a.nombres}</td>
                    <td className="px-4 py-3 text-xs font-mono text-slate-500">{a.tienda_base}</td>
                    {rankingFiltrado ? (
                      <>
                        <td className="px-4 py-3 font-bold text-kyro-text">{a.total}</td>
                        <td className="px-4 py-3 font-mono text-green-700">{pen.format(Number(a.comision_total))}</td>
                      </>
                    ) : (
                      <>
                        <td className="px-4 py-3 font-bold text-blue-700">{a.postpago}</td>
                        <td className="px-4 py-3 font-bold text-purple-700">{a.prepago}</td>
                        <td className="px-4 py-3 font-bold text-orange-700">{a.equipos}</td>
                        <td className="px-4 py-3 font-bold text-green-700">{a.accesorios}</td>
                        <td className="px-4 py-3 font-mono text-green-700">{pen.format(Number(a.comision_total))}</td>
                        <td className="px-4 py-3 font-bold text-kyro-text">{a.total}</td>
                      </>
                    )}
                  </tr>
                ))}
                {ranking.length === 0 && (
                  <tr><td colSpan={rankingFiltrado ? 5 : 9} className="px-4 py-10 text-center text-gray-400">Sin datos en el período</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

function TopList({ title, items, color }: { title: string; items: { name: string; total: number }[]; color: string }) {
  const colorMap: Record<string, string> = {
    blue: 'text-blue-700', orange: 'text-orange-700', green: 'text-green-700',
  }
  return (
    <div className="kyro-card overflow-hidden">
      <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/70 via-amber-400/35 to-transparent" />
      <div className="border-b border-kyro-border p-4">
        <h3 className="text-sm font-semibold text-kyro-text">{title}</h3>
      </div>
      <div className="divide-y divide-kyro-border">
        {items.map((item, i) => (
          <div key={i} className="flex items-center justify-between px-4 py-2.5 transition-colors hover:bg-amber-50/40 dark:hover:bg-amber-400/[0.04]">
            <div className="flex items-center gap-2 min-w-0">
              <span className="text-xs text-gray-400 w-5 shrink-0">
                {i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i + 1}°`}
              </span>
              <span className="truncate text-sm text-kyro-body">{item.name}</span>
            </div>
            <span className={`ml-2 shrink-0 text-sm font-bold ${colorMap[color] ?? '