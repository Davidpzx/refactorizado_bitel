import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  BarChart, Bar, LineChart, Line, XAxis, YAxis, Tooltip, Legend,
  ResponsiveContainer, CartesianGrid, Cell,
} from 'recharts'
import { api } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { Download, TrendingUp } from 'lucide-react'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })

const COLORS = {
  postpago: '#2563eb',
  prepago:  '#7c3aed',
  equipos:  '#ea580c',
  accesorios: '#16a34a',
  otros:    '#94a3b8',
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
  postpago: number
  prepago: number
  equipos: number
  accesorios: number
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

  const { data: statsData, isLoading } = useQuery({
    queryKey: ['estadisticas-ventas', applied],
    queryFn: () =>
      api.get('/v1/estadisticas/ventas', { params: applied }).then(r => r.data),
  })

  const { data: rankingData } = useQuery({
    queryKey: ['estadisticas-ranking', applied],
    queryFn: () =>
      api.get('/v1/estadisticas/productividad', { params: applied }).then(r => r.data),
  })

  const totales: Totales | null = statsData?.totales ?? null
  const series: Serie[]         = statsData?.series ?? []
  const porTienda: TiendaStat[] = statsData?.por_tienda ?? []
  const topPlanes: TopItem[]    = statsData?.top_planes ?? []
  const topEquipos: TopItem[]   = statsData?.top_equipos ?? []
  const ranking: AgentRank[]    = rankingData?.ranking ?? []

  const categoriaBar = totales
    ? [
        { name: 'Postpago',   value: Number(totales.postpago),   fill: COLORS.postpago },
        { name: 'Prepago',    value: Number(totales.prepago),    fill: COLORS.prepago },
        { name: 'Eq.Cuotas',  value: Number(totales.eq_cuotas),  fill: COLORS.equipos },
        { name: 'Eq.Contado', value: Number(totales.eq_contado), fill: COLORS.equipos },
        { name: 'Accesorios', value: Number(totales.accesorios), fill: COLORS.accesorios },
      ]
    : []

  function exportarExcel() {
    const token = localStorage.getItem('auth_token')
    const base  = (api.defaults.baseURL ?? '').replace(/\/$/, '')
    const params = new URLSearchParams(applied as Record<string, string>)
    const url = `${base}/v1/estadisticas/ventas?${params.toString()}`
    fetch(url, { headers: { Authorization: `Bearer ${token}` } })
      .then(r => r.blob())
      .then(blob => {
        const a = document.createElement('a')
        a.href = URL.createObjectURL(blob)
        a.download = `estadisticas_${applied.fecha_desde}_${applied.fecha_hasta}.json`
        a.click()
      })
  }

  const TABS = [
    { id: 'resumen',  label: 'Resumen' },
    { id: 'tiendas',  label: 'Por Tienda' },
    { id: 'top',      label: 'Top Productos' },
    { id: 'ranking',  label: 'Ranking Agentes' },
  ] as const

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <h1 className="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <TrendingUp size={20} className="text-blue-600" /> Estadísticas de Ventas
        </h1>
        <Button variant="outline" size="sm" onClick={exportarExcel}>
          <Download size={14} /> Exportar
        </Button>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border border-gray-200 p-4">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Desde</label>
            <input type="date" value={filters.fecha_desde}
              onChange={e => setFilters(f => ({ ...f, fecha_desde: e.target.value }))}
              className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Hasta</label>
            <input type="date" value={filters.fecha_hasta}
              onChange={e => setFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
              className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          {usuario?.rol === 'admin' && (
            <div>
              <label className="block text-xs text-gray-500 mb-1">Tienda</label>
              <input type="text" placeholder="Todas" value={filters.tienda}
                onChange={e => setFilters(f => ({ ...f, tienda: e.target.value }))}
                className="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-28 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          )}
          <Button onClick={() => setApplied({ ...filters })}>Buscar</Button>
          <Button variant="outline" onClick={() => {
            const reset = { fecha_desde: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10), fecha_hasta: new Date().toISOString().slice(0, 10), tienda: '' }
            setFilters(reset); setApplied(reset)
          }}>Hoy</Button>
        </div>
      </div>

      {/* KPI Cards */}
      {totales && (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
          {[
            { label: 'Total Ventas',  value: totales.total_ventas, sub: pen.format(Number(totales.monto_total)) },
            { label: 'Postpago',      value: totales.postpago,     sub: `${totales.total_ventas > 0 ? Math.round(totales.postpago * 100 / totales.total_ventas) : 0}%` },
            { label: 'Prepago/Chips', value: totales.prepago,      sub: '' },
            { label: 'Eq. Cuotas',   value: totales.eq_cuotas,    sub: '' },
            { label: 'Eq. Contado',  value: totales.eq_contado,   sub: '' },
            { label: 'Accesorios',   value: totales.accesorios,   sub: '' },
          ].map(kpi => (
            <div key={kpi.label} className="bg-white rounded-xl border border-gray-200 p-4">
              <p className="text-xs text-gray-500 mb-1">{kpi.label}</p>
              <p className="text-2xl font-bold text-gray-900">{kpi.value}</p>
              {kpi.sub && <p className="text-xs text-gray-400 mt-0.5">{kpi.sub}</p>}
            </div>
          ))}
        </div>
      )}

      {/* Tabs */}
      <div className="flex gap-1 bg-gray-100 rounded-lg p-1 w-fit">
        {TABS.map(t => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className={`px-4 py-1.5 rounded-md text-sm font-medium transition-colors ${tab === t.id ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-800'}`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {isLoading && <div className="text-center py-10 text-gray-400 text-sm">Cargando estadísticas...</div>}

      {/* TAB: Resumen */}
      {!isLoading && tab === 'resumen' && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Bar por categoría */}
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <h3 className="font-semibold text-gray-800 mb-4 text-sm">Ventas por Categoría</h3>
            <ResponsiveContainer width="100%" height={250}>
              <BarChart data={categoriaBar} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
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
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <h3 className="font-semibold text-gray-800 mb-4 text-sm">Tendencia Diaria</h3>
            <ResponsiveContainer width="100%" height={250}>
              <LineChart data={series} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
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
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div className="p-4 border-b border-gray-200">
            <h3 className="font-semibold text-gray-800 text-sm">Ranking por Tienda</h3>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-200">
                  {['#', 'Tienda', 'Postpago', 'Prepago', 'Equipos', 'Accesorios', 'Total'].map(h => (
                    <th key={h} className="px-4 py-3 text-xs font-semibold text-gray-500 text-left">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {porTienda.map((t, i) => (
                  <tr key={t.tienda_id} className="border-b border-gray-100 hover:bg-gray-50/60">
                    <td className="px-4 py-3 text-gray-400 text-xs">{i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i + 1}°`}</td>
                    <td className="px-4 py-3 font-mono font-medium text-slate-700">{t.tienda_id}</td>
                    <td className="px-4 py-3 font-bold text-blue-700">{t.postpago}</td>
                    <td className="px-4 py-3 font-bold text-purple-700">{t.prepago}</td>
                    <td className="px-4 py-3 font-bold text-orange-700">{t.equipos}</td>
                    <td className="px-4 py-3 font-bold text-green-700">{t.accesorios}</td>
                    <td className="px-4 py-3 font-bold text-gray-900">{t.total}</td>
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
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div className="p-4 border-b border-gray-200">
            <h3 className="font-semibold text-gray-800 text-sm">Ranking de Productividad por Agente</h3>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-200">
                  {['#', 'Agente', 'Tienda', 'Postpago', 'Prepago', 'Equipos', 'Accesorios', 'Comisión', 'Total'].map(h => (
                    <th key={h} className="px-4 py-3 text-xs font-semibold text-gray-500 text-left">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {ranking.map((a, i) => (
                  <tr key={a.vendedor_id} className={`border-b border-gray-100 ${i < 3 ? 'bg-yellow-50/30' : 'hover:bg-gray-50/60'}`}>
                    <td className="px-4 py-3 text-xs text-gray-400">{i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i + 1}°`}</td>
                    <td className="px-4 py-3 font-medium text-gray-800">{a.nombres}</td>
                    <td className="px-4 py-3 text-xs font-mono text-slate-500">{a.tienda_base}</td>
                    <td className="px-4 py-3 font-bold text-blue-700">{a.postpago}</td>
                    <td className="px-4 py-3 font-bold text-purple-700">{a.prepago}</td>
                    <td className="px-4 py-3 font-bold text-orange-700">{a.equipos}</td>
                    <td className="px-4 py-3 font-bold text-green-700">{a.accesorios}</td>
                    <td className="px-4 py-3 font-mono text-green-700">{pen.format(Number(a.comision_total))}</td>
                    <td className="px-4 py-3 font-bold text-gray-900">{a.total}</td>
                  </tr>
                ))}
                {ranking.length === 0 && (
                  <tr><td colSpan={9} className="px-4 py-10 text-center text-gray-400">Sin datos en el período</td></tr>
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
    <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div className="p-4 border-b border-gray-200">
        <h3 className="font-semibold text-gray-800 text-sm">{title}</h3>
      </div>
      <div className="divide-y divide-gray-100">
        {items.map((item, i) => (
          <div key={i} className="flex items-center justify-between px-4 py-2.5 hover:bg-gray-50/60">
            <div className="flex items-center gap-2 min-w-0">
              <span className="text-xs text-gray-400 w-5 shrink-0">
                {i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i + 1}°`}
              </span>
              <span className="text-sm text-gray-700 truncate">{item.name}</span>
            </div>
            <span className={`text-sm font-bold shrink-0 ml-2 ${colorMap[color] ?? 'text-gray-800'}`}>{item.total}</span>
          </div>
        ))}
        {items.length === 0 && (
          <p className="px-4 py-6 text-center text-gray-400 text-sm">Sin datos</p>
        )}
      </div>
    </div>
  )
}
