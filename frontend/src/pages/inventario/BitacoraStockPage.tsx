import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'
import { esAdminOGerente } from '../../utils/roles'
import { Button } from '../../components/ui/button'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import { useTiendasSelect } from '../../hooks/useTiendasSelect'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'
import { KpiCard } from '../../components/ui/KpiCard'
import { CaretLeft as ChevronLeft, CaretRight as ChevronRight, ChartLineUp as TrendingUp, ChartLineDown as TrendingDown, Pulse as Activity, Storefront as Store, MagnifyingGlass as Search, DownloadSimple as Download, BookOpen } from '@phosphor-icons/react'

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
  tienda_id: number
  tienda_codigo: string
  tienda_nombre: string
  accion: 'SUMA' | 'RESTA'
  cantidad: number
  motivo: string | null
  observacion: string | null
  imei_serial: string | null
  precio_en_ese_momento: string | number | null
  dni_autorizacion: string | null
  agente_nombre: string
  producto_nombre: string
  producto_tipo: string
}

interface AgenteOption {
  id: number
  nombres: string
  dni: string
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

const ACCIONES = [
  { value: '', label: 'Todos', tone: 'indigo' as const },
  { value: 'SUMA', label: 'Entradas', tone: 'success' as const },
  { value: 'RESTA', label: 'Salidas', tone: 'danger' as const },
]

const CATEGORIAS = ['CHIP', 'EQUIPO', 'ACCESORIO']

export function BitacoraStockPage() {
  const { usuario } = useAuth()
  const { tiendas } = useTiendasSelect()
  const [exportando, setExportando] = useState(false)

  const [filters, setFilters] = useState({
    fecha_desde: '',
    fecha_hasta: '',
    tienda: '',
    accion: '',
    agente_id: '',
    categoria: '',
    q: '',
  })
  const [page, setPage] = useState(1)
  const [applied, setApplied] = useState({ ...filters, page: 1 })

  const { data: agentesData } = useQuery({
    queryKey: ['agentes-para-bitacora'],
    queryFn: () => api.get<{ data: AgenteOption[] }>('/v1/agentes', { params: { per_page: 500 } }).then(r => r.data),
    // Catálogo para <select> de filtros, cambia poco (OPT-14).
    staleTime: 10 * 60_000,
  })
  const agentes = agentesData?.data ?? []

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
          agente_id: applied.agente_id || undefined,
          categoria: applied.categoria || undefined,
          q: applied.q || undefined,
        },
      }).then(r => r.data),
  })

  const kpis = data?.kpis
  const movimientos = data?.movimientos?.data ?? []
  const meta = data?.movimientos
  const balance = (kpis?.total_entradas ?? 0) - (kpis?.total_salidas ?? 0)

  function applyFilters() { setPage(1); setApplied({ ...filters, page: 1 }) }
  function resetFilters() {
    const e = { fecha_desde: '', fecha_hasta: '', tienda: '', accion: '', agente_id: '', categoria: '', q: '' }
    setFilters(e); setPage(1); setApplied({ ...e, page: 1 })
  }
  function goToPage(p: number) { setPage(p); setApplied(a => ({ ...a, page: p })) }

  async function exportarExcel() {
    setExportando(true)
    try {
      const res = await api.get('/v1/bitacora-stock/exportar', {
        params: {
          fecha_desde: applied.fecha_desde || undefined,
          fecha_hasta: applied.fecha_hasta || undefined,
          tienda: applied.tienda || undefined,
          accion: applied.accion || undefined,
          agente_id: applied.agente_id || undefined,
          categoria: applied.categoria || undefined,
          q: applied.q || undefined,
        },
        responseType: 'blob',
      })
      const url = URL.createObjectURL(res.data as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `bitacora_stock_${new Date().toISOString().slice(0, 10)}.xlsx`
      a.click()
      URL.revokeObjectURL(url)
    } finally {
      setExportando(false)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={BookOpen}
        title="Bitácora de Stock"
        description="Trazabilidad de entradas, salidas y ajustes de inventario."
      />

      {data?.warning && (
        <div className="rounded-kyro-lg border border-kyro-warning/30 bg-kyro-warning/10 p-4 text-sm text-kyro-warning shadow-kyro-card">
          ⚠️ {data.warning}
        </div>
      )}

      {/* KPI Cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard title="Movimientos" value={kpis?.total_mov ?? (data ? '—' : undefined)} loading={isLoading} tone="indigo" icon={<Activity size={18} />} subtitle={kpis ? `${kpis.tiendas_afectadas} tiendas · ${kpis.agentes_involucrados} agentes` : undefined} />
        <KpiCard title="Entradas" value={kpis?.total_entradas ?? (data ? '—' : undefined)} loading={isLoading} tone="success" accent="var(--color-kyro-success)" icon={<TrendingUp size={18} />} />
        <KpiCard title="Salidas" value={kpis?.total_salidas ?? (data ? '—' : undefined)} loading={isLoading} tone="danger" accent="var(--color-kyro-danger)" icon={<TrendingDown size={18} />} />
        <KpiCard title="Balance neto" value={kpis ? balance : (data ? '—' : undefined)} loading={isLoading} tone={balance >= 0 ? 'success' : 'danger'} accent={balance >= 0 ? 'var(--color-kyro-success)' : 'var(--color-kyro-danger)'} icon={<Store size={18} />} />
      </div>

      {/* Filters */}
      <ListToolbar description="Acota el historial por fecha, tienda, agente, categoría o tipo de movimiento.">
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Desde</label>
          <Input type="date" value={filters.fecha_desde}
            onChange={e => setFilters(f => ({ ...f, fecha_desde: e.target.value }))}
            className="w-auto" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Hasta</label>
          <Input type="date" value={filters.fecha_hasta}
            onChange={e => setFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
            className="w-auto" />
        </div>
        {esAdminOGerente(usuario) && (
          <div>
            <label className="mb-1 block text-xs font-medium text-kyro-muted">Tienda</label>
            <Select value={filters.tienda} onChange={e => setFilters(f => ({ ...f, tienda: e.target.value }))} className="w-44">
              <option value="">Todas</option>
              {tiendas.map(t => <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>)}
            </Select>
          </div>
        )}
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Agente</label>
          <Select value={filters.agente_id} onChange={e => setFilters(f => ({ ...f, agente_id: e.target.value }))} className="w-44">
            <option value="">Todos</option>
            {agentes.map(a => (
              <option key={a.id} value={a.id}>{a.nombres}</option>
            ))}
          </Select>
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Categoría</label>
          <Select value={filters.categoria} onChange={e => setFilters(f => ({ ...f, categoria: e.target.value }))} className="w-32">
            <option value="">Todas</option>
            {CATEGORIAS.map(c => (
              <option key={c} value={c}>{c}</option>
            ))}
          </Select>
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Buscar</label>
          <div className="relative">
            <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kyro-subtle" />
            <Input
              type="text"
              placeholder="Producto, IMEI, agente, observación..."
              value={filters.q}
              onChange={e => setFilters(f => ({ ...f, q: e.target.value }))}
              onKeyDown={e => { if (e.key === 'Enter') applyFilters() }}
              className="w-56 pl-9"
            />
          </div>
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-kyro-muted">Acción</label>
          <SegmentedToggle
            ariaLabel="Filtrar bitacora por accion"
            size="sm"
            options={ACCIONES}
            value={filters.accion}
            onChange={(accion) => setFilters(f => ({ ...f, accion }))}
          />
        </div>
        <Button onClick={applyFilters}>Filtrar</Button>
        <Button variant="ghost" onClick={resetFilters}>Limpiar</Button>
        <Button variant="outline" onClick={exportarExcel} disabled={exportando}>
          <Download size={14} /> {exportando ? 'Exportando...' : 'Exportar Excel'}
        </Button>
      </ListToolbar>

      {/* Table */}
      <div className="kyro-card overflow-hidden rounded-[18px]">
        <div className="flex items-center justify-between border-b border-kyro-border p-4">
          <h2 className="text-sm font-semibold text-kyro-text">
            {isLoading ? 'Cargando...' : `${meta?.total ?? 0} movimientos`}
          </h2>
          <span className="text-xs text-kyro-subtle">Pág. {meta?.current_page ?? 1}/{meta?.last_page ?? 1}</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="kyro-table-head">
                {['Fecha/Hora', 'Tienda', 'Agente', 'Producto', 'Tipo', 'Acción', 'Cant.', 'IMEI/Serie', 'Precio', 'DNI Autoriz.', 'Motivo'].map(h => (
                  <th key={h} className={`px-4 py-3 text-left text-[0.68rem] font-semibold uppercase tracking-[0.08em] text-kyro-muted ${h === 'Cant.' ? 'text-center' : ''}`}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={11} className="px-4 py-10 text-center text-kyro-muted">Cargando...</td></tr>}
              {!isLoading && movimientos.length === 0 && (
                <tr><td colSpan={11} className="px-4 py-10 text-center text-kyro-muted">Sin movimientos en el período seleccionado</td></tr>
              )}
              {movimientos.map(m => (
                <tr key={m.id} className={`border-b transition-colors ${m.accion === 'SUMA' ? 'border-emerald-100 bg-emerald-50/20 hover:bg-emerald-50/50 dark:border-emerald-400/10 dark:bg-emerald-400/[0.015] dark:hover:bg-emerald-400/[0.04]' : 'border-red-100 bg-red-50/20 hover:bg-red-50/50 dark:border-red-400/10 dark:bg-red-400/[0.015] dark:hover:bg-red-400/[0.04]'}`}>
                  <td className="px-4 py-3 font-mono text-xs text-kyro-muted">
                    {new Date(m.fecha_hora).toLocaleString('es-PE')}
                  </td>
                  <td className="px-4 py-3">
                    <span className="rounded-md border border-kyro-border bg-kyro-elevated px-2 py-1 font-mono text-xs text-kyro-body">{m.tienda_codigo}</span>
                    {m.tienda_nombre && <span className="ml-1 text-[0.67rem] text-kyro-muted">{m.tienda_nombre}</span>}
                  </td>
                  <td className="px-4 py-3 text-sm text-kyro-body">{m.agente_nombre}</td>
                  <td className="px-4 py-3 text-sm font-medium text-kyro-text">{m.producto_nombre}</td>
                  <td className="px-4 py-3">
                    <span className="rounded-md border border-kyro-border bg-kyro-elevated px-2 py-1 text-[0.68rem] font-semibold uppercase tracking-wide text-kyro-muted">{m.producto_tipo}</span>
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
                  <td className="px-4 py-3 font-mono text-xs text-kyro-muted">{m.imei_serial ?? '—'}</td>
                  <td className="px-4 py-3 font-mono text-xs text-kyro-body">
                    {m.precio_en_ese_momento != null ? `S/ ${parseFloat(String(m.precio_en_ese_momento)).toFixed(2)}` : '—'}
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-kyro-muted">{m.dni_autorizacion ?? '—'}</td>
                  <td className="max-w-xs truncate px-4 py-3 text-xs text-kyro-subtle">{m.motivo ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!isLoading && meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-kyro-border p-4">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => goToPage(page - 1)}>
              <ChevronLeft size={14} /> Anterior
            </Button>
            <span className="text-xs text-kyro-subtle">{meta.from}–{meta.to} de {meta.total}</span>
            <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => goToPage(page + 1)}>
              Siguiente <ChevronRight size={14} />
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}
