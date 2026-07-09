import { useEffect, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Plus, PencilSimple as Pencil, Trash as Trash2, ArrowsClockwise as RefreshCw, SealCheck as CheckCircle2, WarningCircle as AlertCircle, ChartLineUp as TrendingUp, Coins, ShieldCheck, Sliders, DeviceMobile as DeviceMobileIcon, Phone as PhoneIcon, CreditCard, Handshake } from '@phosphor-icons/react'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'

// ── Types ────────────────────────────────────────────────────────────────────

interface ComisionPlan {
  id: number
  tipo_servicio: string
  nombre_plan: string
  tipo_alta: string | null
  fee_monto: number | null
  comision_dni_n: number | null
  comision_dni_n3: number | null
  comision_ext_n: number | null
  comision_ext_n3: number | null
}

interface RecalcularResult {
  success: boolean
  ventas_actualizadas: number
  lineas_actualizadas: number
  operativas_actualizadas: number
  periodo: string
  message: string
  error?: string
}

// ── API calls ────────────────────────────────────────────────────────────────

const comisionesApi = {
  list: (tipo?: string) =>
    api.get<ComisionPlan[]>('/v1/comisiones-planes', { params: tipo ? { tipo_servicio: tipo } : {} }).then(r => r.data),
  create: (data: Partial<ComisionPlan>) =>
    api.post<ComisionPlan>('/v1/comisiones-planes', data).then(r => r.data),
  update: (id: number, data: Partial<ComisionPlan>) =>
    api.put<ComisionPlan>(`/v1/comisiones-planes/${id}`, data).then(r => r.data),
  destroy: (id: number) =>
    api.delete(`/v1/comisiones-planes/${id}`),
  recalcular: (body: { fecha_desde: string; fecha_hasta: string; tienda_id?: string }) =>
    api.post<RecalcularResult>('/v1/comisiones-planes/recalcular', body).then(r => r.data),
}

// ── Form schema ──────────────────────────────────────────────────────────────

const planSchema = z.object({
  tipo_servicio: z.enum(['POSTPAGO', 'PREPAGO', 'EQUIPO', 'ACCESORIO', 'OTROS']),
  nombre_plan:   z.string().min(1, 'Nombre requerido').max(100),
  tipo_alta:     z.string().max(30).optional().or(z.literal('')),
  fee_monto:     z.number().min(0).optional().nullable(),
  comision_dni_n:  z.number().min(0).optional().nullable(),
  comision_dni_n3: z.number().min(0).optional().nullable(),
  comision_ext_n:  z.number().min(0).optional().nullable(),
  comision_ext_n3: z.number().min(0).optional().nullable(),
})
type PlanFormData = z.infer<typeof planSchema>

const recalcSchema = z.object({
  fecha_desde: z.string().min(1),
  fecha_hasta: z.string().min(1),
  tienda_id:   z.string().optional().or(z.literal('')),
})
type RecalcFormData = z.infer<typeof recalcSchema>

// ── Currency helpers ─────────────────────────────────────────────────────────

const pen = (v: number | null | undefined) =>
  v != null ? `S/ ${Number(v).toFixed(2)}` : '—'

const TYPE_COLORS: Record<string, string> = {
  POSTPAGO:  'bg-kyro-info/15 text-kyro-info',
  PREPAGO:   'bg-kyro-success/15 text-kyro-success',
  EQUIPO:    'bg-kyro-indigo/20 text-kyro-body',
  ACCESORIO: 'bg-kyro-warning/15 text-kyro-warning',
  OTROS:     'bg-kyro-elevated text-kyro-muted',
}

const TIPO_OPTIONS = [
  { value: '', label: 'Todos', tone: 'indigo' as const },
  { value: 'POSTPAGO', label: 'Postpago', tone: 'info' as const },
  { value: 'PREPAGO', label: 'Prepago', tone: 'success' as const },
  { value: 'EQUIPO', label: 'Equipo', tone: 'gold' as const },
  { value: 'ACCESORIO', label: 'Accesorio', tone: 'warning' as const },
  { value: 'OTROS', label: 'Otros', tone: 'indigo' as const },
]

// ── Plan Form modal ───────────────────────────────────────────────────────────

function PlanForm({ plan, onSuccess, onCancel }: { plan?: ComisionPlan; onSuccess: () => void; onCancel: () => void }) {
  const qc = useQueryClient()
  const crear    = useMutation({ mutationFn: comisionesApi.create,                    onSuccess: () => { qc.invalidateQueries({ queryKey: ['comisiones-planes'] }); onSuccess() } })
  const editar   = useMutation({ mutationFn: (d: Partial<ComisionPlan>) => comisionesApi.update(plan!.id, d), onSuccess: () => { qc.invalidateQueries({ queryKey: ['comisiones-planes'] }); onSuccess() } })

  const { register, handleSubmit, formState: { errors } } = useForm<PlanFormData>({
    resolver: zodResolver(planSchema),
    defaultValues: plan ? {
      tipo_servicio:   plan.tipo_servicio as PlanFormData['tipo_servicio'],
      nombre_plan:     plan.nombre_plan,
      tipo_alta:       plan.tipo_alta ?? '',
      fee_monto:       plan.fee_monto ?? undefined,
      comision_dni_n:  plan.comision_dni_n ?? undefined,
      comision_dni_n3: plan.comision_dni_n3 ?? undefined,
      comision_ext_n:  plan.comision_ext_n ?? undefined,
      comision_ext_n3: plan.comision_ext_n3 ?? undefined,
    } : { tipo_servicio: 'POSTPAGO' },
  })

  const onSubmit = (data: PlanFormData) => {
    if (plan) editar.mutate(data)
    else crear.mutate(data)
  }

  const isPending = crear.isPending || editar.isPending
  const err       = (crear.error || editar.error) as { response?: { data?: { message?: string } } } | null

  const numInput = (id: string, label: string, reg: Parameters<typeof register>[0]) => (
    <div>
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} type="number" step="0.01" min="0" {...register(reg, { valueAsNumber: true })} className="mt-1" />
    </div>
  )

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="tipo_servicio">Tipo de servicio *</Label>
          <Select id="tipo_servicio" {...register('tipo_servicio')} className="mt-1">
            <option value="POSTPAGO">POSTPAGO</option>
            <option value="PREPAGO">PREPAGO</option>
            <option value="EQUIPO">EQUIPO</option>
            <option value="ACCESORIO">ACCESORIO</option>
            <option value="OTROS">OTROS</option>
          </Select>
          {errors.tipo_servicio && <p className="text-kyro-danger text-xs mt-1">{errors.tipo_servicio.message}</p>}
        </div>
        <div>
          <Label htmlFor="nombre_plan">Nombre del plan *</Label>
          <Input id="nombre_plan" {...register('nombre_plan')} placeholder="Plan Libre 25GB" className="mt-1" />
          {errors.nombre_plan && <p className="text-kyro-danger text-xs mt-1">{errors.nombre_plan.message}</p>}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <Label htmlFor="tipo_alta">Tipo de alta</Label>
          <Input id="tipo_alta" {...register('tipo_alta')} placeholder="NUEVA, UPGRADE, MIGRACION..." className="mt-1" />
        </div>
        {numInput('fee_monto', 'Fee (S/)', 'fee_monto')}
      </div>

      <div className="grid grid-cols-2 gap-4">
        {numInput('comision_dni_n', 'Comisión DNI N1 (S/)', 'comision_dni_n')}
        {numInput('comision_dni_n3', 'Comisión DNI N3 (S/)', 'comision_dni_n3')}
      </div>

      <div className="grid grid-cols-2 gap-4">
        {numInput('comision_ext_n', 'Comisión EXT N1 (S/)', 'comision_ext_n')}
        {numInput('comision_ext_n3', 'Comisión EXT N3 (S/)', 'comision_ext_n3')}
      </div>

      {err && <p className="text-kyro-danger text-sm">{err.response?.data?.message ?? 'Error al guardar.'}</p>}

      <div className="flex gap-3 pt-2">
        <Button type="submit" variant="gold" disabled={isPending} className="flex-1">
          {isPending ? 'Guardando...' : plan ? 'Actualizar plan' : 'Crear plan'}
        </Button>
        <Button type="button" variant="outline" onClick={onCancel} disabled={isPending}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}

// ── Recalculo Modal ───────────────────────────────────────────────────────────

function RecalcularModal({ onClose }: { onClose: () => void }) {
  const [resultado, setResultado] = useState<RecalcularResult | null>(null)
  const mutation = useMutation({ mutationFn: comisionesApi.recalcular, onSuccess: setResultado })

  const { register, handleSubmit, formState: { errors } } = useForm<RecalcFormData>({
    resolver: zodResolver(recalcSchema),
    defaultValues: {
      fecha_desde: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
      fecha_hasta: new Date().toISOString().slice(0, 10),
    },
  })

  const onSubmit = (data: RecalcFormData) => {
    setResultado(null)
    mutation.mutate({ fecha_desde: data.fecha_desde, fecha_hasta: data.fecha_hasta, tienda_id: data.tienda_id || undefined })
  }

  return (
    <div className="space-y-5">
      <div>
        <p className="text-sm text-kyro-body mb-4">
          Recalcula <code className="bg-kyro-elevated px-1 rounded">comision_unitaria</code> en <code className="bg-kyro-elevated px-1 rounded">venta_lineas</code> y
          <code className="bg-kyro-elevated px-1 rounded mx-1">comision_generada</code> en <code className="bg-kyro-elevated px-1 rounded">ventas</code> usando las tarifas actuales.
        </p>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label htmlFor="rec_desde">Fecha desde *</Label>
              <Input id="rec_desde" type="date" {...register('fecha_desde')} className="mt-1" />
              {errors.fecha_desde && <p className="text-kyro-danger text-xs mt-1">{errors.fecha_desde.message}</p>}
            </div>
            <div>
              <Label htmlFor="rec_hasta">Fecha hasta *</Label>
              <Input id="rec_hasta" type="date" {...register('fecha_hasta')} className="mt-1" />
              {errors.fecha_hasta && <p className="text-kyro-danger text-xs mt-1">{errors.fecha_hasta.message}</p>}
            </div>
          </div>

          <div>
            <Label htmlFor="rec_tienda">Tienda (dejar vacío para todas)</Label>
            <Input id="rec_tienda" {...register('tienda_id')} placeholder="PUNDA11" className="mt-1" />
          </div>

          <Button type="submit" variant="gold" disabled={mutation.isPending} className="w-full">
            {mutation.isPending ? 'Recalculando...' : 'Ejecutar recálculo'}
          </Button>
        </form>
      </div>

      {resultado && (
        <div className={`rounded-kyro p-4 border ${resultado.success ? 'bg-kyro-success/10 border-kyro-success' : 'bg-kyro-danger/10 border-kyro-danger'}`}>
          <div className="flex items-center gap-2 mb-2">
            {resultado.success
              ? <CheckCircle2 size={18} className="text-kyro-success" />
              : <AlertCircle size={18} className="text-kyro-danger" />}
            <span className="font-medium text-sm">{resultado.success ? 'Recálculo completado' : 'Error en recálculo'}</span>
          </div>
          <p className="text-sm text-kyro-body">{resultado.message ?? resultado.error}</p>
          {resultado.success && (
            <div className="mt-2 grid grid-cols-2 gap-4 text-sm">
              <div><span className="text-kyro-muted">Ventas actualizadas:</span> <span className="font-semibold">{resultado.ventas_actualizadas}</span></div>
              <div><span className="text-kyro-muted">Líneas actualizadas:</span> <span className="font-semibold">{resultado.lineas_actualizadas}</span></div>
              <div><span className="text-kyro-muted">Ganancias operativas:</span> <span className="font-semibold">{resultado.operativas_actualizadas}</span></div>
              <div className="col-span-2"><span className="text-kyro-muted">Período:</span> <span className="font-semibold">{resultado.periodo}</span></div>
            </div>
          )}
        </div>
      )}

      <div className="flex justify-end">
        <Button variant="outline" onClick={onClose}>Cerrar</Button>
      </div>
    </div>
  )
}

// ── Tarifas operativas Modal (paridad legacy guardar_tarifas_ajax) ─────────────

interface ConfigComisiones {
  tarifas: { ganancia_recargas: number; ganancia_bipay: number; ganancia_krece: number; ganancia_payjoy: number }
  rangos_plan: RangoProductividad[]
  rangos_equipo: RangoProductividad[]
  rangos_servicio: Record<ServicioOperativo, RangoServicio[]>
}

interface RangoProductividad { desde: number; hasta: number; monto: number }
interface RangoServicio { monto_min: number; monto_max: number | null; ganancia: number }
type ServicioOperativo = 'bipay' | 'krece' | 'payjoy'

// ── Color-coding por sección (paridad legacy comisiones_empresa.php / configurar_comisiones.php) ──

const SERVICIO_COLORS: Record<ServicioOperativo, { border: string; text: string; bg: string; icon: typeof CreditCard }> = {
  bipay:  { border: 'border-t-blue-400',   text: 'text-blue-400',   bg: 'bg-blue-500/5 border-blue-500/20',     icon: CreditCard },
  krece:  { border: 'border-t-orange-400', text: 'text-orange-400', bg: 'bg-orange-500/5 border-orange-500/20', icon: Handshake },
  payjoy: { border: 'border-t-purple-400', text: 'text-purple-400', bg: 'bg-purple-500/5 border-purple-500/20', icon: DeviceMobileIcon },
}

function GananciasOperativasSection() {
  const { data, isLoading } = useQuery({
    queryKey: ['config-comisiones'],
    queryFn: () => api.get<ConfigComisiones>('/v1/config-comisiones').then(r => r.data),
  })

  const [form, setForm] = useState({ ganancia_recargas: '', ganancia_bipay: '', ganancia_krece: '', ganancia_payjoy: '' })
  const [msg, setMsg] = useState<string | null>(null)
  const [cargado, setCargado] = useState(false)

  /* eslint-disable react-hooks/set-state-in-effect */
  useEffect(() => {
    if (!data || cargado) return
    setForm({
      ganancia_recargas: String(data.tarifas.ganancia_recargas ?? 0),
      ganancia_bipay:    String(data.tarifas.ganancia_bipay ?? 0),
      ganancia_krece:    String(data.tarifas.ganancia_krece ?? 0),
      ganancia_payjoy:   String(data.tarifas.ganancia_payjoy ?? 0),
    })
    setCargado(true)
  }, [cargado, data])
  /* eslint-enable react-hooks/set-state-in-effect */

  const guardar = useMutation({
    mutationFn: () => api.put('/v1/config-comisiones/tarifas', {
      ganancia_recargas: Number(form.ganancia_recargas),
      ganancia_bipay:    Number(form.ganancia_bipay),
      ganancia_krece:    Number(form.ganancia_krece),
      ganancia_payjoy:   Number(form.ganancia_payjoy),
    }).then(r => r.data),
    onSuccess: (r: { msg?: string }) => setMsg(r.msg ?? 'Tarifas guardadas.'),
    onError: () => setMsg('Error al guardar las tarifas.'),
  })

  const field = (key: keyof typeof form, label: string, suffix: string, colorClass: string) => (
    <div>
      <Label htmlFor={key} className={colorClass}>{label}</Label>
      <div className="relative mt-1">
        <Input id={key} type="number" step="0.01" min="0" value={form[key]}
          onChange={e => setForm(f => ({ ...f, [key]: e.target.value }))} className="pr-10" />
        <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-kyro-muted">{suffix}</span>
      </div>
    </div>
  )

  return (
    <section id="ganancias-operativas" className="kyro-card relative overflow-hidden border-t-4 border-t-emerald-400 p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="flex items-center gap-2 text-sm font-semibold text-emerald-400">
            <Coins size={16} /> Ganancias Operativas por Servicio
          </h3>
          <p className="mt-1 text-xs text-kyro-muted">
            Guarda las tarifas actuales (no modifica el historial) o usa Recálculo Masivo para corregir períodos pasados.
          </p>
        </div>
        <Button variant="gold" size="sm" disabled={guardar.isPending || isLoading} onClick={() => { setMsg(null); guardar.mutate() }}>
          {guardar.isPending ? 'Guardando...' : 'Guardar Tarifas'}
        </Button>
      </div>

      {isLoading ? (
        <p className="py-6 text-center text-sm text-kyro-muted">Cargando configuración...</p>
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {field('ganancia_recargas', 'Recargas (% del monto)', '%', 'text-emerald-400')}
          {field('ganancia_bipay', 'Bipay (fijo)', 'S/', 'text-blue-400')}
          {field('ganancia_krece', 'Krece (fijo)', 'S/', 'text-orange-400')}
          {field('ganancia_payjoy', 'Payjoy (fijo)', 'S/', 'text-purple-400')}
        </div>
      )}

      {msg && (
        <p className={`mt-4 rounded-kyro px-3 py-2 text-sm ${msg.startsWith('Error') ? 'bg-kyro-danger/10 text-kyro-danger' : 'bg-kyro-success/10 text-kyro-success'}`}>{msg}</p>
      )}

      <p className="mt-4 flex items-start gap-2 rounded-kyro border border-emerald-500/20 bg-emerald-500/5 p-3 text-xs text-kyro-muted">
        <ShieldCheck size={15} className="mt-0.5 shrink-0 text-emerald-400" />
        <span><strong className="text-emerald-400">Blindaje de datos:</strong> «Guardar Tarifas» actualiza solo la configuración — los reportes existentes no se modifican. Para corregir el historial, usa Recálculo Masivo con un rango de fechas exacto.</span>
      </p>
    </section>
  )
}

function EstrategiaComisionesSection() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['config-comisiones'],
    queryFn: () => api.get<ConfigComisiones>('/v1/config-comisiones').then(r => r.data),
  })
  const [plan, setPlan] = useState<RangoProductividad[]>([])
  const [equipo, setEquipo] = useState<RangoProductividad[]>([])
  const [servicios, setServicios] = useState<Record<ServicioOperativo, RangoServicio[]>>({
    bipay: [], krece: [], payjoy: [],
  })
  const [msg, setMsg] = useState('')

  /* eslint-disable react-hooks/set-state-in-effect */
  useEffect(() => {
    if (!data) return
    setPlan(data.rangos_plan)
    setEquipo(data.rangos_equipo)
    setServicios({
      bipay: data.rangos_servicio.bipay ?? [],
      krece: data.rangos_servicio.krece ?? [],
      payjoy: data.rangos_servicio.payjoy ?? [],
    })
  }, [data])
  /* eslint-enable react-hooks/set-state-in-effect */

  const guardarProductividad = useMutation({
    mutationFn: ({ tipo, rangos }: { tipo: 'PLAN' | 'EQUIPO'; rangos: RangoProductividad[] }) =>
      api.put<{ msg: string }>('/v1/config-comisiones/rangos-productividad', { tipo, rangos }).then(r => r.data),
    onSuccess: r => { setMsg(r.msg); qc.invalidateQueries({ queryKey: ['config-comisiones'] }) },
  })
  const guardarServicio = useMutation({
    mutationFn: ({ tipo_servicio, rangos }: { tipo_servicio: ServicioOperativo; rangos: RangoServicio[] }) =>
      api.put<{ msg: string }>('/v1/config-comisiones/rangos-servicio', { tipo_servicio, rangos }).then(r => r.data),
    onSuccess: r => { setMsg(r.msg); qc.invalidateQueries({ queryKey: ['config-comisiones'] }) },
  })

  const productividad = (
    titulo: string,
    tipo: 'PLAN' | 'EQUIPO',
    rangos: RangoProductividad[],
    setRangos: React.Dispatch<React.SetStateAction<RangoProductividad[]>>,
    color: { border: string; text: string; bg: string; icon: typeof PhoneIcon },
    banner: string,
  ) => {
    const Icon = color.icon
    return (
      <section className={`relative overflow-hidden rounded-kyro border-t-4 ${color.border} bg-kyro-elevated/40 p-4`}>
        <div className="mb-3 flex items-center justify-between">
          <div>
            <h4 className={`flex items-center gap-1.5 text-sm font-semibold ${color.text}`}>
              <Icon size={15} /> {titulo}
            </h4>
          </div>
          <Button size="sm" variant="outline" onClick={() => setRangos(r => [...r, { desde: 1, hasta: 9999, monto: 0 }])}>
            <Plus size={13} /> Agregar Rango
          </Button>
        </div>
        <p className={`mb-3 rounded-kyro border p-2.5 text-xs text-kyro-muted ${color.bg}`}>{banner}</p>
        <div className="space-y-2">
          {rangos.map((r, i) => (
            <div key={i} className="grid grid-cols-[1fr_1fr_1fr_auto] gap-2">
              <Input type="number" min="1" value={r.desde} onChange={e => setRangos(v => v.map((x, j) => j === i ? { ...x, desde: Number(e.target.value) } : x))} placeholder="Desde" />
              <Input type="number" min="1" value={r.hasta} onChange={e => setRangos(v => v.map((x, j) => j === i ? { ...x, hasta: Number(e.target.value) } : x))} placeholder="Hasta" />
              <Input type="number" min="0" step="0.01" value={r.monto} onChange={e => setRangos(v => v.map((x, j) => j === i ? { ...x, monto: Number(e.target.value) } : x))} placeholder="S/" />
              <Button size="iconSm" variant="glassDanger" aria-label="Eliminar rango" onClick={() => setRangos(v => v.filter((_, j) => j !== i))}><Trash2 size={14} /></Button>
            </div>
          ))}
        </div>
        <Button variant="gold" className="mt-3 w-full" disabled={guardarProductividad.isPending}
          onClick={() => guardarProductividad.mutate({ tipo, rangos })}>
          Guardar {tipo}
        </Button>
      </section>
    )
  }

  const servicio = (tipo: ServicioOperativo) => {
    const rangos = servicios[tipo]
    const setRangos = (next: RangoServicio[]) => setServicios(s => ({ ...s, [tipo]: next }))
    const color = SERVICIO_COLORS[tipo]
    const Icon = color.icon
    return (
      <section key={tipo} className={`relative overflow-hidden rounded-kyro border-t-4 ${color.border} bg-kyro-elevated/40 p-4`}>
        <div className="mb-3 flex items-center justify-between">
          <h4 className={`flex items-center gap-1.5 text-sm font-semibold uppercase ${color.text}`}>
            <Icon size={15} /> {tipo} — por monto
          </h4>
          <Button size="sm" variant="outline" onClick={() => setRangos([...rangos, { monto_min: 0, monto_max: null, ganancia: 0 }])}>
            <Plus size={13} /> Agregar
          </Button>
        </div>
        <div className="space-y-2">
          {rangos.map((r, i) => (
            <div key={i} className="grid grid-cols-[1fr_1fr_1fr_auto] gap-2">
              <Input type="number" min="0" step="0.01" value={r.monto_min} onChange={e => setRangos(rangos.map((x, j) => j === i ? { ...x, monto_min: Number(e.target.value) } : x))} placeholder="Mínimo" />
              <Input type="number" min="0" step="0.01" value={r.monto_max ?? ''} onChange={e => setRangos(rangos.map((x, j) => j === i ? { ...x, monto_max: e.target.value === '' ? null : Number(e.target.value) } : x))} placeholder="Sin límite" />
              <Input type="number" min="0" step="0.01" value={r.ganancia} onChange={e => setRangos(rangos.map((x, j) => j === i ? { ...x, ganancia: Number(e.target.value) } : x))} placeholder="Ganancia" />
              <Button size="iconSm" variant="glassDanger" aria-label="Eliminar rango" onClick={() => setRangos(rangos.filter((_, j) => j !== i))}><Trash2 size={14} /></Button>
            </div>
          ))}
        </div>
        <p className="mt-2 text-[11px] text-kyro-muted">El sistema evaluará el monto exacto de la operación y aplicará la ganancia del rango que corresponda.</p>
        <Button variant="gold" className="mt-3 w-full" disabled={guardarServicio.isPending}
          onClick={() => guardarServicio.mutate({ tipo_servicio: tipo, rangos })}>
          Guardar {tipo.toUpperCase()}
        </Button>
      </section>
    )
  }

  return (
    <section id="estrategia-comisiones" className="kyro-card relative overflow-hidden p-5">
      <h3 className="mb-1 flex items-center gap-2 text-sm font-semibold text-kyro-text">
        <Sliders size={16} className="text-kyro-info" /> Estrategia de Comisiones
      </h3>
      <p className="mb-4 text-xs text-kyro-muted">Rangos de productividad (por cantidad vendida) y por monto de operación.</p>

      {isLoading ? (
        <p className="py-8 text-center text-sm text-kyro-muted">Cargando rangos...</p>
      ) : (
        <div className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            {productividad(
              'Comisiones de Planes Postpago', 'PLAN', plan, setPlan,
              { border: 'border-t-cyan-400', text: 'text-cyan-400', bg: 'bg-cyan-500/5 border-cyan-500/20', icon: PhoneIcon },
              'El rango es el n.º de plan vendido en el mes (acumulado por agente). Para el último rango usa 9999 como "Hasta". Los rangos no deben solaparse.',
            )}
            {productividad(
              'Comisiones de Equipos Celulares', 'EQUIPO', equipo, setEquipo,
              { border: 'border-t-amber-400', text: 'text-amber-400', bg: 'bg-amber-500/5 border-amber-500/20', icon: DeviceMobileIcon },
              'El rango es el n.º de equipo vendido en el mes por el agente. La comisión se aplica por equipo. Si el ítem tiene comisión especial, esta tabla se usa solo como fallback.',
            )}
          </div>
          <div className="grid gap-4 md:grid-cols-3">
            {(['bipay', 'krece', 'payjoy'] as ServicioOperativo[]).map(servicio)}
          </div>
        </div>
      )}

      {msg && <p className="mt-4 rounded-kyro bg-kyro-success/10 px-3 py-2 text-sm text-kyro-success">{msg}</p>}
    </section>
  )
}

// ── Modal wrapper ─────────────────────────────────────────────────────────────

function Modal({ title, children, onClose }: { title: string; children: React.ReactNode; onClose: () => void }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
      <div className="kyro-card w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between p-6 border-b border-kyro-border">
          <h3 className="font-semibold text-kyro-text">{title}</h3>
          <Button variant="ghost" size="icon" aria-label="Cerrar modal" onClick={onClose} className="text-kyro-subtle hover:text-kyro-text text-xl leading-none">&times;</Button>
        </div>
        <div className="p-6">{children}</div>
      </div>
    </div>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

const scrollToId = (elementId: string) =>
  document.getElementById(elementId)?.scrollIntoView({ behavior: 'smooth', block: 'start' })

export function ComisionesPage() {
  const [filtroTipo, setFiltroTipo] = useState('')
  const [modal, setModal] = useState<'create' | 'edit' | 'recalcular' | null>(null)
  const [planEditando, setPlanEditando] = useState<ComisionPlan | null>(null)

  const qc = useQueryClient()
  const { data: planes = [], isLoading } = useQuery({
    queryKey: ['comisiones-planes', filtroTipo],
    queryFn: () => comisionesApi.list(filtroTipo || undefined),
  })

  const eliminar = useMutation({
    mutationFn: comisionesApi.destroy,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['comisiones-planes'] }),
  })
  const confirmDialog = useConfirmDialog()

  const handleEdit = (p: ComisionPlan) => { setPlanEditando(p); setModal('edit') }
  const handleDelete = async (p: ComisionPlan) => {
    const ok = await confirmDialog({
      title: `¿Eliminar "${p.nombre_plan}"?`,
      intent: 'danger',
      icon: Trash2,
      confirmLabel: 'Eliminar',
    })
    if (ok) eliminar.mutate(p.id)
  }

  const closeModal = () => { setModal(null); setPlanEditando(null) }

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader title="Comisiones de Planes" subtitle="Configura las tarifas de comisión por plan de servicio" Icon={TrendingUp}>
        <div className="flex flex-wrap gap-3">
          <Button variant="glassInfo" onClick={() => scrollToId('ganancias-operativas')}>
            <AlertCircle size={15} className="mr-2" /> Tarifas operativas
          </Button>
          <Button variant="glassIndigo" onClick={() => scrollToId('estrategia-comisiones')}>
            <Pencil size={15} className="mr-2" /> Estrategia y rangos
          </Button>
          <Button variant="glassWarning" onClick={() => setModal('recalcular')}>
            <RefreshCw size={15} className="mr-2" /> Recálculo masivo
          </Button>
          <Button variant="gold" onClick={() => setModal('create')}>
            <Plus size={15} className="mr-2" /> Nuevo plan
          </Button>
        </div>
      </PageHeader>

      {/* Filtro */}
      <ListToolbar description="Filtra el catálogo por familia de servicio">
        <div className="flex items-center gap-4">
          <Label className="shrink-0">Filtrar por tipo:</Label>
          <SegmentedToggle
            ariaLabel="Filtrar comisiones por tipo"
            size="sm"
            options={TIPO_OPTIONS}
            value={filtroTipo}
            onChange={setFiltroTipo}
          />
          <span className="text-sm text-kyro-muted">{planes.length} planes</span>
        </div>
      </ListToolbar>

      {/* Tabla */}
      <div className="kyro-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="kyro-table-head">
              <tr>
                <th className="px-4 py-3 text-left">Tipo</th>
                <th className="px-4 py-3 text-left">Nombre del plan</th>
                <th className="px-4 py-3 text-left">Alta</th>
                <th className="px-4 py-3 text-right">Fee</th>
                <th className="px-4 py-3 text-right">DNI N1</th>
                <th className="px-4 py-3 text-right">DNI N3</th>
                <th className="px-4 py-3 text-right">EXT N1</th>
                <th className="px-4 py-3 text-right">EXT N3</th>
                <th className="px-4 py-3 text-center">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-kyro-border">
              {isLoading ? (
                <tr><td colSpan={9} className="px-4 py-8 text-center text-kyro-subtle">Cargando...</td></tr>
              ) : planes.length === 0 ? (
                <tr><td colSpan={9} className="px-4 py-8 text-center text-kyro-subtle">No hay planes configurados.</td></tr>
              ) : planes.map(p => (
                <tr key={p.id} className="hover:bg-kyro-elevated/60 transition-colors">
                  <td className="px-4 py-3">
                    <span className={`inline-flex text-xs px-2 py-0.5 rounded-full font-medium ${TYPE_COLORS[p.tipo_servicio] ?? 'bg-kyro-elevated text-kyro-muted'}`}>
                      {p.tipo_servicio}
                    </span>
                  </td>
                  <td className="px-4 py-3 font-medium text-kyro-text">{p.nombre_plan}</td>
                  <td className="px-4 py-3 text-kyro-muted">{p.tipo_alta ?? '—'}</td>
                  <td className="px-4 py-3 text-right text-kyro-body">{pen(p.fee_monto)}</td>
                  <td className="px-4 py-3 text-right font-medium text-kyro-gold">{pen(p.comision_dni_n)}</td>
                  <td className="px-4 py-3 text-right text-kyro-gold">{pen(p.comision_dni_n3)}</td>
                  <td className="px-4 py-3 text-right font-medium text-kyro-info">{pen(p.comision_ext_n)}</td>
                  <td className="px-4 py-3 text-right text-kyro-info">{pen(p.comision_ext_n3)}</td>
                  <td className="px-4 py-3">
                    <TableActions className="justify-center">
                      <ActionIconButton tone="edit" label="Editar plan" icon={<Pencil size={15} />} onClick={() => handleEdit(p)} />
                      <ActionIconButton tone="delete" label="Eliminar plan" icon={<Trash2 size={15} />} onClick={() => handleDelete(p)} disabled={eliminar.isPending} />
                    </TableActions>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Secciones siempre visibles (paridad legacy: 2 páginas propias, no modales on-demand) */}
      <GananciasOperativasSection />
      <EstrategiaComisionesSection />

      {/* Modals */}
      {modal === 'create' && (
        <Modal title="Nuevo plan de comisión" onClose={closeModal}>
          <PlanForm onSuccess={closeModal} onCancel={closeModal} />
        </Modal>
      )}
      {modal === 'edit' && planEditando && (
        <Modal title="Editar plan de comisión" onClose={closeModal}>
          <PlanForm plan={planEditando} onSuccess={closeModal} onCancel={closeModal} />
        </Modal>
      )}
      {modal === 'recalcular' && (
        <Modal title="Recálculo masivo de comisiones" onClose={closeModal}>
          <RecalcularModal onClose={closeModal} />
        </Modal>
      )}
    </div>
  )
}
