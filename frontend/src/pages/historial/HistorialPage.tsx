import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { historialApi } from '../../services/historial.api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { ChevronLeft, ChevronRight, Eye, Download } from 'lucide-react'
import { api } from '../../services/api'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

function diferenciaClass(val: number) {
  if (val === 0) return 'bg-gray-100 text-gray-600'
  return val < 0 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'
}

const ESTADOS = ['', 'borrador', 'enviado', 'editado', 'aprobado']

export function HistorialPage() {
  const { usuario } = useAuth()

  const [filters, setFilters] = useState({
    fecha_desde: '',
    fecha_hasta: '',
    tienda: '',
    agente_id: '',
    estado: '',
  })
  const [page, setPage] = useState(1)
  const [applied, setApplied] = useState({ ...filters, page: 1 })

  const { data, isLoading } = useQuery({
    queryKey: ['historial', applied],
    queryFn: () =>
      historialApi.listar({
        ...applied,
        page: applied.page,
        per_page: 15,
        agente_id: applied.agente_id ? Number(applied.agente_id) : undefined,
        tienda: applied.tienda || undefined,
        estado: applied.estado || undefined,
        fecha_desde: applied.fecha_desde || undefined,
        fecha_hasta: applied.fecha_hasta || undefined,
      }),
  })

  const reportes = data?.data ?? []
  const meta = data

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

  return (
    <div className="space-y-6">
      <PageHeader
        title="Historial Completo de Reportes"
        description="Audita reportes, diferencias y estados con trazabilidad por período."
        actions={<Button
          variant="outline"
          size="sm"
          onClick={() => {
            const params = new URLSearchParams()
            if (applied.fecha_desde) params.set('fecha_desde', applied.fecha_desde)
            if (applied.fecha_hasta) params.set('fecha_hasta', applied.fecha_hasta)
            if (applied.tienda) params.set('tienda', applied.tienda)
            if (applied.agente_id) params.set('agente_id', String(applied.agente_id))
            if (applied.estado) params.set('estado', applied.estado)
            const token = localStorage.getItem('auth_token')
            const base = (api.defaults.baseURL ?? '').replace(/\/$/, '')
            const url = `${base}/v1/historial/exportar?${params.toString()}`
            const a = document.createElement('a')
            a.href = url
            a.setAttribute('data-auth', token ?? '')
            // Open in new tab — the browser will download the CSV
            fetch(url, { headers: { Authorization: `Bearer ${token}` } })
              .then(r => r.blob())
              .then(blob => {
                const burl = URL.createObjectURL(blob)
                const link = document.createElement('a')
                link.href = burl
                link.download = `historial_${new Date().toISOString().slice(0, 10)}.csv`
                link.click()
                URL.revokeObjectURL(burl)
              })
          }}
        >
          <Download size={14} /> Exportar CSV
        </Button>}
      />

      <ListToolbar description="Combina fechas, tienda, agente y estado para localizar reportes específicos.">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Desde</label>
            <input
              type="date"
              value={filters.fecha_desde}
              onChange={(e) => setFilters((f) => ({ ...f, fecha_desde: e.target.value }))}
              className="h-9 w-40 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
            />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Hasta</label>
            <input
              type="date"
              value={filters.fecha_hasta}
              onChange={(e) => setFilters((f) => ({ ...f, fecha_hasta: e.target.value }))}
              className="h-9 w-40 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
            />
          </div>
          {usuario?.rol === 'admin' && (
            <>
              <div>
                <label className="block text-xs text-gray-500 mb-1">Tienda</label>
                <input
                  type="text"
                  placeholder="Todas"
                  value={filters.tienda}
                  onChange={(e) => setFilters((f) => ({ ...f, tienda: e.target.value }))}
                  className="h-9 w-32 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
                />
              </div>
              <div>
                <label className="block text-xs text-gray-500 mb-1">ID Agente</label>
                <input
                  type="number"
                  placeholder="Todos"
                  value={filters.agente_id}
                  onChange={(e) => setFilters((f) => ({ ...f, agente_id: e.target.value }))}
                  className="h-9 w-28 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
                />
              </div>
            </>
          )}
          <div>
            <label className="block text-xs text-gray-500 mb-1">Estado</label>
            <select
              value={filters.estado}
              onChange={(e) => setFilters((f) => ({ ...f, estado: e.target.value }))}
              className="h-9 w-36 rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm shadow-sm dark:border-white/10 dark:bg-zinc-950/65 dark:text-zinc-100"
            >
              {ESTADOS.map((e) => (
                <option key={e} value={e}>
                  {e === '' ? 'Todos' : e}
                </option>
              ))}
            </select>
          </div>
          <Button onClick={applyFilters}>Buscar</Button>
          <Button variant="outline" onClick={resetFilters}>Limpiar</Button>
      </ListToolbar>

      <div className="relative overflow-hidden rounded-xl border border-gray-200/80 bg-white/80 shadow-[0_14px_35px_-24px_rgba(15,23,42,0.45)] backdrop-blur-xl dark:border-white/[0.08] dark:bg-zinc-900/65 dark:shadow-[0_20px_45px_-28px_rgba(0,0,0,0.95)]">
        <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/70 via-amber-400/45 to-transparent" />
        <div className="flex items-center justify-between border-b border-gray-200/80 p-4 dark:border-white/[0.07]">
          <h2 className="font-semibold text-gray-900 text-sm">
            {isLoading ? 'Cargando...' : `${meta?.total ?? 0} registros encontrados`}
          </h2>
          <span className="text-xs text-gray-400">
            Página {meta?.current_page ?? 1} de {meta?.last_page ?? 1}
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 bg-gray-50/80 dark:border-white/[0.07] dark:bg-zinc-800/50">
                {['ID', 'Fecha', 'Agente / Tienda', 'Total', 'F. Esperado', 'F. Entregado', 'Diferencia', 'Estado', ''].map((h) => (
                  <th
                    key={h}
                    className={`px-4 py-3 text-[0.68rem] font-semibold uppercase tracking-[0.06em] text-gray-500 ${['Total', 'F. Esperado', 'F. Entregado', 'Diferencia'].includes(h) ? 'text-right' : 'text-left'}`}
                  >
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr>
                  <td colSpan={9} className="px-4 py-10 text-center text-gray-400 text-sm">
                    Cargando...
                  </td>
                </tr>
              )}
              {!isLoading && reportes.length === 0 && (
                <tr>
                  <td colSpan={9} className="px-4 py-10 text-center text-gray-400 text-sm">
                    Sin resultados para los filtros aplicados
                  </td>
                </tr>
              )}
              {reportes.map((r) => {
                const dif = Number(r.diferencia)
                const isSolicitado = r.estado_edicion === 'SOLICITADO'
                const rowCls = isSolicitado
                  ? 'border-b border-yellow-300 bg-yellow-50 animate-pulse'
                  : dif < 0
                  ? 'border-b border-red-100 bg-red-50/40'
                  : 'border-b border-gray-100 transition-colors hover:bg-amber-50/40 dark:border-white/[0.05] dark:hover:bg-amber-400/[0.04]'

                return (
                  <tr key={r.id} className={rowCls}>
                    <td className="px-4 py-3 font-mono text-gray-600 text-xs">#{String(r.id).padStart(4, '0')}</td>
                    <td className="px-4 py-3 text-gray-700">
                      {new Date(r.fecha + 'T00:00:00').toLocaleDateString('es-PE')}
                    </td>
                    <td className="px-4 py-3">
                      <div className="font-medium text-gray-800">{(r as any).agente_nombre ?? '—'}</div>
                      <div className="text-xs text-gray-400">{r.tienda_id}</div>
                    </td>
                    <td className="px-4 py-3 text-right font-mono text-gray-800">{fmt(r.total_calculado)}</td>
                    <td className="px-4 py-3 text-right font-mono text-gray-700">{fmt(r.efectivo_esperado)}</td>
                    <td className="px-4 py-3 text-right font-mono text-gray-800">{fmt(r.efectivo_entregado)}</td>
                    <td className="px-4 py-3 text-right">
                      <span className={`inline-block px-2 py-0.5 rounded-md text-xs font-bold ${diferenciaClass(dif)}`}>
                        {dif > 0 ? '+' : ''}{fmt(dif)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <EstadoBadge estado={r.estado} estadoEdicion={r.estado_edicion} />
                    </td>
                    <td className="px-4 py-3">
                      <Link
                        to={`/reportes/${r.id}`}
                        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-indigo-600 transition-colors hover:bg-indigo-50 hover:text-indigo-800 dark:text-indigo-400 dark:hover:bg-indigo-400/10"
                      >
                        <Eye size={13} /> Ver
                      </Link>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {!isLoading && meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-gray-200/80 p-4 dark:border-white/[0.07]">
            <Button
              variant="outline"
              size="sm"
              disabled={page <= 1}
              onClick={() => goToPage(page - 1)}
            >
              <ChevronLeft size={14} /> Anterior
            </Button>
            <span className="text-xs text-gray-500">
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
    return <span className="inline-block px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-700 font-medium">Ed. solicitada</span>
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
