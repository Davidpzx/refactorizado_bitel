import { useParams, Link, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { agentesApi } from '../../services/agentes.api'
import type { Agente, HistorialAgenteEvento } from '../../types/agente'
import type { Reporte } from '../../types/reporte'
import type { PaginatedResponse } from '../../types/pagination'
import { Button } from '../../components/ui/button'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { ArrowLeft, User, MapPin, DollarSign, Phone, Mail, Calendar, Key, ShieldCheck, FileText, TrendingUp, Download, Smartphone, Save, Receipt, Trash2, Eye, Plus, History, X } from 'lucide-react'
import { useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useAuth } from '../../hooks/useAuth'
import { DocumentosAgentePanel } from '../../components/agente/DocumentosAgentePanel'
import { formatearFechaCorta } from '../../lib/fechas'

const pen = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' })
const fmt = (v: number | string | null | undefined) => pen.format(Number(v ?? 0))

interface AgenteVentasResponse {
  agente: AgenteLaboral
  stats: {
    total_reportes: number
    total_vendido: string
    diferencia_acumulada: string
  }
  reportes: PaginatedResponse<Reporte>
}

interface AgenteLaboral extends Agente {
  fecha_prueba_inicio?: string | null
  fecha_prueba_fin?: string | null
}

interface TokenResult {
  success: boolean
  token?: string
  expiracion?: string
  tipo?: string
  accion?: string
}

interface FechasLaboralesResult {
  msg?: string
}

function FechasLaboralesPanel({ agenteId, agente }: { agenteId: string; agente: AgenteLaboral }) {
  const [fechaIngreso,      setFechaIngreso]      = useState(agente.fecha_ingreso ?? '')
  const [fechaPruebaInicio, setFechaPruebaInicio] = useState(agente.fecha_prueba_inicio ?? '')
  const [fechaPruebaFin,    setFechaPruebaFin]    = useState(agente.fecha_prueba_fin ?? '')
  const [msg, setMsg]                              = useState<string | null>(null)

  const mut = useMutation({
    mutationFn: (data: Record<string, string>) =>
      api.patch<FechasLaboralesResult>(`/v1/agentes/${agenteId}/fechas-laborales`, data).then(r => r.data),
    onSuccess: (res) => setMsg(res.msg ?? 'Fechas actualizadas.'),
    onError: () => setMsg('Error al guardar fechas.'),
  })

  return (
    <section className="kyro-card relative overflow-hidden p-5">
      <h3 className="mb-1 flex items-center gap-2 text-sm font-semibold text-kyro-text">
        <Calendar size={15} className="text-kyro-gold" /> Fechas laborales
      </h3>
      <p className="mb-4 text-xs text-kyro-muted">Ingreso y vigencia del periodo de prueba.</p>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Fecha ingreso</label>
          <Input type="date" value={fechaIngreso} onChange={e => setFechaIngreso(e.target.value)} />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Inicio prueba</label>
          <Input type="date" value={fechaPruebaInicio} onChange={e => setFechaPruebaInicio(e.target.value)} />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Fin prueba</label>
          <Input type="date" value={fechaPruebaFin} onChange={e => setFechaPruebaFin(e.target.value)} />
        </div>
      </div>
      {msg && <p className={`mt-3 rounded-kyro px-3 py-2 text-xs ${msg.startsWith('Error') ? 'bg-kyro-danger/10 text-kyro-danger' : 'bg-kyro-success/10 text-kyro-success'}`}>{msg}</p>}
      <div className="mt-3 flex justify-end">
        <Button
          variant="gold"
          size="sm"
          disabled={mut.isPending}
          onClick={() => {
            setMsg(null)
            mut.mutate({
              fecha_ingreso:       fechaIngreso       || '',
              fecha_prueba_inicio: fechaPruebaInicio  || '',
              fecha_prueba_fin:    fechaPruebaFin     || '',
            })
          }}
        >
          {mut.isPending ? 'Guardando...' : 'Guardar fechas'}
        </Button>
      </div>
    </section>
  )
}

function TokenSeguridadPanel({ agenteId }: { agenteId: string }) {
  const [resultado, setResultado] = useState<TokenResult | null>(null)
  const [msg, setMsg]             = useState<string | null>(null)

  const mut = useMutation({
    mutationFn: (tipo: string) =>
      api.post<TokenResult>(`/v1/agentes/${agenteId}/token-seguridad`, { tipo }).then(r => r.data),
    onSuccess: (data) => {
      setResultado(data)
      if (data.accion === 'revocado') setMsg('Token revocado.')
      else setMsg(null)
    },
    onError: () => setMsg('Error al procesar la solicitud.'),
  })

  return (
    <section className="kyro-card relative overflow-hidden p-5">
      <h3 className="mb-1 flex items-center gap-2 text-sm font-semibold text-kyro-text">
        <ShieldCheck size={15} className="text-kyro-gold" /> Token de seguridad
      </h3>
      <p className="mb-4 text-xs text-kyro-muted">Genera o revoca credenciales de acceso temporal.</p>

      {resultado?.token && (
        <div className="mb-4 rounded-kyro border border-kyro-indigo bg-kyro-indigo/10 p-3">
          <p className="mb-1 text-xs text-kyro-muted">Token generado ({resultado.tipo}):</p>
          <p className="break-all font-mono text-2xl font-bold tracking-widest text-kyro-gold">{resultado.token}</p>
          <p className="mt-1 text-xs text-kyro-subtle">Expira: {resultado.expiracion}</p>
        </div>
      )}

      {msg && <p className="mb-3 rounded-kyro bg-kyro-elevated px-3 py-2 text-xs text-kyro-muted">{msg}</p>}

      <div className="flex flex-wrap gap-2">
        <Button size="sm" variant="outline" disabled={mut.isPending} onClick={() => mut.mutate('diario')}>
          <Key size={13} className="mr-1" /> Generar diario
        </Button>
        <Button size="sm" variant="outline" disabled={mut.isPending} onClick={() => mut.mutate('permanente')}>
          <Key size={13} className="mr-1" /> Generar permanente
        </Button>
        <Button
          size="sm"
          variant="outline"
          disabled={mut.isPending}
          className="border-kyro-danger text-kyro-danger hover:bg-kyro-danger/10"
          onClick={() => { setResultado(null); mut.mutate('revocar') }}
        >
          Revocar token
        </Button>
      </div>
    </section>
  )
}

interface Adelanto {
  id: number
  fecha: string
  monto: string
  motivo: string | null
}

function AdelantosPanel({ agenteId }: { agenteId: string }) {
  const qc = useQueryClient()
  const [monto, setMonto]   = useState('')
  const [fecha, setFecha]   = useState(new Date().toISOString().slice(0, 10))
  const [motivo, setMotivo] = useState('')

  const { data } = useQuery({
    queryKey: ['agente-adelantos', agenteId],
    queryFn: () => api.get<{ data: Adelanto[]; total: number }>(`/v1/agentes/${agenteId}/adelantos`).then(r => r.data),
  })
  const adelantos = data?.data ?? []
  const total     = data?.total ?? 0

  const registrar = useMutation({
    mutationFn: () => api.post(`/v1/agentes/${agenteId}/adelantos`, { monto: Number(monto), fecha, motivo }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['agente-adelantos', agenteId] }); setMonto(''); setMotivo('') },
  })
  const eliminar = useMutation({
    mutationFn: (adelantoId: number) => api.delete(`/v1/agentes/${agenteId}/adelantos/${adelantoId}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['agente-adelantos', agenteId] }),
  })

  return (
    <section className="kyro-card relative overflow-hidden p-5">
      <h3 className="mb-1 flex items-center gap-2 text-sm font-semibold text-kyro-text">
        <DollarSign size={15} className="text-kyro-gold" /> Adelantos
      </h3>
      <p className="mb-4 text-xs text-kyro-muted">Adelantos de sueldo; se descuentan en la planilla del mes.</p>

      <div className="mb-4 flex flex-wrap items-end gap-2">
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Monto (S/)</label>
          <Input type="number" step="0.01" value={monto} onChange={e => setMonto(e.target.value)} className="w-28" placeholder="0.00" />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Fecha</label>
          <Input type="date" value={fecha} onChange={e => setFecha(e.target.value)} />
        </div>
        <div className="min-w-[8rem] flex-1">
          <label className="mb-1 block text-xs text-kyro-muted">Motivo</label>
          <Input type="text" value={motivo} onChange={e => setMotivo(e.target.value)} placeholder="Opcional" />
        </div>
        <Button size="sm" variant="gold" disabled={!monto || Number(monto) <= 0 || registrar.isPending} onClick={() => registrar.mutate()}>
          {registrar.isPending ? 'Guardando...' : 'Registrar'}
        </Button>
      </div>

      <div className="rounded-kyro border border-kyro-border">
        <div className="flex items-center justify-between border-b border-kyro-border px-3 py-2 text-xs text-kyro-muted">
          <span>{adelantos.length} adelanto(s)</span>
          <span className="font-mono font-bold text-kyro-danger">Total: -{fmt(total)}</span>
        </div>
        <div className="max-h-48 overflow-y-auto divide-y divide-kyro-border">
          {adelantos.length === 0 && <p className="px-3 py-4 text-center text-xs text-kyro-muted">Sin adelantos registrados</p>}
          {adelantos.map(a => (
            <div key={a.id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
              <span className="font-mono text-xs text-kyro-muted">{new Date(a.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</span>
              <span className="min-w-0 flex-1 truncate text-xs text-kyro-body">{a.motivo || '—'}</span>
              <span className="font-mono font-bold text-kyro-danger">-{fmt(a.monto)}</span>
              <Button
                variant="glassDanger"
                size="iconSm"
                aria-label="Eliminar adelanto"
                disabled={eliminar.isPending}
                onClick={() => { if (confirm('¿Eliminar este adelanto?')) eliminar.mutate(a.id) }}
              >
                <Trash2 size={14} />
              </Button>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

type ListaRrhh = Array<Record<string, string | number>>
interface PerfilRrhh {
  nombres: string
  apellidos: string | null
  telefono: string | null
  correo: string | null
  direccion: string | null
  fecha_nacimiento: string | null
  lugar_nacimiento: string | null
  grupo_sanguineo: string | null
  alergias: string | null
  sistema_pension: string | null
  nombre_afp: string | null
  numero_cuspp: string | null
  antecedentes_penales: boolean
  antecedentes_policial: boolean
  antecedentes_judicial: boolean
  contactos_emergencia: ListaRrhh
  carga_familiar: ListaRrhh
  formacion_academica: ListaRrhh
  experiencia_laboral: ListaRrhh
}

interface Boleta {
  id: number
  fecha_inicio: string
  fecha_fin: string
  total_pagado: string
  estado: 'PENDIENTE' | 'PAGADO'
  fecha_pago: string
}

const listaATexto = (items: ListaRrhh, keys: string[]) =>
  (items ?? []).map(item => keys.map(key => String(item[key] ?? '')).join(' | ')).join('\n')

const textoALista = (text: string, keys: string[]): ListaRrhh =>
  text.split(/\r?\n/).map(line => line.trim()).filter(Boolean).map(line => {
    const values = line.split('|').map(value => value.trim())
    return Object.fromEntries(keys.map((key, index) => [key, values[index] ?? '']))
  })

async function descargar(path: string, filename: string) {
  const response = await api.get(path, { responseType: 'blob' })
  const url = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

function PerfilRrhhPanel({ agenteId }: { agenteId: string }) {
  const { data } = useQuery({
    queryKey: ['agente-perfil-rrhh', agenteId],
    queryFn: () => api.get<{ data: PerfilRrhh }>(`/v1/agentes/${agenteId}/perfil-rrhh`).then(r => r.data.data),
  })
  if (!data) return <section className="kyro-card p-5 text-sm text-kyro-muted">Cargando ficha RRHH...</section>

  return <PerfilRrhhEditor key={agenteId} agenteId={agenteId} initial={data} />
}

function PerfilRrhhEditor({ agenteId, initial }: { agenteId: string; initial: PerfilRrhh }) {
  const qc = useQueryClient()
  const [form, setForm] = useState<PerfilRrhh>(initial)
  const [listas, setListas] = useState({
    contactos: listaATexto(initial.contactos_emergencia, ['nombre', 'parentesco', 'telefono']),
    familia: listaATexto(initial.carga_familiar, ['nombre', 'parentesco', 'edad']),
    formacion: listaATexto(initial.formacion_academica, ['nivel', 'institucion', 'carrera', 'anio_fin']),
    experiencia: listaATexto(initial.experiencia_laboral, ['empresa', 'cargo', 'desde', 'hasta']),
  })
  const guardar = useMutation({
    mutationFn: () => api.put(`/v1/agentes/${agenteId}/perfil-rrhh`, {
      ...form,
      contactos_emergencia: textoALista(listas.contactos, ['nombre', 'parentesco', 'telefono']),
      carga_familiar: textoALista(listas.familia, ['nombre', 'parentesco', 'edad']),
      formacion_academica: textoALista(listas.formacion, ['nivel', 'institucion', 'carrera', 'anio_fin']),
      experiencia_laboral: textoALista(listas.experiencia, ['empresa', 'cargo', 'desde', 'hasta']),
    }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['agente-perfil-rrhh', agenteId] }),
  })

  const set = (key: keyof PerfilRrhh, value: string | boolean) => setForm(current => ({ ...current, [key]: value }))

  return (
    <section className="kyro-card p-5 lg:col-span-2">
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h3 className="text-sm font-semibold text-kyro-text">Ficha RRHH</h3>
          <p className="text-xs text-kyro-muted">Datos personales, previsionales, familiares y laborales.</p>
        </div>
        <Button size="sm" variant="gold" disabled={guardar.isPending} onClick={() => guardar.mutate()}>
          <Save size={14} /> {guardar.isPending ? 'Guardando...' : 'Guardar ficha'}
        </Button>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {([
          ['apellidos', 'Apellidos', 'text'], ['telefono', 'Telefono', 'text'], ['correo', 'Correo', 'email'], ['direccion', 'Direccion', 'text'],
          ['fecha_nacimiento', 'Fecha nacimiento', 'date'], ['lugar_nacimiento', 'Lugar nacimiento', 'text'],
          ['grupo_sanguineo', 'Grupo sanguineo', 'text'], ['alergias', 'Alergias', 'text'],
          ['sistema_pension', 'Sistema pension', 'text'], ['nombre_afp', 'AFP', 'text'], ['numero_cuspp', 'CUSPP', 'text'],
        ] as Array<[keyof PerfilRrhh, string, string]>).map(([key, label, type]) => (
          <div key={key}>
            <label className="mb-1 block text-xs text-kyro-muted">{label}</label>
            <Input type={type} value={String(form[key] ?? '')} onChange={e => set(key, e.target.value)} />
          </div>
        ))}
      </div>
      <div className="mt-4 flex flex-wrap gap-4 text-xs text-kyro-body">
        {([
          ['antecedentes_penales', 'Antecedentes penales'],
          ['antecedentes_policial', 'Antecedentes policiales'],
          ['antecedentes_judicial', 'Antecedentes judiciales'],
        ] as Array<[keyof PerfilRrhh, string]>).map(([key, label]) => (
          <label key={key} className="flex items-center gap-2">
            <input type="checkbox" checked={Boolean(form[key])} onChange={e => set(key, e.target.checked)} />
            {label}
          </label>
        ))}
      </div>
      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        {([
          ['contactos', 'Contactos emergencia', 'nombre | parentesco | telefono'],
          ['familia', 'Carga familiar', 'nombre | parentesco | edad'],
          ['formacion', 'Formacion academica', 'nivel | institucion | carrera | anio'],
          ['experiencia', 'Experiencia laboral', 'empresa | cargo | desde | hasta'],
        ] as const).map(([key, label, help]) => (
          <div key={key}>
            <label className="mb-1 block text-xs font-medium text-kyro-body">{label}</label>
            <textarea
              rows={4}
              value={listas[key]}
              onChange={e => setListas(current => ({ ...current, [key]: e.target.value }))}
              className="kyro-input w-full text-sm"
              placeholder={help}
            />
            <p className="mt-1 text-[11px] text-kyro-muted">Una fila por registro: {help}</p>
          </div>
        ))}
      </div>
    </section>
  )
}

function BoletasPanel({ agenteId, nombre }: { agenteId: string; nombre: string }) {
  const qc = useQueryClient()
  const { data } = useQuery({
    queryKey: ['agente-boletas', agenteId],
    queryFn: () => api.get<{ data: Boleta[] }>(`/v1/agentes/${agenteId}/boletas`).then(r => r.data.data),
  })
  const accion = useMutation({
    mutationFn: ({ id, accion }: { id: number; accion: 'pagar' | 'eliminar' }) => api.patch(`/v1/constancias/boleta/${id}`, { accion }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['agente-boletas', agenteId] }),
  })

  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ fecha_inicio: '', fecha_fin: '', sueldo_base: '', bonos: '0', dscto_tardanza: '0', dscto_adelantos: '0' })
  const totalNeto = Math.max(0,
    (parseFloat(form.sueldo_base) || 0) +
    (parseFloat(form.bonos) || 0) -
    (parseFloat(form.dscto_tardanza) || 0) -
    (parseFloat(form.dscto_adelantos) || 0)
  )

  const generarBoleta = useMutation({
    mutationFn: async () => {
      const resp = await api.post('/v1/constancias/boleta', {
        agente_id: Number(agenteId),
        fecha_inicio: form.fecha_inicio,
        fecha_fin: form.fecha_fin,
        sueldo_base: parseFloat(form.sueldo_base) || 0,
        bonos: parseFloat(form.bonos) || 0,
        dscto_tardanza: parseFloat(form.dscto_tardanza) || 0,
        dscto_adelantos: parseFloat(form.dscto_adelantos) || 0,
        total_neto: totalNeto,
      }, { responseType: 'blob' })
      const url = URL.createObjectURL(resp.data as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `boleta_${nombre}_${form.fecha_inicio}.pdf`
      a.click()
      URL.revokeObjectURL(url)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['agente-boletas', agenteId] })
      setShowForm(false)
      setForm({ fecha_inicio: '', fecha_fin: '', sueldo_base: '', bonos: '0', dscto_tardanza: '0', dscto_adelantos: '0' })
    },
  })

  const boletas = data ?? []

  return (
    <section className="kyro-card p-5">
      <div className="mb-3 flex items-center justify-between gap-2">
        <div>
          <h3 className="flex items-center gap-2 text-sm font-semibold text-kyro-text"><Receipt size={15} className="text-kyro-gold" /> Boletas</h3>
          <p className="text-xs text-kyro-muted">Liquidaciones generadas para el agente.</p>
        </div>
        <Button size="sm" variant="gold" onClick={() => setShowForm(v => !v)}>
          <Plus size={13} /> Generar
        </Button>
      </div>

      {showForm && (
        <div className="mb-4 rounded-kyro border border-kyro-border bg-kyro-surface p-4 space-y-3">
          <p className="text-xs font-semibold text-kyro-text">Nueva boleta de pago</p>
          <div className="flex flex-wrap gap-3">
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Desde</label>
              <Input type="date" value={form.fecha_inicio} onChange={e => setForm(f => ({ ...f, fecha_inicio: e.target.value }))} className="kyro-input" />
            </div>
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Hasta</label>
              <Input type="date" value={form.fecha_fin} onChange={e => setForm(f => ({ ...f, fecha_fin: e.target.value }))} className="kyro-input" />
            </div>
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Sueldo base (S/)</label>
              <Input type="number" min="0" step="0.01" value={form.sueldo_base} onChange={e => setForm(f => ({ ...f, sueldo_base: e.target.value }))} className="kyro-input w-28" />
            </div>
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Bonos (S/)</label>
              <Input type="number" min="0" step="0.01" value={form.bonos} onChange={e => setForm(f => ({ ...f, bonos: e.target.value }))} className="kyro-input w-24" />
            </div>
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Dscto. tardanza</label>
              <Input type="number" min="0" step="0.01" value={form.dscto_tardanza} onChange={e => setForm(f => ({ ...f, dscto_tardanza: e.target.value }))} className="kyro-input w-28" />
            </div>
            <div>
              <label className="block text-xs text-kyro-muted mb-1">Dscto. adelantos</label>
              <Input type="number" min="0" step="0.01" value={form.dscto_adelantos} onChange={e => setForm(f => ({ ...f, dscto_adelantos: e.target.value }))} className="kyro-input w-28" />
            </div>
          </div>
          <div className="flex items-center gap-4">
            <p className="text-sm font-bold text-kyro-gold">Total neto: {fmt(totalNeto)}</p>
            <Button size="sm" variant="gold"
              disabled={!form.fecha_inicio || !form.fecha_fin || !form.sueldo_base || totalNeto <= 0 || generarBoleta.isPending}
              onClick={() => generarBoleta.mutate()}
            >
              {generarBoleta.isPending ? 'Generando...' : 'Generar y Descargar PDF'}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setShowForm(false)}>Cancelar</Button>
          </div>
          {generarBoleta.isError && (
            <p className="text-xs text-kyro-danger">
              {(generarBoleta.error as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Error al generar la boleta.'}
            </p>
          )}
        </div>
      )}

      <div className="max-h-64 divide-y divide-kyro-border overflow-y-auto rounded-kyro border border-kyro-border">
        {boletas.length === 0 && <p className="p-4 text-center text-xs text-kyro-muted">Sin boletas generadas.</p>}
        {boletas.map(boleta => (
          <div key={boleta.id} className="p-3">
            <div className="flex items-center justify-between gap-2">
              <div>
                <p className="text-xs font-medium text-kyro-text">{boleta.fecha_inicio} al {boleta.fecha_fin}</p>
                <p className="font-mono text-sm font-bold text-kyro-gold">{fmt(boleta.total_pagado)}</p>
              </div>
              <Badge variant={boleta.estado === 'PAGADO' ? 'success' : 'warning'}>{boleta.estado}</Badge>
            </div>
            <div className="mt-2 flex flex-wrap gap-2">
              <Button size="sm" variant="glassInfo" onClick={() => descargar(`/v1/constancias/boleta/${boleta.id}`, `boleta_${nombre}_${boleta.id}.pdf`)}>
                <Download size={13} /> PDF
              </Button>
              {boleta.estado === 'PENDIENTE' && <Button size="sm" variant="gold" onClick={() => accion.mutate({ id: boleta.id, accion: 'pagar' })}>Marcar pagado</Button>}
              <Button size="sm" variant="glassDanger" onClick={() => confirm('Eliminar esta boleta?') && accion.mutate({ id: boleta.id, accion: 'eliminar' })}>
                <Trash2 size={13} />
              </Button>
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}

function SeguridadDispositivoPanel({ agenteId }: { agenteId: string }) {
  const qc = useQueryClient()
  const { data } = useQuery({
    queryKey: ['agente-seguridad', agenteId],
    queryFn: () => api.get(`/v1/agentes/${agenteId}/seguridad`).then(r => r.data),
  })
  const reset = useMutation({
    mutationFn: () => api.post(`/v1/agentes/${agenteId}/reset-dispositivo`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['agente-seguridad', agenteId] }),
  })

  return (
    <section className="kyro-card p-5">
      <h3 className="mb-1 flex items-center gap-2 text-sm font-semibold text-kyro-text"><Smartphone size={15} className="text-kyro-gold" /> Dispositivo</h3>
      <p className="mb-3 text-xs text-kyro-muted">Vinculacion del celular y token activo.</p>
      <div className="space-y-2 text-xs text-kyro-body">
        <p>Dispositivo: <strong>{data?.dispositivo_vinculado ? 'Vinculado' : 'Sin vincular'}</strong></p>
        <p>Tienda inicial: <strong>{data?.tienda_registro_inicial ?? '-'}</strong></p>
        <p>Token: <strong>{data?.tiene_token ? `${data.token} (${data.tipo_token})` : 'Sin token activo'}</strong></p>
      </div>
      <Button
        size="sm"
        variant="destructive"
        className="mt-4"
        disabled={reset.isPending}
        onClick={() => confirm('Desvincular el dispositivo y revocar el token?') && reset.mutate()}
      >
        {reset.isPending ? 'Procesando...' : 'Resetear dispositivo'}
      </Button>
    </section>
  )
}

const TIPO_CAMBIO_BADGE: Record<string, { variant: 'default' | 'success' | 'warning' | 'destructive' | 'outline'; label: string }> = {
  CESE:      { variant: 'destructive', label: 'Cese' },
  REINGRESO: { variant: 'success',     label: 'Reingreso' },
  TIENDA:    { variant: 'default',      label: 'Tienda' },
  HORARIO:   { variant: 'warning',      label: 'Horario' },
  FICHA:     { variant: 'outline',      label: 'Ficha' },
  ESTADO:    { variant: 'outline',      label: 'Estado' },
}

function HistorialAgenteModal({ agenteId, onClose }: { agenteId: string; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['agente-historial', agenteId],
    queryFn: () => agentesApi.historial(Number(agenteId)),
  })
  const eventos: HistorialAgenteEvento[] = data ?? []

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true" onClick={onClose}>
      <div className="kyro-card relative flex max-h-[80vh] w-full max-w-2xl flex-col overflow-hidden" onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between border-b border-kyro-border p-4">
          <h3 className="flex items-center gap-2 text-sm font-semibold text-kyro-text">
            <History size={15} className="text-kyro-gold" /> Historial del agente
          </h3>
          <Button size="iconSm" variant="ghost" aria-label="Cerrar" onClick={onClose}><X size={16} /></Button>
        </div>
        <div className="overflow-y-auto p-4">
          {isLoading && <p className="py-8 text-center text-sm text-kyro-muted">Cargando historial...</p>}
          {!isLoading && eventos.length === 0 && (
            <p className="py-8 text-center text-sm text-kyro-muted">Sin eventos registrados.</p>
          )}
          <ul className="space-y-3">
            {eventos.map(ev => {
              const badge = TIPO_CAMBIO_BADGE[ev.tipo_cambio] ?? { variant: 'outline' as const, label: ev.tipo_cambio }
              return (
                <li key={ev.id} className="rounded-kyro border border-kyro-border bg-kyro-elevated p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <Badge variant={badge.variant}>{badge.label}</Badge>
                    <span className="font-mono text-xs text-kyro-muted">
                      {new Date(ev.fecha_registro.replace(' ', 'T')).toLocaleString('es-PE')}
                    </span>
                  </div>
                  <div className="mt-2 space-y-1 text-xs text-kyro-body">
                    <p>Estado: <strong>{ev.estado}</strong></p>
                    {ev.clasificacion_baja && <p>Clasificación: <strong>{ev.clasificacion_baja}</strong></p>}
                    {ev.observacion && <p>Observación: {ev.observacion}</p>}
                    {(ev.campo_anterior || ev.campo_nuevo) && (
                      <p className="text-kyro-muted">
                        <span className="text-kyro-danger">{ev.campo_anterior ?? '—'}</span>
                        {' → '}
                        <span className="text-kyro-success">{ev.campo_nuevo ?? '—'}</span>
                      </p>
                    )}
                  </div>
                </li>
              )
            })}
          </ul>
        </div>
      </div>
    </div>
  )
}

export function VerAgentePage() {
  const { id }       = useParams<{ id: string }>()
  const navigate     = useNavigate()
  const { usuario }  = useAuth()
  const isAdmin      = usuario?.rol === 'admin'
  const [page, setPage] = useState(1)
  const [mostrarHistorial, setMostrarHistorial] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['agente-ventas', id, page],
    queryFn: () =>
      api.get<AgenteVentasResponse>(`/v1/agentes/${id}/ventas`, { params: { page, per_page: 15 } }).then(r => r.data),
    enabled: !!id,
  })

  const agente  = data?.agente
  const stats   = data?.stats
  const reportes = data?.reportes?.data ?? []
  const meta    = data?.reportes

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center text-sm text-kyro-muted">
        <span className="mr-2 h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
        Cargando perfil...
      </div>
    )
  }

  if (!agente) {
    return (
      <div className="space-y-4 py-12 text-center">
        <p className="text-kyro-muted">Agente no encontrado</p>
        <Link to="/agentes"><Button variant="outline"><ArrowLeft size={14} /> Volver a Agentes</Button></Link>
      </div>
    )
  }

  const initials = agente.nombres
    .split(' ')
    .slice(0, 2)
    .map((p: string) => p[0])
    .join('')
    .toUpperCase()

  const dif = Number(stats?.diferencia_acumulada ?? 0)

  return (
    <div className="space-y-6">
      <Link to="/agentes" className="inline-flex items-center gap-1.5 text-sm text-kyro-muted transition-colors hover:text-kyro-gold">
        <ArrowLeft size={15} /> Volver a agentes
      </Link>

      <section className="kyro-card relative overflow-hidden p-5 sm:p-6">
        <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-kyro-indigo via-kyro-gold to-transparent" />
        <div aria-hidden className="absolute -right-16 -top-20 h-48 w-48 rounded-full bg-kyro-indigo/10 blur-3xl" />
        <div className="relative flex flex-wrap items-start gap-5">
          <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-kyro-lg bg-kyro-indigo text-2xl font-bold text-kyro-text shadow-kyro-card">
            {initials}
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="font-orbitron text-xl font-bold tracking-tight text-kyro-text">{agente.nombres}</h1>
              <Badge variant={agente.estado === 'ACTIVO' ? 'success' : agente.estado === 'INACTIVO' ? 'warning' : 'destructive'}>
                {agente.estado}
              </Badge>
              {agente.es_gerencia && String(agente.es_gerencia) !== 'NO' && (
                <Badge>Gerencia</Badge>
              )}
            </div>
            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-sm text-kyro-muted">
              {agente.dni && (
                <span className="flex items-center gap-1"><User size={13} /> DNI: {agente.dni}</span>
              )}
              {agente.tienda_base && (
                <span className="flex items-center gap-1"><MapPin size={13} /> Tienda: {agente.tienda_base}</span>
              )}
              {agente.correo && (
                <span className="flex items-center gap-1"><Mail size={13} /> {agente.correo}</span>
              )}
              {agente.telefono && (
                <span className="flex items-center gap-1"><Phone size={13} /> {agente.telefono}</span>
              )}
              {agente.fecha_ingreso && (
                <span className="flex items-center gap-1">
                  <Calendar size={13} /> Ingreso: {formatearFechaCorta(agente.fecha_ingreso)}
                </span>
              )}
            </div>
          </div>
          <div className="ml-auto rounded-kyro border border-kyro-border bg-kyro-elevated px-4 py-3 text-right">
            <p className="text-[0.68rem] font-semibold uppercase tracking-wider text-kyro-subtle">Sueldo base</p>
            <p className="mt-1 font-mono text-lg font-bold tabular-nums text-kyro-text">{fmt(agente.sueldo_base)}</p>
            {isAdmin && id && (
              <div className="mt-3 flex flex-col items-end gap-2">
                <Button
                  size="sm"
                  variant="glassInfo"
                  onClick={() => descargar(`/v1/constancias/agente/${id}`, `certificado_${agente.nombres.replace(/\s+/g, '_')}.pdf`)}
                >
                  <Download size={13} /> Certificado
                </Button>
                <Button size="sm" variant="outline" onClick={() => setMostrarHistorial(true)}>
                  <History size={13} /> Historial
                </Button>
              </div>
            )}
          </div>
        </div>
      </section>

      {stats && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="kyro-card border-l-4 border-l-kpi-total p-4">
            <div className="mb-2 flex items-center gap-2 text-kyro-muted">
              <FileText size={14} />
              <p className="text-xs">Total reportes</p>
            </div>
            <p className="text-xl font-bold text-kyro-text">{stats.total_reportes}</p>
          </div>
          <div className="kyro-card border-l-4 border-l-kpi-total p-4">
            <div className="mb-2 flex items-center gap-2 text-kyro-gold">
              <DollarSign size={14} />
              <p className="text-xs">Total vendido</p>
            </div>
            <p className="font-mono text-xl font-bold tabular-nums text-kyro-text">{fmt(stats.total_vendido)}</p>
          </div>
          <div className={`kyro-card border-l-4 p-4 ${dif < 0 ? 'border-l-kyro-danger' : dif > 0 ? 'border-l-kyro-warning' : 'border-l-kpi-neutral'}`}>
            <div className="mb-2 flex items-center gap-2 text-kyro-muted">
              <TrendingUp size={14} />
              <p className="text-xs">Diferencia acumulada</p>
            </div>
            <p className={`font-mono text-xl font-bold tabular-nums ${dif < 0 ? 'text-kyro-danger' : dif > 0 ? 'text-kyro-warning' : 'text-kyro-body'}`}>
              {dif > 0 ? '+' : ''}{fmt(dif)}
            </p>
          </div>
        </div>
      )}

      {isAdmin && id && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <FechasLaboralesPanel agenteId={id} agente={agente} />
          <TokenSeguridadPanel agenteId={id} />
          <AdelantosPanel agenteId={id} />
          <SeguridadDispositivoPanel agenteId={id} />
          <BoletasPanel agenteId={id} nombre={agente.nombres.replace(/\s+/g, '_')} />
          <PerfilRrhhPanel agenteId={id} />
          <DocumentosAgentePanel agenteId={Number(id)} />
        </div>
      )}

      <section className="kyro-card relative overflow-hidden">
        <div aria-hidden className="absolute inset-x-0 top-0 z-20 h-px bg-gradient-to-r from-kyro-gold via-kyro-indigo to-transparent" />
        <div className="flex flex-col gap-2 border-b border-kyro-border p-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-sm font-semibold text-kyro-text">Historial de reportes</h2>
            <p className="mt-0.5 text-xs text-kyro-muted">Movimientos y cierres asociados al agente.</p>
          </div>
          <span className="text-xs tabular-nums text-kyro-muted">
            {meta?.total ?? 0} reportes · Pág. {meta?.current_page ?? 1}/{meta?.last_page ?? 1}
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full border-separate border-spacing-0 text-sm">
            <thead>
              <tr>
                {['ID', 'Fecha', 'Tienda', 'Total', 'F. Entregado', 'Diferencia', 'Estado', ''].map(h => (
                  <th key={h} className={`kyro-table-head px-4 py-3 ${['Total', 'F. Entregado', 'Diferencia'].includes(h) ? 'text-right' : 'text-left'}`}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {reportes.length === 0 && (
                <tr><td colSpan={8} className="px-4 py-10 text-center text-kyro-muted">Sin reportes</td></tr>
              )}
              {reportes.map(r => {
                const difR = Number(r.diferencia)
                return (
                  <tr key={r.id} className={`transition-colors [&>td]:border-b [&>td]:border-kyro-border ${difR < 0 ? 'bg-kyro-danger/5' : 'hover:bg-kyro-elevated'}`}>
                    <td className="px-4 py-3 font-mono text-xs text-kyro-muted">#{String(r.id).padStart(4, '0')}</td>
                    <td className="px-4 py-3 text-kyro-body">{new Date(r.fecha + 'T00:00:00').toLocaleDateString('es-PE')}</td>
                    <td className="px-4 py-3"><span className="rounded-kyro bg-kyro-elevated px-1.5 py-0.5 font-mono text-xs text-kyro-body">{r.tienda_id}</span></td>
                    <td className="px-4 py-3 text-right font-mono text-kyro-body">{fmt(r.total_calculado)}</td>
                    <td className="px-4 py-3 text-right font-mono text-kyro-body">{fmt(r.efectivo_entregado)}</td>
                    <td className="px-4 py-3 text-right">
                      <span className={`inline-block rounded-kyro px-2 py-0.5 text-xs font-bold ${difR === 0 ? 'bg-kyro-elevated text-kyro-muted' : difR < 0 ? 'bg-kyro-danger/10 text-kyro-danger' : 'bg-kyro-warning/10 text-kyro-warning'}`}>
                        {difR > 0 ? '+' : ''}{fmt(difR)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${{ borrador: 'bg-kyro-elevated text-kyro-muted', enviado: 'bg-kyro-info/10 text-kyro-info', editado: 'bg-kyro-warning/10 text-kyro-warning', aprobado: 'bg-kyro-success/10 text-kyro-success' }[r.estado] ?? 'bg-kyro-elevated text-kyro-muted'}`}>
                        {r.estado}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <TableActions>
                        <ActionIconButton
                          tone="view"
                          label="Ver reporte"
                          icon={<Eye size={15} />}
                          onClick={() => navigate(`/reportes/${r.id}`)}
                        />
                      </TableActions>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-kyro-border bg-kyro-elevated p-3.5">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
              <ChevronLeft size={14} /> Anterior
            </Button>
            <span className="text-xs text-kyro-muted">{meta.from}–{meta.to} de {meta.total}</span>
            <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}>
              Siguiente <ChevronRight size={14} />
            </Button>
          </div>
        )}
      </section>

      {mostrarHistorial && id && (
        <HistorialAgenteModal agenteId={id} onClose={() => setMostrarHistorial(false)} />
      )}
    </div>
  )
}
