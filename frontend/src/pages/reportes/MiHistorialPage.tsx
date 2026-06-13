import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../../services/api'
import type { PaginatedResponse } from '../../types/pagination'
import type { Reporte } from '../../types/reporte'
import { Button } from '../../components/ui/button'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { ChevronLeft, ChevronRight, Eye, Edit, PenLine } from 'lucide-react'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

function diferenciaClass(val: number) {
  if (val === 0) return 'bg-gray-100 text-gray-600'
  return val < 0 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'
}

const ESTADOS = ['', 'borrador', 'enviado', 'editado', 'aprobado']

// Modal solicitar edición
function ModalSolicitarEdicion({ reporteId, onClose, onSuccess }: { reporteId: number; onClose: () => void; onSuccess: () => void }) {
  const [motivo, setMotivo] = useState('')
  const qc = useQueryClient()

  const { mutate, isPending, error } = useMutation({
    mutationFn: () =>
      api.post(`/v1/reportes/${reporteId}/solicitar-edicion`, { motivo_edicion: motivo }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['mis-reportes'] })
      onSuccess()
    },
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
      <div className="premium-surface relative w-full max-w-md space-y-4 p-6">
        <h3 className="font-semibold text-gray-900">Solicitar Edición de Reporte #{String(reporteId).padStart(4, '0')}</h3>
        <p className="text-sm text-gray-500">Explica brevemente el motivo por el que necesitas editar este reporte.</p>
        <textarea
          value={motivo}
          onChange={(e) => setMotivo(e.target.value)}
          placeholder="Motivo de la solicitud..."
          rows={4}
          className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
        />
        {error && (
          <p className="text-xs text-red-600">{(error as any)?.response?.data?.error ?? 'Error al enviar solicitud'}</p>
        )}
        <div className="flex gap-2 justify-end">
          <Button variant="outline" onClick={onClose}>Cancelar</Button>
          <Button disabled={!motivo.trim() || isPending} onClick={() => mutate()}>
            {isPending ? 'Enviando...' : 'Enviar Solicitud'}
          </Button>
        </div>
      </div>
    </div>
  )
}

export function MiHistorialPage() {
  const [filters, setFilters] = useState({ fecha_desde: '', fecha_hasta: '', estado: '' })
  const [page, setPage] = useState(1)
  const [applied, setApplied] = useState({ ...filters, page: 1 })
  const [solicitandoId, setSolicitandoId] = useState<number | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['mis-reportes', applied],
    queryFn: () =>
      api.get<PaginatedResponse<Reporte>>('/v1/reportes/mis-reportes', {
        params: {
          page: applied.page,
          per_page: 15,
          fecha_desde: applied.fecha_desde || undefined,
          fecha_hasta: applied.fecha_hasta || undefined,
          estado: applied.estado || undefined,
        },
      }).then(r => r.data),
  })

  const reportes = data?.data ?? []
  const meta = data

  function applyFilters() {
    setPage(1)
    setApplied({ ...filters, page: 1 })
  }

  function goToPage(p: number) {
    setPage(p)
    setApplied((a) => ({ ...a, page: p }))
  }

  // Personal KPIs
  const totalVendido      = reportes.reduce((s, r) => s + Number(r.total_calculado), 0)
  const totalDiferencia   = reportes.reduce((s, r) => s + Number(r.diferencia), 0)
  const reportesConDif    = reportes.filter(r => Number(r.diferencia) !== 0).length

  return (
    <div className="space-y-6">
      <PageHeader title="Mi Historial Personal" subtitle="Consulta tus cierres, diferencias y solicitudes de edición" accent="#6366f1" />

      {/* KPIs personales de la página actual */}
      {!isLoading && reportes.length > 0 && (
        <div className="grid grid-cols-3 gap-4">
          <div className="premium-kpi p-4">
            <p className="text-xs text-gray-500 mb-1">Total vendido (página)</p>
            <p className="text-lg font-bold text-blue-700">{fmt(totalVendido)}</p>
          </div>
          <div className="premium-kpi p-4">
            <p className="text-xs text-gray-500 mb-1">Diferencia acumulada</p>
            <p className={`text-lg font-bold ${totalDiferencia < 0 ? 'text-red-600' : totalDiferencia > 0 ? 'text-yellow-600' : 'text-gray-600'}`}>
              {totalDiferencia > 0 ? '+' : ''}{fmt(totalDiferencia)}
            </p>
          </div>
          <div className="premium-kpi p-4">
            <p className="text-xs text-gray-500 mb-1">Reportes con descuadre</p>
            <p className={`text-lg font-bold ${reportesConDif > 0 ? 'text-red-600' : 'text-green-700'}`}>
              {reportesConDif} de {reportes.length}
            </p>
          </div>
        </div>
      )}

      {/* Filters */}
      <ListToolbar description="Busca reportes por periodo y estado">
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
        <div>
          <label className="block text-xs text-gray-500 mb-1">Estado</label>
          <select value={filters.estado} onChange={e => setFilters(f => ({ ...f, estado: e.target.value }))}
            className="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            {ESTADOS.map(e => <option key={e} value={e}>{e === '' ? 'Todos' : e}</option>)}
          </select>
        </div>
        <Button onClick={applyFilters}>Buscar</Button>
        <Button variant="outline" onClick={() => { const r = { fecha_desde: '', fecha_hasta: '', estado: '' }; setFilters(r); setApplied({ ...r, page: 1 }) }}>Limpiar</Button>
      </ListToolbar>

      {/* Table */}
      <div className="premium-surface">
        <div className="p-4 border-b border-gray-200 flex items-center justify-between">
          <h2 className="font-semibold text-gray-900 text-sm">
            {isLoading ? 'Cargando...' : `${meta?.total ?? 0} registros`}
          </h2>
          <span className="text-xs text-gray-400">Pág. {meta?.current_page ?? 1}/{meta?.last_page ?? 1}</span>
        </div>
        <div className="overflow-x-auto">
          <table className="premium-table w-full text-sm">
            <thead>
              <tr>
                {['ID', 'Fecha', 'Total', 'F. Entregado', 'Diferencia', 'Estado', 'Acciones'].map(h => (
                  <th key={h} className={`px-4 py-3 text-xs font-semibold text-gray-500 ${['Total', 'F. Entregado', 'Diferencia'].includes(h) ? 'text-right' : 'text-left'}`}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-400">Cargando...</td></tr>}
              {!isLoading && reportes.length === 0 && (
                <tr><td colSpan={7} className="px-4 py-10 text-center text-gray-400">Sin reportes</td></tr>
              )}
              {reportes.map(r => {
                const dif = Number(r.diferencia)
                const puedeEditar = r.estado !== 'borrador' && r.estado !== 'aprobado' && r.estado_edicion === 'CERRADO'
                return (
                  <tr key={r.id} className={`border-b ${dif < 0 ? 'border-red-100 bg-red-50/30' : 'border-gray-100 hover:bg-gray-50/60'}`}>
                    <td className="px-4 py-3 font-mono text-xs text-gray-600">#{String(r.id).padStart(4, '0')}</td>
                    <td className="px-4 py-3 text-gray-700">{new Date(r.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</td>
                    <td className="px-4 py-3 text-right font-mono text-gray-800">{fmt(r.total_calculado)}</td>
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
                      <div className="flex items-center gap-2">
                        <Link to={`/reportes/${r.id}`} className="inline-flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800">
                          <Eye size={13} /> Ver
                        </Link>
                        {puedeEditar && (
                          <button onClick={() => setSolicitandoId(r.id)}
                            className="inline-flex items-center gap-1 text-xs text-orange-600 hover:text-orange-800">
                            <Edit size={13} /> Solicitar edición
                          </button>
                        )}
                        {r.estado_edicion === 'SOLICITADO' && (
                          <span className="text-xs text-yellow-600 font-medium">Pendiente aprobación</span>
                        )}
                        {r.estado_edicion === 'APROBADO' && (
                          <Link
                            to={`/reportes/${r.id}/editar`}
                            className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                          >
                            <PenLine size={13} /> Editar ahora
                          </Link>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
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

      {solicitandoId !== null && (
        <ModalSolicitarEdicion
          reporteId={solicitandoId}
          onClose={() => setSolicitandoId(null)}
          onSuccess={() => setSolicitandoId(null)}
        />
      )}
    </div>
  )
}

function EstadoBadge({ estado, estadoEdicion }: { estado: string; estadoEdicion?: string }) {
  if (estadoEdicion === 'SOLICITADO') {
    return <span className="inline-block px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-700 font-medium">Ed. solicitada</span>
  }
  const map: Record<string, string> = {
    borrador: 'bg-gray-100 text-gray-600', enviado: 'bg-blue-100 text-blue-700',
    editado: 'bg-orange-100 text-orange-700', aprobado: 'bg-green-100 text-green-700',
  }
  return <span className={`inline-block px-2 py-0.5 text-xs rounded-full font-medium ${map[estado] ?? 'bg-gray-100 text-gray-500'}`}>{estado}</span>
}
