import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
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
      <h1 className="text-xl font-semibold text-gray-900">Bitácora de Stock</h1>

      {data?.warning && (
        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-800">
          ⚠️ {data.warning}
        </div>
      )}

      {/* KPI Cards */}
      {kpis && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <div className="flex items-center gap-2 mb-1">
              <Activity size={15} className="text-blue-500" />
              <p className="text-xs text-gray-500">Total Movimientos</p>
            </div>
            <p className="text-xl font-bold text-blue-700">{kpis.total_mov.toLocaleString()}</p>
          </div>
          <div className="bg-white rounded-xl border border-green-200 p-4">
            <div className="flex items-center gap-2 mb-1">
              <TrendingUp size={15} className="text-green-500" />
              <p className="text-xs text-gray-500">Entradas (+)</p>
            </div>
            <p className="text-xl font-bold text-green-700">+{kpis.total_entradas.toLocaleString()}</p>
          </div>
          <div className="bg-white rounded-xl border border-red-200 p-4">
            <div className="flex items-center gap-2 mb-1">
              <TrendingDown size={15} className="text-red-500" />
              <p className="text-xs text-gray-500">Salidas (-)</p>
            </div>
            <p className="text-xl font-bold text-red-700">-{kpis.total_salidas.toLocaleString()}</p>
          </div>
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <div className="flex items-center gap-2 mb-1">
              <Store size={15} className="text-slate-500" />
              <p className="text-xs text-gray-500">Balance Neto</p>
            </div>
            <p className={`text-xl font-bold ${(kpis.total_entradas - kpis.total_salidas) >= 0 ? 'text-green-700' : 'text-red-600'}`}>
              {kpis.total_entradas - kpis.total_salidas >= 0 ? '+' : ''}
              {(kpis.total_entradas - kpis.total_salidas).toLocaleString()}
            </p>
          </div>
        </div>
      )}

      {/* Filters */}
      <div className="bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap items-end gap-3">
        <div>
          <label className="block text-xs text-gray-500 mb-1">Desde</label>
          <input type="date" value={filters.fecha_desde}
            onChange={e => setFilters(f => ({ ...f, fecha_desde: e.target.value }))}
            className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label className="block text-xs text-gray-500 mb-1">Hasta</label>
          <input type="date" value={filters.fecha_hasta}
            onChange={e => setFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
            className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        {usuario?.rol === 'admin' && (
          <div>
            <label className="block text-xs text-gray-500 mb-1">Tienda</label>
            <input type="text" placeholder="Todas" value={filters.tienda}
              onChange={e => setFilters(f => ({ ...f, tienda: e.target.value }))}
              className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-28" />
          </div>
        )}
        <div>
          <label className="block text-xs text-gray-500 mb-1">Acción</label>
          <select value={filters.accion} onChange={e => setFilters(f => ({ ...f, accion: e.target.value }))}
            className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">Todos</option>
            <option value="SUMA">Entradas (SUMA)</option>
            <option value="RESTA">Salidas (RESTA)</option>
          </select>
        </div>
        <Button onClick={applyFilters}>Filtrar</Button>
        <Button variant="outline" onClick={resetFilters}>Limpiar</Button>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div className="p-4 border-b border-gray-200 flex items-center justify-between">
          <h2 className="font-semibold text-gray-900 text-sm">
            {isLoading ? 'Cargando...' : `${meta?.total ?? 0} movimientos`}
          </h2>
          <span className="text-xs text-gray-400">Pág. {meta?.current_page ?? 1}/{meta?.last_page ?? 1}</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200">
                {['Fecha/Hora', 'Tienda', 'Agente', 'Producto', 'Tipo', 'Acción', 'Cant.', 'Motivo'].map(h => (
                  <th key={h} className={`px-4 py-3 text-xs font-semibold text-gray-500 text-left ${h === 'Cant.' ? 'text-center' : ''}`}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-400">Cargando...</td></tr>}
              {!isLoading && movimientos.length === 0 && (
                <tr><td colSpan={8} className="px-4 py-10 text-center text-gray-400">Sin movimientos en el período seleccionado</td></tr>
              )}
              {movimientos.map(m => (
                <tr key={m.id} className={`border-b ${m.accion === 'SUMA' ? 'border-green-100 bg-green-50/20 hover:bg-green-50/40' : 'border-red-100 bg-red-50/20 hover:bg-red-50/40'}`}>
                  <td className="px-4 py-3 text-xs text-gray-600 font-mono">
                    {new Date(m.fecha_hora).toLocaleString('es-PE')}
                  </td>
                  <td className="px-4 py-3">
                    <span className="text-xs bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded font-mono">{m.tienda_id}</span>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-700">{m.agente_nombre}</td>
                  <td className="px-4 py-3 text-sm text-gray-800 font-medium">{m.producto_nombre}</td>
                  <td className="px-4 py-3">
                    <span className="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded uppercase">{m.producto_tipo}</span>
                  </td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex items-center gap-1 text-xs font-bold px-2 py-0.5 rounded-full ${m.accion === 'SUMA' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                      {m.accion === 'SUMA' ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
                      {m.accion}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-center font-bold text-gray-800">
                    {m.accion === 'SUMA' ? '+' : '-'}{m.cantidad}
                  </td>
                  <td className="px-4 py-3 text-xs text-gray-500 max-w-xs truncate">{m.motivo ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!isLoading && meta && meta.last_page > 1 && (
          <div className="p-4 border-t border-gray-200 flex items-center justify-between">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => goToPage(page - 1)}>
              <ChevronLeft size={14} /> Anterior
            </Button>
            <span className="text-xs text-gray-500">{meta.from}–{meta.to} de {meta.total}</span>
            <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => goToPage(page + 1)}>
              Siguiente <ChevronRight size={14} />
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}
