import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../../services/api'
import { adminPaginasApi } from '../../services/adminPaginas.api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { StatCard } from '../../components/ui/StatCard'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import { useAgentesSelect } from '../../hooks/useAgentesSelect'
import { AlertCircle, AlertTriangle, Camera, CheckCircle, Clock, Download, Pencil, UserCheck, UserX, ClipboardList, CalendarRange, Receipt } from 'lucide-react'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'

const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado']

interface AsistenciaRow {
  id: number
  agente_id: number
  nombres: string
  tienda_base: string
  fecha: string
  hora_ingreso: string | null
  hora_salida: string | null
  inicio_refrigerio: string | null
  fin_refrigerio: string | null
  minutos_tardanza: number
  minutos_deuda: number
  minutos_extra: number
  omitio_refrigerio: number
  turno_extendido: number
  minutos_refrigerio_asignado: number | null
  horas_extras_aprobadas: number
  observacion_admin: string | null
  metodo_marcacion: string
  observacion: string | null
  requiere_revision: number
  dia_descanso: string | null
  salida_oficial: string | null
}

interface AsistenciaEditForm {
  fecha: string
  hora_ingreso: string
  inicio_refrigerio: string
  fin_refrigerio: string
  hora_salida: string
  omitio_refrigerio: boolean
  horas_extras_aprobadas: string
  minutos_refrigerio_asignado: string
  observacion_admin: string
}

const horaInput = (hora: string | null) => hora?.slice(0, 5) ?? ''

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
  const { agentes: agentesLista } = useAgentesSelect()
  const qc = useQueryClient()

  const [filters, setFilters] = useState({
    fecha_desde: new Date().toISOString().slice(0, 10),
    fecha_hasta: new Date().toISOString().slice(0, 10),
    agente_id: '',
  })
  const [applied, setApplied] = useState({ ...filters })
  const [page, setPage] = useState(1)
  const [editando, setEditando] = useState<AsistenciaRow | null>(null)
  const [editForm, setEditForm] = useState<AsistenciaEditForm | null>(null)

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

  const { data: fotosPendientes } = useQuery({
    queryKey: ['fotos-pendientes'],
    queryFn: () => adminPaginasApi.fotosPendientes(),
    enabled: usuario?.rol === 'admin',
    staleTime: 30_000,
  })
  const fotosCount = fotosPendientes?.total ?? 0

  // ── Asistencia Manual — paridad legacy acciones_asistencia.php (crear_manual) ──
  const [showManual, setShowManual] = useState(false)
  const [manual, setManual] = useState({
    agente_id: '',
    fecha: new Date().toISOString().slice(0, 10),
    hora_ingreso: '08:00',
    hora_salida: '17:00',
    inicio_refrigerio: '',
    fin_refrigerio: '',
    motivo: '',
  })

  const registrarManual = useMutation({
    mutationFn: () => api.post('/v1/asistencias', {
      agente_id: Number(manual.agente_id),
      fecha: manual.fecha,
      hora_ingreso: manual.hora_ingreso || null,
      hora_salida: manual.hora_salida || null,
      inicio_refrigerio: manual.inicio_refrigerio || null,
      fin_refrigerio: manual.fin_refrigerio || null,
      metodo_marcacion: 'MANUAL',
      motivo: manual.motivo,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['asistencias'] })
      setShowManual(false)
      setManual(m => ({ ...m, agente_id: '', motivo: '' }))
    },
  })

  // ── Excepciones (FALTA/PERMISO/PERDONAR) — paridad legacy acciones_asistencia ──
  const [showExc, setShowExc] = useState(false)
  const [exc, setExc] = useState({ agente_id: '', fecha: new Date().toISOString().slice(0, 10), estado: 'FALTA_INJUSTIFICADA' })

  const registrarExcepcion = useMutation({
    mutationFn: () => api.post('/v1/asistencias/excepcion', {
      agente_id: Number(exc.agente_id),
      fecha: exc.fecha,
      estado: exc.estado,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['asistencias'] })
      setShowExc(false)
      setExc(e => ({ ...e, agente_id: '' }))
    },
  })

  const eliminarRegistro = useMutation({
    mutationFn: (id: number) => api.delete(`/v1/asistencias/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['asistencias'] }),
  })

  const confirmDialog = useConfirmDialog()

  const editarRegistro = useMutation({
    mutationFn: () => api.patch(`/v1/asistencias/${editando!.id}`, {
      ...editForm,
      horas_extras_aprobadas: Number(editForm?.horas_extras_aprobadas || 0),
      minutos_refrigerio_asignado: editForm?.minutos_refrigerio_asignado === ''
        ? null
        : Number(editForm?.minutos_refrigerio_asignado),
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['asistencias'] })
      setEditando(null)
      setEditForm(null)
    },
  })

  function abrirEdicion(asistencia: AsistenciaRow) {
    setEditando(asistencia)
    setEditForm({
      fecha: asistencia.fecha.slice(0, 10),
      hora_ingreso: horaInput(asistencia.hora_ingreso),
      inicio_refrigerio: horaInput(asistencia.inicio_refrigerio),
      fin_refrigerio: horaInput(asistencia.fin_refrigerio),
      hora_salida: horaInput(asistencia.hora_salida),
      omitio_refrigerio: Boolean(asistencia.omitio_refrigerio),
      horas_extras_aprobadas: String(asistencia.horas_extras_aprobadas ?? 0),
      minutos_refrigerio_asignado: asistencia.minutos_refrigerio_asignado == null ? '' : String(asistencia.minutos_refrigerio_asignado),
      observacion_admin: asistencia.observacion_admin ?? asistencia.observacion ?? '',
    })
  }

  const [exportando, setExportando] = useState(false)

  async function exportar() {
    setExportando(true)
    try {
      const token = localStorage.getItem('auth_token')
      const base  = (api.defaults.baseURL ?? '').replace(/\/$/, '')
      const params = new URLSearchParams()
      if (applied.fecha_desde) params.set('fecha_desde', applied.fecha_desde)
      if (applied.fecha_hasta) params.set('fecha_hasta', applied.fecha_hasta)
      if (applied.agente_id)   params.set('agente_id',   applied.agente_id)
      const r = await fetch(`${base}/v1/asistencias/exportar?${params.toString()}`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      const blob = await r.blob()
      const a = document.createElement('a')
      a.href = URL.createObjectURL(blob)
      a.download = `asistencias_${applied.fecha_desde}_${applied.fecha_hasta}.xlsx`
      a.click()
      URL.revokeObjectURL(a.href)
    } finally {
      setExportando(false)
    }
  }

  const [exportandoNeiry, setExportandoNeiry] = useState(false)

  // Plantilla Neiry: matriz mensual E/SR/RR/S por agente, agrupada por tienda
  async function exportarNeiry() {
    setExportandoNeiry(true)
    try {
      const token = localStorage.getItem('auth_token')
      const base  = (api.defaults.baseURL ?? '').replace(/\/$/, '')
      const mes   = (applied.fecha_desde || new Date().toISOString().slice(0, 10)).slice(0, 7)
      const r = await fetch(`${base}/v1/asistencias/exportar-neiry?mes=${mes}`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      const blob = await r.blob()
      const a = document.createElement('a')
      a.href = URL.createObjectURL(blob)
      a.download = `Neiry_${mes}.xlsx`
      a.click()
      URL.revokeObjectURL(a.href)
    } finally {
      setExportandoNeiry(false)
    }
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
      <PageHeader title="Panel de Asistencias" subtitle="Seguimiento de ingresos, salidas y revisiones pendientes" Icon={Clock}>
        {usuario?.rol === 'admin' && (
          <Button variant="glassIndigo" size="sm" onClick={() => setShowManual(s => !s)}>
            <ClipboardList size={14} /> Asistencia Manual
          </Button>
        )}
        {usuario?.rol === 'admin' && (
          <Button variant="glassInfo" size="sm" onClick={() => setShowExc(s => !s)}>
            <AlertCircle size={14} /> Registrar excepción
          </Button>
        )}
        <Button variant="glassSuccess" size="sm" onClick={exportar} disabled={exportando}>
          <Download size={14} /> {exportando ? 'Exportando…' : 'Exportar Excel'}
        </Button>
        {usuario?.rol === 'admin' && (
          <Button variant="glassWarning" size="sm" onClick={exportarNeiry} disabled={exportandoNeiry}>
            <Download size={14} /> {exportandoNeiry ? 'Exportando…' : 'Plantilla Neiry'}
          </Button>
        )}
        {usuario?.rol === 'admin' && (
          <Link
            to="/revisar-fotos"
            className="relative inline-flex h-9 items-center gap-1.5 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 text-xs font-semibold text-amber-700 shadow-sm transition-all hover:bg-amber-500/20 dark:text-amber-400"
            title="Revisar fotos de marcación pendientes"
          >
            <Camera size={14} /> Fotos
            {fotosCount > 0 && (
              <span className="ml-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-kyro-danger px-1 text-[10px] font-bold text-white">
                {fotosCount > 99 ? '99+' : fotosCount}
              </span>
            )}
          </Link>
        )}
        {usuario?.rol === 'admin' && (
          <Link
            to="/asistencias/control"
            className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-kyro-border bg-kyro-elevated px-3 text-xs font-semibold text-kyro-body shadow-sm transition-all hover:border-kyro-warning/50 hover:text-kyro-warning"
            title="Control mensual de asistencias (matriz por agente)"
          >
            <CalendarRange size={14} /> Control
          </Link>
        )}
        {usuario?.rol === 'admin' && (
          <Link
            to="/asistencias/liquidacion"
            className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-kyro-border bg-kyro-elevated px-3 text-xs font-semibold text-kyro-body shadow-sm transition-all hover:border-kyro-warning/50 hover:text-kyro-warning"
            title="Historial de liquidación de asistencias"
          >
            <Receipt size={14} /> Liquidación
          </Link>
        )}
      </PageHeader>

      {/* Formulario de asistencia manual (admin) */}
      {showManual && usuario?.rol === 'admin' && (
        <div className="kyro-card border-l-4 border-l-cyan-500 p-4">
          <h3 className="mb-3 text-sm font-semibold text-kyro-text">Registrar asistencia manual (días pasados)</h3>
          <div className="flex flex-wrap items-end gap-3">
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Agente</label>
              <Select value={manual.agente_id} onChange={e => setManual(m => ({ ...m, agente_id: e.target.value }))} className="w-full">
                <option value="">Selecciona agente</option>
                {agentesLista.map(a => <option key={a.id} value={a.id}>{a.nombres}</option>)}
              </Select>
            </div>
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Fecha</label>
              <Input type="date" value={manual.fecha}
                onChange={e => setManual(m => ({ ...m, fecha: e.target.value }))} className="kyro-input" />
            </div>
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Ingreso</label>
              <Input type="time" value={manual.hora_ingreso}
                onChange={e => setManual(m => ({ ...m, hora_ingreso: e.target.value }))} className="kyro-input w-28" />
            </div>
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Salida</label>
              <Input type="time" value={manual.hora_salida}
                onChange={e => setManual(m => ({ ...m, hora_salida: e.target.value }))} className="kyro-input w-28" />
            </div>
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Ini. Refrig.</label>
              <Input type="time" value={manual.inicio_refrigerio}
                onChange={e => setManual(m => ({ ...m, inicio_refrigerio: e.target.value }))} className="kyro-input w-28" />
            </div>
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Fin Refrig.</label>
              <Input type="time" value={manual.fin_refrigerio}
                onChange={e => setManual(m => ({ ...m, fin_refrigerio: e.target.value }))} className="kyro-input w-28" />
            </div>
          </div>
          <div className="mt-3">
            <label className="block text-xs text-kyro-muted mb-1">Motivo (obligatorio)</label>
            <Input type="text" placeholder="Ej. Agente olvidó marcar al ingresar" value={manual.motivo}
              onChange={e => setManual(m => ({ ...m, motivo: e.target.value }))} className="kyro-input w-full max-w-lg" />
          </div>
          <div className="mt-3 flex gap-2">
            <Button
              variant="gold"
              disabled={!manual.agente_id || !manual.motivo || manual.motivo.length < 5 || registrarManual.isPending}
              onClick={() => registrarManual.mutate()}
            >
              {registrarManual.isPending ? 'Registrando...' : 'Registrar'}
            </Button>
            <Button variant="ghost" onClick={() => setShowManual(false)}>Cancelar</Button>
          </div>
          {registrarManual.isError && (
            <p className="mt-2 text-xs text-kyro-danger">
              {(registrarManual.error as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'No se pudo registrar la asistencia.'}
            </p>
          )}
        </div>
      )}

      {/* Formulario de excepción (admin) */}
      {showExc && usuario?.rol === 'admin' && (
        <div className="kyro-card border-l-4 border-l-kyro-warning p-4">
          <h3 className="mb-3 text-sm font-semibold text-kyro-text">Registrar excepción de asistencia</h3>
          <div className="flex flex-wrap items-end gap-3">
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Agente</label>
              <Select value={exc.agente_id} onChange={e => setExc(s => ({ ...s, agente_id: e.target.value }))} className="w-full">
                <option value="">Selecciona agente</option>
                {agentesLista.map(a => <option key={a.id} value={a.id}>{a.nombres}</option>)}
              </Select>
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Fecha</label>
              <Input type="date" value={exc.fecha}
                onChange={e => setExc(s => ({ ...s, fecha: e.target.value }))} className="kyro-input" />
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Tipo</label>
              <select value={exc.estado} onChange={e => setExc(s => ({ ...s, estado: e.target.value }))} className="kyro-input h-9 w-52">
                <option value="FALTA_INJUSTIFICADA">Falta injustificada</option>
                <option value="PERMISO">Permiso (deuda 9h)</option>
                <option value="PERDONAR">Perdonar (borrar negativo)</option>
              </select>
            </div>
            <Button
              variant="gold"
              disabled={!exc.agente_id || registrarExcepcion.isPending}
              onClick={() => registrarExcepcion.mutate()}
            >
              {registrarExcepcion.isPending ? 'Guardando...' : 'Registrar'}
            </Button>
            <Button variant="ghost" onClick={() => setShowExc(false)}>Cancelar</Button>
          </div>
          {registrarExcepcion.isError && (
            <p className="mt-2 text-xs text-kyro-danger">
              {(registrarExcepcion.error as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'No se pudo registrar la excepción.'}
            </p>
          )}
        </div>
      )}

      {/* Filtros */}
      <ListToolbar description="Acota el periodo y el agente que deseas revisar">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className="mb-1 block text-[0.68rem] font-semibold uppercase tracking-wide text-kyro-muted">Desde</label>
            <Input type="date" value={filters.fecha_desde}
              onChange={e => setFilters(f => ({ ...f, fecha_desde: e.target.value }))}
              className="kyro-input"
            />
          </div>
          <div>
            <label className="mb-1 block text-[0.68rem] font-semibold uppercase tracking-wide text-kyro-muted">Hasta</label>
            <Input type="date" value={filters.fecha_hasta}
              onChange={e => setFilters(f => ({ ...f, fecha_hasta: e.target.value }))}
              className="kyro-input"
            />
          </div>
          {usuario?.rol === 'admin' && (
            <div>
              <label className="mb-1 block text-[0.68rem] font-semibold uppercase tracking-wide text-kyro-muted">Agente</label>
              <Select value={filters.agente_id} onChange={e => setFilters(f => ({ ...f, agente_id: e.target.value }))} className="w-44">
                <option value="">Todos</option>
                {agentesLista.map(a => <option key={a.id} value={a.id}>{a.nombres}</option>)}
              </Select>
            </div>
          )}
              <Button variant="gold" onClick={() => { setApplied({ ...filters }); setPage(1) }}>Buscar</Button>
          <Button variant="outline" onClick={() => {
            const reset = { fecha_desde: new Date().toISOString().slice(0, 10), fecha_hasta: new Date().toISOString().slice(0, 10), agente_id: '' }
            setFilters(reset); setApplied(reset); setPage(1)
          }}>Hoy</Button>
        </div>
      </ListToolbar>

      {/* KPI Cards */}
      {kpis && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <StatCard title="Presentes" value={kpis.presentes} accent="#22c55e" valueColorClass="text-kyro-success" icon={<UserCheck size={13} />} />
          <StatCard title="Ausentes" value={kpis.ausentes} accent="#ef4444" valueColorClass="text-kyro-danger" icon={<UserX size={13} />} />
          <StatCard title="Tardanzas" value={kpis.tardanzas} accent="#f59e0b" valueColorClass="text-kyro-warning" icon={<AlertCircle size={13} />} />
          <StatCard title="Pend. Revisión" value={kpis.pendientes_revision} accent="#0d6efd" valueColorClass="text-kyro-info" icon={<Clock size={13} />} />
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
                    <td className="px-4 py-3 text-kyro-body">{fechaObj.toLocaleDateString('es-PE')}</td>
                    <td className="px-4 py-3 text-xs text-kyro-subtle">{DIAS[fechaObj.getDay()]}</td>
                    <td className="px-4 py-3 font-medium text-kyro-text">{a.nombres}</td>
                    <td className="px-4 py-3 text-xs font-mono text-kyro-muted">{a.tienda_base}</td>
                    <td className={`px-4 py-3 font-mono ${esTarde ? 'text-kyro-warning font-bold' : 'text-kyro-body'}`}>
                      {a.hora_ingreso ? a.hora_ingreso.slice(0, 5) : <span className="text-red-500">—</span>}
                    </td>
                    <td className="px-4 py-3 font-mono text-kyro-muted">{a.hora_salida ? a.hora_salida.slice(0, 5) : '—'}</td>
                    <td className="px-4 py-3">
                      <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${
                        a.metodo_marcacion === 'FOTO' ? 'bg-kyro-info/10 text-kyro-info' 
                        : a.metodo_marcacion === 'QR' ? 'bg-kyro-indigo/15 text-kyro-indigo' 
                        : 'bg-kyro-elevated text-kyro-muted'
                      }`}>
                        {a.metodo_marcacion ?? 'MANUAL'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {esRevision
                        ? <span className="text-xs bg-kyro-warning/15 text-kyro-warning px-2 py-0.5 rounded-full font-medium">Revisión</span>
                        : esTarde
                        ? <span className="text-xs bg-kyro-warning/10 text-kyro-warning px-2 py-0.5 rounded-full">Tardanza</span>
                        : <span className="text-xs bg-kyro-success/10 text-kyro-success px-2 py-0.5 rounded-full">OK</span>
                      }
                      {(a.minutos_tardanza > 0 || a.minutos_deuda > 0) && (
                        <p className="mt-1 text-[10px] text-kyro-subtle">
                          Tardanza {a.minutos_tardanza}m · Deuda {a.minutos_deuda}m
                        </p>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      {usuario?.rol === 'admin' && (
                        <TableActions>
                          <ActionIconButton tone="edit" label="Editar asistencia" icon={<Pencil size={15} />} onClick={() => abrirEdicion(a)} />
                          {esRevision && (
                            <Button
                              variant="glassSuccess"
                              size="iconSm"
                              aria-label="Aprobar asistencia"
                              title="Aprobar"
                              disabled={aprobar.isPending}
                              onClick={() => aprobar.mutate(a.id)}
                            >
                              <CheckCircle size={15} />
                            </Button>
                          )}
                          <ActionIconButton
                            tone="delete"
                            label="Eliminar asistencia"
                            icon={<UserX size={15} />}
                            disabled={eliminarRegistro.isPending}
                            onClick={async () => { if (await confirmDialog({ title: '¿Eliminar este registro de asistencia?', intent: 'danger', icon: UserX, confirmLabel: 'Eliminar' })) eliminarRegistro.mutate(a.id) }}
                          />
                        </TableActions>
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
            <span className="text-xs text-kyro-subtle">{meta.total} registros</span>
            <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}>Siguiente</Button>
          </div>
        )}
      </div>

      {editando && editForm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
          <div className="kyro-card max-h-[90vh] w-full max-w-2xl overflow-y-auto p-6">
            <div className="mb-5 flex items-start justify-between">
              <div>
                <h3 className="font-semibold text-kyro-text">Editar asistencia de {editando.nombres}</h3>
                <p className="text-xs text-kyro-muted">Tardanza y deuda se recalculan con el horario oficial.</p>
              </div>
              <Button variant="ghost" size="icon" aria-label="Cerrar edicion" onClick={() => { setEditando(null); setEditForm(null) }}>&times;</Button>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs text-kyro-muted">Fecha</label>
                <Input type="date" value={editForm.fecha} onChange={e => setEditForm(f => f && ({ ...f, fecha: e.target.value }))} />
              </div>
              <div>
                <label className="mb-1 block text-xs text-kyro-muted">Ingreso</label>
                <Input type="time" value={editForm.hora_ingreso} onChange={e => setEditForm(f => f && ({ ...f, hora_ingreso: e.target.value }))} />
              </div>
              <div>
                <label className="mb-1 block text-xs text-kyro-muted">Inicio refrigerio</label>
                <Input type="time" disabled={editForm.omitio_refrigerio} value={editForm.inicio_refrigerio} onChange={e => setEditForm(f => f && ({ ...f, inicio_refrigerio: e.target.value }))} />
              </div>
              <div>
                <label className="mb-1 block text-xs text-kyro-muted">Fin refrigerio</label>
                <Input type="time" disabled={editForm.omitio_refrigerio} value={editForm.fin_refrigerio} onChange={e => setEditForm(f => f && ({ ...f, fin_refrigerio: e.target.value }))} />
              </div>
              <div>
                <label className="mb-1 block text-xs text-kyro-muted">Salida</label>
                <Input type="time" value={editForm.hora_salida} onChange={e => setEditForm(f => f && ({ ...f, hora_salida: e.target.value }))} />
              </div>
              <div>
                <label className="mb-1 block text-xs text-kyro-muted">Horas extra aprobadas</label>
                <Input type="number" min="0" max="24" step="0.25" value={editForm.horas_extras_aprobadas} onChange={e => setEditForm(f => f && ({ ...f, horas_extras_aprobadas: e.target.value }))} />
              </div>
              {Boolean(editando.turno_extendido) && (
                <div>
                  <label className="mb-1 block text-xs text-kyro-muted">Refrigerio asignado (min)</label>
                  <Input type="number" min="0" max="180" value={editForm.minutos_refrigerio_asignado} onChange={e => setEditForm(f => f && ({ ...f, minutos_refrigerio_asignado: e.target.value }))} />
                </div>
              )}
              <label className="flex items-center gap-2 self-end pb-2 text-sm text-kyro-body">
                <input type="checkbox" checked={editForm.omitio_refrigerio}
                  onChange={e => setEditForm(f => f && ({ ...f, omitio_refrigerio: e.target.checked }))} />
                Turno corrido / omitió refrigerio
              </label>
              <div className="sm:col-span-2">
                <label className="mb-1 block text-xs text-kyro-muted">Observación administrativa</label>
                <textarea className="kyro-input min-h-20 w-full p-3 text-sm" maxLength={500}
                  value={editForm.observacion_admin} onChange={e => setEditForm(f => f && ({ ...f, observacion_admin: e.target.value }))} />
              </div>
            </div>

            {editarRegistro.isError && (
              <p className="mt-3 text-sm text-kyro-danger">
                {(editarRegistro.error as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'No se pudo actualizar la asistencia.'}
              </p>
            )}
            <div className="mt-5 flex gap-3">
              <Button variant="gold" className="flex-1" disabled={editarRegistro.isPending} onClick={() => editarRegistro.mutate()}>
                {editarRegistro.isPending ? 'Recalculando...' : 'Guardar y recalcular'}
              </Button>
              <Button variant="outline" onClick={() => { setEditando(null); setEditForm(null) }}>Cancelar</Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
