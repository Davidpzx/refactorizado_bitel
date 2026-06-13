import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { Input } from '../../components/ui/input'
import { Download, AlertTriangle, Clock, UserCheck, UserX, AlertCircle } from 'lucide-react'

const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado']

interface AsistenciaRow {
  id: number
  agente_id: number
  nombres: string
  tienda_base: string
  fecha: string
  hora_ingreso: string | null
  hora_salida: string | null
  metodo_marcacion: string
  observacion: string | null
  requiere_revision: number
  dia_descanso: string | null
  salida_oficial: string | null
}

interface Kpis {
  presentes: number
  ausentes: number
  tardanzas: number
  pendientes_revision: number
}

interface AsistenciaData {
  warning?: string
  kpis: Kpis
  data: {
    data: AsistenciaRow[]
    current_page: number
    last_page: number
    total: number
  }
}

export function AsistenciasPage() {
  const { usuario } = useAuth()
  const qc = useQueryClient()

  const [filters, setFilters] = useState({
    fecha_desde: new Date().toISOString().slice(0, 10),
    fecha_hasta: new Date().toISOString().slice(0, 10),
    agente_id: '',
  })
  const [applied, setApplied] = useState({ ...filters })
  const [page, setPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['asistencias', applied, page],
    queryFn: () =>
      api.get<AsistenciaData>('/v1/asistencias', {
        params: {
          ...applied,
          page,
          per_page: 30,
          agente_id: applied.agente_id || undefined,
        },
      }).then(r => r.data),
  })

  const aprobar = useMutation({
    mutationFn: (id: number) => api.post(`/v1/asistencias/${id}/aprobar`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['asistencias'] }),
  })

  function exportar() {
    const token = localStorage.getItem('auth_token')
    const base  = (api.defaults.baseURL ?? '').replace(/\/$/, '')
    const params = new URLSearchParams()
    if (applied.fecha_desde) params.set('fecha_desde', applied.fecha_desde)
    if (applied.fecha_hasta) params.set('fecha_hasta', applied.fecha_hasta)
    if (applied.agente_id)   params.set('agente_id',   applied.agente_id)
    fetch(`${base}/v1/asistencias/exportar?${params.toString()}`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then(r => r.blob())
      .then(blob => {
        const a = document.createElement('a')
        a.href = URL.createObjectURL(blob)
        a.download = `asistencias_${applied.fecha_desde}_${applied.fecha_hasta}.csv`
        a.click()
        URL.revokeObjectURL(a.href)
      })
  }

  if (data?.warning) {
    return (
      <div className="flex items-center gap-3 rounded-kyro-lg border border-kyro-warning/30 bg-kyro-warning/10 p-4 text-sm text-kyro-warning shadow-kyro-card">
        <AlertTriangle size={18} /> {data.warning}
      </div>
    )
  }

  const rows  = data?.data?.data ?? []
  const meta  = data?.data
  const kpis  = data?.kpis

  return (
    <div className="space-y-6">
      <PageHeader title="Panel de Asistencias" subtitle="Seguimiento de ingresos, salidas y revisiones pendientes">
        <Button variant="outline" size="sm" onClick={exportar}>
          <Download size={14} /> Exportar CSV
        </Button>
      </PageHeader>

      {/* Filtros */}
      <ListToolbar description="Acota el periodo y el agente que deseas revisar">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Desde</label>
            <Input type="date" value={filters.fecha_desde}
              onChange={e => setFilters(f => ({ ...f, fecha_desde: e.target.value }))}
              className="kyro-input"
            />
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Hasta</label>
            <Input type="date" value={filters.fecha_hasta}
              onChange={e => setFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
              className="kyro-input"
            />
          </div>
          {usuario?.rol === 'admin' && (
            <div>
              <label className="block text-xs text-gray-500 mb-1">ID Agente</label>
              <Input type="number" placeholder="Todos" value={filters.agente_id}
                onChange={e => setFilters(f => ({ ...f, agente_id: e.target.value }))}
                className="kyro-input w-24"
              />
            </div>
          )}
          <Button onClick={() => { setApplied({ ...filters }); setPage(1) }}>Buscar</Button>
          <Button variant="outline" onClick={() => {
            const reset = { fecha_desde: new Date().toISOString().slice(0, 10), fecha_hasta: new Date().toISOString().slice(0, 10), agente_id: '' }
            setFilters(reset); setApplied(reset); setPage(1)
          }}>Hoy</Button>
        </div>
      </ListToolbar>

      {/* KPI Cards */}
      {kpis && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          {[
            { label: 'Presentes',     value: kpis.presentes,          Icon: UserCheck,   color: 'text-kyro-success', border: 'border-l-kyro-success' },
            { label: 'Ausentes',      value: kpis.ausentes,           Icon: UserX,       color: 'text-kyro-danger',  border: 'border-l-kyro-danger' },
            { label: 'Tardanzas',     value: kpis.tardanzas,          Icon: AlertCircle, color: 'text-kyro-warning', border: 'border-l-kyro-warning' },
            { label: 'Pend. Revisión',value: kpis.pendientes_revision, Icon: Clock,      color: 'text-kyro-info',    border: 'border-l-kpi-total' },
          ].map(k => (
            <div key={k.label} className={`kyro-card border-l-4 p-4 ${k.border}`}>
              <div className="flex items-center gap-1 mb-1">
                <k.Icon size={13} className={k.color} />
                <p className="text-xs text-gray-500">{k.label}</p>
              </div>
              <p className={`text-2xl font-bold ${k.color}`}>{k.value}</p>
            </div>
          ))}
        </div>
      )}

      {/* Tabla */}
      <div className="kyro-card overflow-hidden">
        <div className="flex items-center justify-between border-b border-kyro-border p-4">
          <h2 className="text-sm font-semibold text-kyro-text">
            {isLoading ? 'Cargando...' : `${meta?.total ?? 0} registros`}
          </h2>
          <span className="text-xs text-gray-400">Pág. {meta?.current_page ?? 1}/{meta?.last_page ?? 1}</span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr>
                {['Fecha', 'Día', 'Agente', 'Tienda', 'Ingreso', 'Salida', 'Método', 'Estado', 'Acciones'].map(h => (
                  <th key={h} className="kyro-table-head px-4 py-3 text-left">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && <tr><td colSpan={9} className="px-4 py-10 text-center text-gray-400">Cargando...</td></tr>}
              {!isLoading && rows.length === 0 && (
                <tr><td colSpan={9} className="px-4 py-10 text-center text-gray-400">Sin asistencias en el período</td></tr>
              )}
              {rows.map(a => {
                const fechaObj = new Date(a.fecha + 'T00:00:00')
                const esTarde  = a.hora_ingreso && a.hora_ingreso > '09:00:00'
                const esRevision = Boolean(a.requiere_revision)

                return (
                  <tr key={a.id}
                    className={`border-b ${esRevision ? 'border-kyro-warning/30 bg-kyro-warning/10' : esTarde ? 'border-kyro-warning/20 bg-kyro-warning/5' : 'border-kyro-border hover:bg-kyro-elevated/50'}`}
                  >
                    <td className="px-4 py-3 text-gray-700">{fechaObj.toLocaleDateString('es-PE')}</td>
                    <td className="px-4 py-3 text-xs text-gray-400">{DIAS[fechaObj.getDay()]}</td>
                    <td className="px-4 py-3 font-medium text-gray-800">{a.nombres}</td>
                    <td className="px-4 py-3 text-xs font-mono text-slate-600">{a.tienda_base}</td>
                    <td className={`px-4 py-3 font-mono ${esTarde ? 'text-amber-700 font-bold' : 'text-gray-700'}`}>
                      {a.hora_ingreso ? a.hora_ingreso.slice(0, 5) : <span className="text-red-500">—</span>}
                    </td>
                    <td className="px-4 py-3 font-mono text-gray-600">{a.hora_salida ? a.hora_salida.slice(0, 5) : '—'}</td>
                    <td className="px-4 py-3">
                      <span className={`text-xs px-2 py-0.5 rounded-full ${a.metodo_marcacion === 'FOTO' ? 'bg-blue-100 text-blue-700' : a.metodo_marcacion === 'QR' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600'}`}>
                        {a.metodo_marcacion ?? 'MANUAL'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {esRevision
                        ? <span className="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full">Revisión</span>
                        : esTarde
                        ? <span className="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">Tardanza</span>
                        : <span className="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">OK</span>
                      }
                    </td>
                    <td className="px-4 py-3">
                      {esRevision && usuario?.rol === 'admin' && (
                        <Button size="sm" variant="outline"
                          disabled={aprobar.isPending}
                          onClick={() => aprobar.mutate(a.id)}
                        >
                          Aprobar
                        </Button>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-kyro-border p-4">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Anterior</Button>
            <span className="text-xs text-gray-500">{meta.total} registros</span>
            <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}>Siguiente</Button>
          </div>
        )}
      </div>
    </div>
  )
}
