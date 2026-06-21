import { useState } from 'react'
import type { AxiosError } from 'axios'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../../services/api'
import type { PaginatedResponse } from '../../types/pagination'
import type { Reporte } from '../../types/reporte'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { ChevronLeft, ChevronRight, Eye, Edit, PenLine } from 'lucide-react'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

function diferenciaClass(val: number) {
  if (val === 0) return 'bg-kyro-elevated text-kyro-muted'
  return val < 0 ? 'bg-kyro-danger/15 text-kyro-danger' : 'bg-kyro-warning/15 text-kyro-warning'
}

const ESTADOS = [
  { value: '', label: 'Todos', tone: 'indigo' as const },
  { value: 'borrador', label: 'Borrador', tone: 'indigo' as const },
  { value: 'enviado', label: 'Enviado', tone: 'info' as const },
  { value: 'editado', label: 'Editado', tone: 'warning' as const },
  { value: 'aprobado', label: 'Aprobado', tone: 'success' as const },
]

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
          <p className="text-xs text-kyro-danger">{(error as AxiosError<{ error?: string }>)?.response?.data?.error ?? 'Error al enviar solicitud'}</p>
        )}
        <div className="flex gap-2 justify-end">
          <Button variant="outline" onClick={onClose}>Cancelar</Button>
          <Button variant="gold" disabled={!motivo.trim() || isPending} onClick={() => mutate()}>
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

interface HistorialOperativo {
  agente: {
    id: number
    dni: string
    nombres: string
    tienda_base: string
    es_jefe: boolean
  }
  periodo: { desde: string; hasta: string }
  asistencias: Array<{
    id: number
    fecha: string
    hora_ingreso: string | null
    hora_salida: string | null
    minutos_tardanza?: number
    estado_asistencia?: string
    inicio_refrigerio?: string | null
    fin_refrigerio?: string | null
    comodin_usado?: number | boolean | null
    min_comodin?: number | null
    omitio_refrigerio?: number | boolean | null
    horas_extras_aprobadas?: number | null
    minutos_deuda?: number | null
    minutos_tardanza_refrigerio?: number | null
  }>
  comisiones: Array<{
    fecha: string
    reporte_id: number
    items: number
    comision: string | number
  }>
  total_comisiones: number
  equipo: Array<{
    id: number
    nombres: string
    dni: string
    presentes: number
    faltas: number
    tardanza_total: number
  }>
}

function HistorialOperativoPanel({ data }: { data?: HistorialOperativo }) {
  if (!data) return null

  const queryClient = useQueryClient()
  const [procesandoId, setProcesandoId] = useState<number | null>(null)

  const recuperarTardanza = useMutation({
    mutationFn: ({ asistenciaId, minutos }: { asistenciaId: number; minutos: number }) =>
      api.post('/v1/asistencias/salvavidas', {
        asistencia_id: asistenciaId,
        minutos: minutos,
      }).then(r => r.data),
    onSuccess: (r: { success: boolean; mensaje?: string }) => {
      alert(r.mensaje ?? 'Tardanza recuperada.')
      queryClient.invalidateQueries({ queryKey: ['mi-historial-operativo'] })
      queryClient.invalidateQueries({ queryKey: ['mis-tardanzas'] })
    },
    onError: (e: any) => {
      alert(e.response?.data?.mensaje ?? 'Error al recuperar la tardanza.')
    },
    onSettled: () => {
      setProcesandoId(null)
    }
  })

  // Monday of current week logic
  const checkEsEstaSemana = (fechaStr: string) => {
    const date = new Date(fechaStr + 'T00:00:00')
    const hoy = new Date()
    const day = hoy.getDay()
    const diff = hoy.getDate() - day + (day === 0 ? -6 : 1)
    const lunes = new Date(hoy.setDate(diff))
    lunes.setHours(0, 0, 0, 0)
    return date >= lunes
  }

  const comodinUsadoEstaSemana = data.asistencias.some(
    item => checkEsEstaSemana(item.fecha) && (item.comodin_usado === 1 || item.comodin_usado === true)
  )
  const comodinDisponible = !comodinUsadoEstaSemana

  return (
    <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
      {/* Mi Historial de Marcas y Descuentos (Table - 2/3 width) */}
      <section className="kyro-card overflow-hidden xl:col-span-2">
        <div className="border-b border-kyro-border p-4">
          <h3 className="text-sm font-semibold text-kyro-text">Mi Historial de Marcas y Descuentos</h3>
          <p className="text-xs text-kyro-muted">{data.agente.nombres} · {data.periodo.desde} al {data.periodo.hasta}</p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="kyro-table-head">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-semibold text-kyro-muted">FECHA</th>
                <th className="px-4 py-3 text-center text-xs font-semibold text-kyro-muted">ENTRADA</th>
                <th className="px-4 py-3 text-center text-xs font-semibold text-kyro-muted">INICIO REFRI</th>
                <th className="px-4 py-3 text-center text-xs font-semibold text-kyro-muted">FIN REFRI</th>
                <th className="px-4 py-3 text-center text-xs font-semibold text-kyro-muted">SALIDA</th>
                <th className="px-4 py-3 text-left text-xs font-semibold text-kyro-muted">DESGLOSE TARDANZA</th>
                <th className="px-4 py-3 text-right text-xs font-semibold text-kyro-muted">BALANCE S/</th>
              </tr>
            </thead>
            <tbody>
              {data.asistencias.length === 0 && (
                <tr>
                  <td colSpan={7} className="p-5 text-center text-xs text-kyro-muted">
                    Sin marcas en el periodo.
                  </td>
                </tr>
              )}
              {data.asistencias.map(item => {
                const esEstaSemana = checkEsEstaSemana(item.fecha)
                const tardanzaTotalBd = item.minutos_tardanza ?? 0
                const minSalvavidas = (item.comodin_usado === 1 || item.comodin_usado === true) ? (item.min_comodin ?? 0) : 0
                const tardeRefri = item.minutos_tardanza_refrigerio ?? 0
                const tardeEntradaBruta = Math.max(0, tardanzaTotalBd - tardeRefri)
                const tardeEntradaNeta = Math.max(0, tardeEntradaBruta - minSalvavidas)
                const minutosADescontar = tardeEntradaNeta + tardeRefri
                const descuentoFinal = minutosADescontar * 1.00

                const hExtraAprob = item.horas_extras_aprobadas ?? 0
                const minutosDeudaTotal = item.minutos_deuda ?? 0
                const horasDeudaV = Math.floor(minutosDeudaTotal / 60)
                const minsDeudaV = minutosDeudaTotal % 60
                let strDeuda = ""
                if (horasDeudaV > 0) strDeuda += `${horasDeudaV}h `
                if (minsDeudaV > 0) strDeuda += `${minsDeudaV}m`
                strDeuda = strDeuda.trim()

                const puedeMostrarSalvavidas = esEstaSemana
                  && comodinDisponible
                  && tardeEntradaBruta > 0
                  && tardeEntradaBruta <= 30
                  && !(item.comodin_usado === 1 || item.comodin_usado === true)

                return (
                  <tr key={item.id} className="border-b border-kyro-border hover:bg-kyro-elevated/60">
                    <td className="px-4 py-3 text-left">
                      <div className="font-semibold text-kyro-text">{new Date(item.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</div>
                      {(item.omitio_refrigerio === 1 || item.omitio_refrigerio === true) && (
                        <span className="inline-block px-1.5 py-0.5 mt-1 text-[9px] font-bold rounded bg-kyro-info/10 text-kyro-info">
                          TURNO CORRIDO
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className={tardeEntradaNeta > 0 ? "font-bold text-kyro-danger" : "text-kyro-muted"}>
                        {item.hora_ingreso?.slice(0, 5) ?? '--:--'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-center text-xs text-kyro-muted">
                      {item.inicio_refrigerio?.slice(0, 5) ?? '--:--'}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <span className={tardeRefri > 0 ? "font-bold text-kyro-danger" : "text-kyro-muted"}>
                        {item.fin_refrigerio?.slice(0, 5) ?? '--:--'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-center text-xs text-kyro-muted">
                      {item.hora_salida?.slice(0, 5) ?? '--:--'}
                    </td>
                    <td className="px-4 py-3 text-left text-xs space-y-1">
                      {item.estado_asistencia === 'FALTA_INJUSTIFICADA' ? (
                        <span className="font-bold text-kyro-danger">FALTA REGISTRADA</span>
                      ) : (
                        <>
                          {minSalvavidas > 0 && (
                            <div className="font-bold text-kyro-info">
                              🛟 Salvavidas Usado: -{minSalvavidas}m de refri
                            </div>
                          )}
                          {tardeEntradaNeta > 0 && (
                            <div className="text-kyro-danger">
                              • Tarde Entrada: -{tardeEntradaNeta}m
                            </div>
                          )}
                          {tardeRefri > 0 && (
                            <div className="text-kyro-danger">
                              • Tarde Refri: -{tardeRefri}m
                            </div>
                          )}
                          {minutosDeudaTotal > 0 && (
                            <div className="text-kyro-warning">
                              • Deuda Jornada: -{strDeuda}
                            </div>
                          )}
                          {hExtraAprob > 0 && (
                            <div className="font-bold text-kyro-success">
                              • Hrs Recuperación: +{hExtraAprob}h
                            </div>
                          )}
                          {tardeEntradaNeta === 0 && tardeRefri === 0 && hExtraAprob === 0 && minutosDeudaTotal === 0 && minSalvavidas === 0 && (
                            <span className="text-kyro-muted">Jornada Correcta</span>
                          )}
                          {puedeMostrarSalvavidas && (
                            <Button
                              size="sm"
                              variant="glassSuccess"
                              className="w-full mt-1 font-bold text-[10px] h-7 gap-1"
                              disabled={procesandoId === item.id || recuperarTardanza.isPending}
                              onClick={() => {
                                if (window.confirm(`¿Recuperar Tardanza?\n\nSe perdonarán tus ${tardeEntradaBruta} minutos de tardanza de este día.\n\n⚠️ Ese tiempo se descontará automáticamente de tu tiempo de refrigerio de HOY.\n\nNota: Solo puedes usar esto 1 vez por semana.`)) {
                                  setProcesandoId(item.id)
                                  recuperarTardanza.mutate({ asistenciaId: item.id, minutos: tardeEntradaBruta })
                                }
                              }}
                            >
                              🛟 RECUPERAR TARDANZA
                            </Button>
                          )}
                        </>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right font-bold">
                      {item.estado_asistencia === 'FALTA_INJUSTIFICADA' ? (
                        <span className="text-kyro-danger">- S/ —</span>
                      ) : descuentoFinal > 0 ? (
                        <span className="text-kyro-danger">- {fmt(descuentoFinal)}</span>
                      ) : (
                        <span className="text-kyro-muted">S/ 0.00</span>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </section>

      {/* Mis Comisiones (Right panel - 1/3 width) */}
      <section className="kyro-card overflow-hidden xl:col-span-1">
        <div className="flex items-center justify-between border-b border-kyro-border p-4">
          <div>
            <h3 className="text-sm font-semibold text-kyro-text">Mis Comisiones</h3>
            <p className="text-xs text-kyro-muted">Ventas atribuidas al agente.</p>
          </div>
          <span className="font-mono font-bold text-kyro-success">{fmt(data.total_comisiones)}</span>
        </div>
        <div className="max-h-[500px] divide-y divide-kyro-border overflow-y-auto">
          {data.comisiones.length === 0 && <p className="p-5 text-center text-xs text-kyro-muted">Sin comisiones en el periodo.</p>}
          {data.comisiones.map(item => (
            <Link key={`${item.fecha}-${item.reporte_id}`} to={`/reportes/${item.reporte_id}`} className="flex items-center justify-between p-3 hover:bg-kyro-elevated">
              <div>
                <p className="text-sm font-medium text-kyro-text">{new Date(item.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</p>
                <p className="text-xs text-kyro-muted">{item.items} item(s) comisionables</p>
              </div>
              <span className="font-mono font-bold text-kyro-success">+{fmt(item.comision)}</span>
            </Link>
          ))}
        </div>
      </section>

      {/* Panel del equipo si es Jefe */}
      {data.agente.es_jefe && (
        <section className="kyro-card overflow-hidden xl:col-span-3">
          <div className="border-b border-kyro-border p-4">
            <h3 className="text-sm font-semibold text-kyro-text">Equipo de tienda {data.agente.tienda_base}</h3>
            <p className="text-xs text-kyro-muted">Presencias, faltas y tardanza acumulada del periodo.</p>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr>
                  {['Agente', 'DNI', 'Presencias', 'Faltas', 'Tardanza'].map(h => <th key={h} className="kyro-table-head px-4 py-3 text-left">{h}</th>)}
                </tr>
              </thead>
              <tbody>
                {data.equipo.map(miembro => (
                  <tr key={miembro.id} className="[&>td]:border-b [&>td]:border-kyro-border">
                    <td className="px-4 py-3 font-medium text-kyro-text">{miembro.nombres}</td>
                    <td className="px-4 py-3 font-mono text-xs text-kyro-muted">{miembro.dni}</td>
                    <td className="px-4 py-3">{miembro.presentes}</td>
                    <td className="px-4 py-3 text-kyro-danger">{miembro.faltas}</td>
                    <td className="px-4 py-3 text-kyro-warning">{miembro.tardanza_total} min</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  )
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
    onError: (e: AxiosError<{ mensaje?: string }>) => setMsg(e.response?.data?.mensaje ?? 'Error al recuperar la tardanza.'),
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
        <Button variant="gold" size="sm" disabled={dni.length !== 8 || isFetching} onClick={() => { setMsg(null); setConsultado(dni) }}>
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
              <Button size="sm" variant="glassSuccess" disabled={usado || recuperar.isPending}
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
  const navigate = useNavigate()
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
  const { data: historialOperativo } = useQuery({
    queryKey: ['mi-historial-operativo', applied.fecha_desde, applied.fecha_hasta],
    queryFn: () => api.get<HistorialOperativo>('/v1/asistencias/mi-historial', {
      params: {
        fecha_desde: applied.fecha_desde || undefined,
        fecha_hasta: applied.fecha_hasta || undefined,
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
      <HistorialOperativoPanel data={historialOperativo} />

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
          <SegmentedToggle
            ariaLabel="Filtrar mi historial por estado"
            size="sm"
            options={ESTADOS}
            value={filters.estado}
            onChange={(estado) => setFilters(f => ({ ...f, estado }))}
          />
        </div>
        <Button variant="gold" onClick={applyFilters}>Buscar</Button>
        <Button variant="ghost" onClick={() => { const r = { fecha_desde: '', fecha_hasta: '', estado: '' }; setFilters(r); setApplied({ ...r, page: 1 }) }}>Limpiar</Button>
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
                      <TableActions>
                        <ActionIconButton
                          tone="view"
                          label="Ver reporte"
                          icon={<Eye size={15} />}
                          onClick={() => navigate(`/reportes/${r.id}`)}
                        />
                        {puedeEditar && (
                          <Button size="sm" variant="glassWarning" onClick={() => setSolicitandoId(r.id)}
                            className="h-8 gap-1 px-2 text-xs">
                            <Edit size={13} /> Solicitar edición
                          </Button>
                        )}
                        {r.estado_edicion === 'SOLICITADO' && (
                          <span className="text-xs text-kyro-warning font-medium">Pendiente aprobación</span>
                        )}
                        {r.estado_edicion === 'APROBADO' && (
                          <ActionIconButton
                            tone="edit"
                            label="Editar reporte"
                            icon={<PenLine size={15} />}
                            onClick={() => navigate(`/reportes/${r.id}/editar`)}
                          />
                        )}
                      </TableActions>
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
