import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Input } from '../../components/ui/input'
import { ChevronLeft, ChevronRight, TrendingUp, TrendingDown, Activity, Store } from 'lucide-react'

interface BitacoraKpis {
  total_mov: number
  total_entradas: number
  total_salidas: number
  tiendas_afectadas: number
  agentes_involucrados: number
}

interface Movimiento {
  id: number
  fecha_hora: string
  tienda_id: string
  accion: 'SUMA' | 'RESTA'
  cantidad: number
  motivo: string | null
  observacion: string | null
  agente_nombre: string
  producto_nombre: string
  producto_tipo: string
}

interface BitacoraResponse {
  kpis: BitacoraKpis | null
  movimientos: {
    data: Movimiento[]
    total: number
    current_page: number
    last_page: number
    from: number
    to: number
  }
  warning?: string
}

export function BitacoraStockPage() {
  const { usuario } = useAuth()

  const [filters, setFilters] = useState({
    fecha_desde: '',
    fecha_hasta: '',
    tienda: '',
    accion: '',
  })
  const [page, setPage] = useState(1)
  const [applied, setApplied] = useState({ ...filters, page: 1 })

  const { data, isLoading } = useQuery({
    queryKey: ['bitacora-stock', applied],
    queryFn: () =>
      api.get<BitacoraResponse>('/v1/bitacora-stock', {
        params: {
          page: applied.page,
          per_page: 20,
          fecha_desde: applied.fecha_desde || undefined,
          fecha_hasta: applied.fecha_hasta || undefined,
          tienda: applied.tienda || undefined,
          accion: applied.accion || undefined,
        },
      }).then(r => r.data),
  })

  const kpis = data?.kpis
  const movimientos = data?.movimientos?.data ?? []
  const meta = data?.movimientos

  function applyFilters() { setPage(1); setApplied({ ...filters, page: 1 }) }
  function resetFilters() {
    const e = { fecha_desde: '', fecha_hasta: '', tienda: '', accion: '' }
    setFilters(e); setPage(1); setApplied({ ...e, page: 1 })
  }
  function goToPage(p: number) { setPage(p); setApplied(a => ({ ...a, page: p })) }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Bitácora de Stock"
        description="Trazabilidad de entradas, salidas y ajustes de inventario."
      />

      {data?.warning && (
        <div className="rounded-xl border border-amber-200/80 bg-amber-50/70 p-4 text-sm text-amber-800 shadow-sm backdrop-blur-xl dark:border-amber-400/20 dark:bg-amber-500/[0.08] dark:text-amber-300">
          ⚠️ {data.warning}
        </div>
      )}

      {/* KPI Cards */}
      {kpis && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div className="group relative overflow-hidden rounded-xl border border-blue-200/70 bg-blue-50/45 p-4 shadow-[0_12px_30px_-22px_rgba(37,99,235,0.45)] backdrop-blur-xl transition-all hover:-translate-y-0.5 dark:border-blue-400/15 dark:bg-blue-500/[0.055]">
            <div className="flex items-center gap-2 mb-1">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-blue-200/70 bg-white/65 text-blue-600 dark:border-blue-400/15 dark:bg-blue-500/10 dark:text-blue-300"><Activity size={15} /></span>
              <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-zinc-400">Total Movimientos</p>
            </div>
            <p className="mt-2 font-mono text-xl font-bold text-blue-700 dark:text-blue-300">{kpis.total_mov.toLocaleString()}</p>
          </div>
          <div className="group relative overflow-hidden rounded-xl border border-emerald-200/70 bg-emerald-50/45 p-4 shadow-[0_12px_30px_-22px_rgba(16,185,129,0.45)] backdrop-blur-xl transition-all hover:-translate-y-0.5 dark:border-emerald-400/15 dark:bg-emerald-500/[0.055]">
            <div className="flex items-center gap-2 mb-1">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-emerald-200/70 bg-white/65 text-emerald-600 dark:border-emerald-400/15 dark:bg-emerald-500/10 dark:text-emerald-300"><TrendingUp size={15} /></span>
              <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-zinc-400">Entradas (+)</p>
            </div>
            <p className="mt-2 font-mono text-xl font-bold text-emerald-700 dark:text-emerald-300">+{kpis.total_entradas.toLocaleString()}</p>
          </div>
          <div className="group relative overflow-hidden rounded-xl border border-red-200/70 bg-red-50/45 p-4 shadow-[0_12px_30px_-22px_rgba(220,38,38,0.4)] backdrop-blur-xl transition-all hover:-translate-y-0.5 dark:border-red-400/15 dark:bg-red-500/[0.055]">
            <div className="flex items-center gap-2 mb-1">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-red-200/70 bg-white/65 text-red-600 dark:border-red-400/15 dark:bg-red-500/10 dark:text-red-300"><TrendingDown size={15} /></span>
              <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-zinc-400">Salidas (-)</p>
            </div>
            <p className="mt-2 font-mono text-xl font-bold text-red-700 dark:text-red-300">-{kpis.total_salidas.toLocaleString()}</p>
          </div>
          <div className="group relative overflow-hidden rounded-xl border border-gray-200/80 bg-white/80 p-4 shadow-[0_12px_30px_-22px_rgba(15,23,42,0.45)] backdrop-blur-xl transition-all hover:-translate-y-0.5 dark:border-white/[0.08] dark:bg-zinc-900/65">
            <div className="flex items-center gap-2 mb-1">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg border border-gray-200/80 bg-gray-50/80 text-slate-600 dark:border-white/10 dark:bg-white/[0.04] dark:text-zinc-300"><Store size={15} /></span>
              <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-zinc-400">Balance Neto</p>
            </div>
            <p className={`mt-2 font-mono text-xl font-bold ${(kpis.total_entradas - kpis.total_salidas) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'}`}>
              {kpis.total_entradas - kpis.total_salidas >= 0 ? '+' : ''}
              {(kpis.total_entradas - kpis.total_salidas).toLocaleString()}
            </p>
          </div>
        </div>
      )}

      {/* Filters */}
      <ListToolbar description="Acota el historial por fecha, tienda o tipo de movimiento.">
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-zinc-400">Desde</label>
          <Input type="date" value={filters.fecha_desde}
            onChange={e => setFilters(f => ({ ...f, fecha_desde: e.target.value }))}
            className="w-auto" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-zinc-400">Hasta</label>
          <Input type="date" value={filters.fecha_hasta}
            onChange={e => setFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
            className="w-auto" />
        </div>
        {usuario?.rol === 'admin' && (
          <div>
            <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-zinc-400">Tienda</label>
            <Input type="text" placeholder="Todas" value={filters.tienda}
              onChange={e => setFilters(f => ({ ...f, tienda: e.target.value }))}
              className="w-28" />
          </div>
        )}
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-zinc-400">Acción</label>
          <select value={filters.accion} onChange={e => setFilters(f => ({ ...f, accion: e.target.value }))}
            className="h-9 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm text-gray-700 shadow-sm transition-all dark:border-white/10 dark:bg-black/20 dark:text-zinc-200">
            <option value="">Todos</option>
            <option value="SUMA">Entradas (SUMA)</option>
            <option value="RESTA">Salidas (RESTA)</option>
          </select>
        </div>
        <Button onClick={applyFilters}>Filtrar</Button>
        <Button variant="outline" onClick={resetFilters}>Limpiar</Button>
      </ListToolbar>

      {/* Table */}
      <div className="relative overflow-hidden rounded-xl border border-gray-200/80 bg-white/80 shadow-[0_18px_45px_-30px_rgba(15,23,42,0.55)] backdrop-blur-xl dark:border-white/[0.08] dark:bg-zinc-900/65 dark:shadow-[0_22px_50px_-30px_rgba(0,0,0,0.95)]">
        <div aria-hidden className="absolute inset-x-0 top-0 h-px" style={{ background: 'linear-gradient(90deg, rgba(99,102,241,0.8), rgba(255,194,0,0.55) 45%, transparent 82%)' }} />
        <div className="flex items-center justify-between border-b border-gray-200/80 p-4 dark:border-white/[0.07]">
          <h2 className="text-sm font-semibold text-gray-900 dark:text-zinc-100">
            {isLoading ? 'Cargando...' : `${meta?.total ?? 0} movimientos`}
          </h2>
          <span className="rounded-full border border-gray-200/80 bg-gray-50/80 px-2.5 py-1 text-xs text-gray-400 dark:border-white/10 dark:bg-white/[0.035] dark:text-zinc-500">Pág. {meta?.current_page ?? 1}/{meta?.last_page ?? 1}</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 bg-gray-50/90 dark:border-white/[0.07] dark:bg-white/[0.035]">
                {['Fecha/Hora', 'Tienda', 'Agente', 'Producto', 'Tipo', 'Acción', 'Cant.', 'Motivo'].map(h => (
                  <th key={h} className={`px-4 py-3 text-left text-[0.68rem] font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-zinc-400 ${h === 'Cant.' ? 'text-center' : ''}`}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-400">Cargando...</td></tr>}
              {!isLoading && movimientos.length === 0 && (
                <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-400">Sin movimientos en el período seleccionado</td></tr>
              )}
              {movimientos.map(m => (
                <tr key={m.id} className={`border-b transition-colors ${m.accion === 'SUMA' ? 'border-emerald-100 bg-emerald-50/20 hover:bg-emerald-50/50 dark:border-emerald-400/10 dark:bg-emerald-400/[0.015] dark:hover:bg-emerald-400/[0.04]' : 'border-red-100 bg-red-50/20 hover:bg-red-50/50 dark:border-red-400/10 dark:bg-red-400/[0.015] dark:hover:bg-red-400/[0.04]'}`}>
                  <td className="px-4 py-3 font-mono text-xs text-gray-600 dark:text-zinc-400">
                    {new Date(m.fecha_hora).toLocaleString('es-PE')}
                  </td>
                  <td className="px-4 py-3">
                    <span className="rounded-md border border-slate-200/80 bg-slate-100/80 px-2 py-1 font-mono text-xs text-slate-700 dark:border-white/10 dark:bg-white/[0.05] dark:text-zinc-300">{m.tienda_id}</span>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-700 dark:text-zinc-300">{m.agente_nombre}</td>
                  <td className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-zinc-200">{m.producto_nombre}</td>
                  <td className="px-4 py-3">
                    <span className="rounded-md border border-gray-200/80 bg-gray-100/80 px-2 py-1 text-[0.68rem] font-semibold uppercase tracking-wide text-gray-600 dark:border-white/10 dark:bg-white/[0.04] dark:text-zinc-400">{m.producto_tipo}</span>
                  </td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-bold ${m.accion === 'SUMA' ? 'border-emerald-200/70 bg-emerald-50 text-emerald-700 dark:border-emerald-400/15 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-red-200/70 bg-red-50 text-red-700 dark:border-red-400/15 dark:bg-red-500/10 dark:text-red-300'}`}>
                      {m.accion === 'SUMA' ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
                      {m.accion}
                    </span>
                  </td>
                  <td className={`px-4 py-3 text-center font-mono font-bold ${m.accion === 'SUMA' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'}`}>
                    {m.accion === 'SUMA' ? '+' : '-'}{m.cantidad}
                  </td>
                  <td className="max-w-xs truncate px-4 py-3 text-xs text-gray-500 dark:text-zinc-500">{m.motivo ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!isLoading && meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-gray-200/80 p-4 dark:border-white/[0.07]">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => goToPage(page - 1)}>
              <ChevronLeft size={14} /> Anterior
            </Button>
            <span className="text-xs text-gray-500 dark:text-zinc-400">{meta.from}–{meta.to} de {meta.total}</span>
            <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => goToPage(page + 1)}>
              Siguiente <ChevronRight size={14} />
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}
