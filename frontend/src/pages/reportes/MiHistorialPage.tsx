import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../../services/api'
import type { PaginatedResponse } from '../../types/pagination'
import type { Reporte } from '../../types/reporte'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { ChevronLeft, ChevronRight, Eye, Edit, PenLine } from 'lucide-react'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

function diferenciaClass(val: number) {
  if (val === 0) return 'bg-kyro-elevated text-kyro-muted'
  return val < 0 ? 'bg-kyro-danger/15 text-kyro-danger' : 'bg-kyro-warning/15 text-kyro-warning'
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
      <div className="kyro-card relative w-full max-w-md space-y-4 p-6">
        <h3 className="font-semibold text-kyro-text">Solicitar Edición de Reporte #{String(reporteId).padStart(4, '0')}</h3>
        <p className="text-sm text-kyro-muted">Explica brevemente el motivo por el que necesitas editar este reporte.</p>
        <textarea
          value={motivo}
          onChange={(e) => setMotivo(e.target.value)}
          placeholder="Motivo de la solicitud..."
          rows={4}
          className="kyro-input w-full resize-none"
        />
        {error && (
          <p className="text-xs text-kyro-danger">{(error as any)?.response?.data?.error ?? 'Error al enviar solicitud'}</p>
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

interface Tardanza {
  id: number
  fecha: string
  hora_ingreso: string | null
  minutos_tardanza?: number
}
interface MisTardanzas {
  success: boolean
  agente?: { id: number; nombres: string }
  tardanzas: Tardanza[]
  salvavidas_usado: boolean
  mensaje?: string
}

// Salvavidas: el agente recupera una tardanza de la semana (paridad legacy mi_historial).
function SalvavidasPanel() {
  const [dni, setDni]       = useState('')
  const [consultado, setConsultado] = useState('')
  const [msg, setMsg]       = useState<string | null>(null)

  const { data, refetch, isFetching } = useQuery({
    queryKey: ['mis-tardanzas', consultado],
    queryFn: () => api.get<MisTardanzas>('/v1/asistencias/mis-tardanzas', { params: { dni: consultado } }).then(r => r.data),
    enabled: consultado.length === 8,
  })

  const recuperar = useMutation({
    mutationFn: (t: Tardanza) => api.post('/v1/asistencias/salvavidas', {
      asistencia_id: t.id, minutos: t.minutos_tardanza ?? 0, dni: consultado,
    }).then(r => r.data),
    onSuccess: (r: { success: boolean; mensaje?: string }) => { setMsg(r.mensaje ?? (r.success ? 'Tardanza recuperada.' : 'No se pudo recuperar.')); refetch() },
    onError: (e: any) => setMsg(e?.response?.data?.mensaje ?? 'Error al recuperar la tardanza.'),
  })

  const tardanzas = data?.tardanzas ?? []
  const usado     = data?.salvavidas_usado ?? false

  return (
    <section className="kyro-card border-l-4 border-l-kyro-gold p-5">
      <h3 className="mb-1 text-sm font-semibold text-kyro-text">🛟 Salvavidas — recuperar tardanza</h3>
      <p className="mb-3 text-xs text-kyro-muted">Ingresa tu DNI para ver tus tardanzas de esta semana. Solo 1 recuperación por semana.</p>
      <div className="flex flex-wrap items-end gap-2">
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">DNI</label>
          <Input type="text" inputMode="numeric" maxLength={8} value={dni}
            onChange={e => setDni(e.target.value.replace(/\D/g, ''))} placeholder="8 dígitos" className="w-36" />
        </div>
        <Button size="sm" disabled={dni.length !== 8 || isFetching} onClick={() => { setMsg(null); setConsultado(dni) }}>
          {isFetching ? 'Consultando...' : 'Consultar'}
        </Button>
      </div>

      {data?.agente && (
        <p className="mt-3 text-xs text-kyro-muted">Agente: <span className="font-medium text-kyro-text">{data.agente.nombres}</span></p>
      )}
      {msg && <p className="mt-2 rounded-kyro bg-kyro-elevated px-3 py-2 text-xs text-kyro-body">{msg}</p>}

      {consultado.length === 8 && (
        <div className="mt-3 divide-y divide-kyro-border rounded-kyro border border-kyro-border">
          {tardanzas.length === 0 && <p className="px-3 py-4 text-center text-xs text-kyro-muted">Sin tardanzas esta semana 🎉</p>}
          {tardanzas.map(t => (
            <div key={t.id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
              <span className="text-xs text-kyro-muted">{new Date(t.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</span>
              <span className="text-xs text-kyro-body">Ingreso {t.hora_ingreso?.slice(0, 5) ?? '—'}</span>
              <span className="font-mono text-xs font-bold text-kyro-warning">{t.minutos_tardanza ?? 0} min</span>
              <Button size="sm" variant="outline" disabled={usado || recuperar.isPending}
                onClick={() => { setMsg(null); recuperar.mutate(t) }}>
                {usado ? 'Ya usado' : 'Recuperar'}
              </Button>
            </div>
          ))}
        </div>
      )}
    </section>
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
      <PageHeader title="Mi Historial Personal" subtitle="Consulta tus cierres, diferencias y solicitudes de edición" />

      <SalvavidasPanel />

      {/* KPIs personales de la página actual */}
      {!isLoading && reportes.length > 0 && (
        <div className="grid grid-cols-3 gap-4">
          <div className="kyro-card border-l-4 border-l-kpi-total p-4">
            <p className="text-xs text-kyro-muted mb-1">Total vendido (página)</p>
            <p className="text-lg font-bold text-kyro-text">{fmt(totalVendido)}</p>
          </div>
          <div className="kyro-card border-l-4 border-l-kpi-neutral p-4">
            <p className="text-xs text-kyro-muted mb-1">Diferencia acumulada</p>
            <p className={`text-lg font-bold ${totalDiferencia < 0 ? 'text-kyro-danger' : totalDiferencia > 0 ? 'text-kyro-warning' : 'text-kyro-muted'}`}>
              {totalDiferencia > 0 ? '+' : ''}{fmt(totalDiferencia)}
            </p>
          </div>
          <div className="kyro-card border-l-4 border-l-kpi-declarado p-4">
            <p className="text-xs text-kyro-muted mb-1">Reportes con descuadre</p>
            <p className={`text-lg font-bold ${reportesConDif > 0 ? 'text-kyro-danger' : 'text-kyro-success'}`}>
              {reportesConDif} de {reportes.length}
            </p>
          </div>
        </div>
      )}

      {/* Filters */}
      <ListToolbar description="Busca reportes por periodo y estado">
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Desde</label>
          <Input type="date" value={filters.fecha_desde}
            onChange={e => setFilters(f => ({ ...f, fecha_desde: e.target.value }))}
          />
        </div>
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Hasta</label>
          <Input type="date" value={filters.fecha_hasta}
            onChange={e => setFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
          />
        </div>
        <div>
          <label className="block text-xs text-kyro-muted mb-1">Estado</label>
          <Select value={filters.estado} onChange={e => setFilters(f => ({ ...f, estado: e.target.value }))}>
            {ESTADOS.map(e => <option key={e} value={e}>{e === '' ? 'Todos' : e}</option>)}
          </Select>
        </div>
        <Button onClick={applyFilters}>Buscar</Button>
        <Button variant="outline" onClick={() => { const r = { fecha_desde: '', fecha_hasta: '', estado: '' }; setFilters(r); setApplied({ ...r, page: 1 }) }}>Limpiar</Button>
      </ListToolbar>

      {/* Table */}
      <div className="kyro-card overflow-hidden">
        <div className="p-4 border-b border-kyro-border flex items-center justify-between">
          <h2 className="font-semibold text-kyro-text text-sm">
            {isLoading ? 'Cargando...' : `${meta?.total ?? 0} registros`}
          </h2>
          <span className="text-xs text-kyro-subtle">Pág. {meta?.current_page ?? 1}/{meta?.last_page ?? 1}</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="kyro-table-head">
              <tr>
                {['ID', 'Fecha', 'Total', 'F. Entregado', 'Diferencia', 'Estado', 'Acciones'].map(h => (
                  <th key={h} className={`px-4 py-3 text-xs font-semibold text-kyro-muted ${['Total', 'F. Entregado', 'Diferencia'].includes(h) ? 'text-right' : 'text-left'}`}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={7} className="px-4 py-10 text-center text-kyro-subtle">Cargando...</td></tr>}
              {!isLoading && reportes.length === 0 && (
                <tr><td colSpan={7} className="px-4 py-10 text-center text-kyro-subtle">Sin reportes</td></tr>
              )}
              {reportes.map(r => {
                const dif = Number(r.diferencia)
                const puedeEditar = r.estado !== 'borrador' && r.estado !== 'aprobado' && r.estado_edicion === 'CERRADO'
                return (
                  <tr key={r.id} className={`border-b border-kyro-border ${dif < 0 ? 'bg-kyro-danger/5' : 'hover:bg-kyro-elevated/60'}`}>
                    <td className="px-4 py-3 font-mono text-xs text-kyro-muted">#{String(r.id).padStart(4, '0')}</td>
                    <td className="px-4 py-3 text-kyro-body">{new Date(r.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</td>
                    <td className="px-4 py-3 text-right font-mono text-kyro-text">{fmt(r.total_calculado)}</td>
                    <td className="px-4 py-3 text-right font-mono text-kyro-text">{fmt(r.efectivo_entregado)}</td>
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
                        <Link to={`/reportes/${r.id}`} className="inline-flex items-center gap-1 text-xs text-kyro-info hover:text-kyro-gold">
                          <Eye size={13} /> Ver
                        </Link>
                        {puedeEditar && (
                          <Button size="sm" variant="ghost" onClick={() => setSolicitandoId(r.id)}
                            className="h-auto px-0 text-kyro-warning hover:text-kyro-gold">
                            <Edit size={13} /> Solicitar edición
                          </Button>
                        )}
                        {r.estado_edicion === 'SOLICITADO' && (
                          <span className="text-xs text-kyro-warning font-medium">Pendiente aprobación</span>
                        )}
                        {r.estado_edicion === 'APROBADO' && (
                          <Link
                            to={`/reportes/${r.id}/editar`}
                            className="inline-flex items-center gap-1 text-xs font-semibold text-kyro-info hover:text-kyro-gold"
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
          <div className="p-4 border-t border-kyro-border flex items-center justify-between">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => goToPage(page - 1)}>
              <ChevronLeft size={14} /> Anterior
            </Button>
            <span className="text-xs text-kyro-muted">{meta.from}–{meta.to} de {meta.total}</span>
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
    return <span className="inline-block px-2 py-0.5 text-xs rounded-full bg-kyro-warning/15 text-kyro-warning font-medium">Ed. solicitada</span>
  }
  const map: Record<string, string> = {
    borrador: 'bg-kyro-elevated text-kyro-muted', enviado: 'bg-kyro-info/15 text-kyro-info',
    editado: 'bg-kyro-warning/15 text-kyro-warning', aprobado: 'bg-kyro-success/15 text-kyro-success',
  }
  return <span className={`inline-block px-2 py-0.5 text-xs rounded-full font-medium ${map[estado] ?? 'bg-kyro-elevated text-kyro-muted'}`}>{estado}</span>
}
