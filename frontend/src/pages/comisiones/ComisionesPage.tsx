import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Plus, Pencil, Trash2, RefreshCw, CheckCircle2, AlertCircle } from 'lucide-react'
import { api } from '../../services/api'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'

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
        <Button type="submit" disabled={isPending} className="flex-1">
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

          <Button type="submit" disabled={mutation.isPending} className="w-full">
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

// ── Modal wrapper ─────────────────────────────────────────────────────────────

function Modal({ title, children, onClose }: { title: string; children: React.ReactNode; onClose: () => void }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
      <div className="kyro-card w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between p-6 border-b border-kyro-border">
          <h3 className="font-semibold text-kyro-text">{title}</h3>
          <Button variant="ghost" size="icon" onClick={onClose} className="text-kyro-subtle hover:text-kyro-text text-xl leading-none">&times;</Button>
        </div>
        <div className="p-6">{children}</div>
      </div>
    </div>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

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

  const handleEdit = (p: ComisionPlan) => { setPlanEditando(p); setModal('edit') }
  const handleDelete = (p: ComisionPlan) => {
    if (window.confirm(`¿Eliminar "${p.nombre_plan}"?`)) eliminar.mutate(p.id)
  }

  const closeModal = () => { setModal(null); setPlanEditando(null) }

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader title="Comisiones de Planes" subtitle="Configura las tarifas de comisión por plan de servicio">
        <div className="flex flex-wrap gap-3">
          <Button variant="outline" onClick={() => setModal('recalcular')}>
            <RefreshCw size={15} className="mr-2" /> Recálculo masivo
          </Button>
          <Button onClick={() => setModal('create')}>
            <Plus size={15} className="mr-2" /> Nuevo plan
          </Button>
        </div>
      </PageHeader>

      {/* Filtro */}
      <ListToolbar description="Filtra el catálogo por familia de servicio">
        <div className="flex items-center gap-4">
          <Label htmlFor="filtro_tipo" className="shrink-0">Filtrar por tipo:</Label>
          <Select
            id="filtro_tipo"
            value={filtroTipo}
            onChange={e => setFiltroTipo(e.target.value)}
            className="w-48"
          >
            <option value="">Todos</option>
            <option value="POSTPAGO">POSTPAGO</option>
            <option value="PREPAGO">PREPAGO</option>
            <option value="EQUIPO">EQUIPO</option>
            <option value="ACCESORIO">ACCESORIO</option>
            <option value="OTROS">OTROS</option>
          </Select>
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
                    <div className="flex items-center justify-center gap-2">
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => handleEdit(p)}
                        className="h-8 w-8 text-kyro-subtle hover:bg-kyro-info/10 hover:text-kyro-info"
                        title="Editar"
                      >
                        <Pencil size={14} />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => handleDelete(p)}
                        disabled={eliminar.isPending}
                        className="h-8 w-8 text-kyro-subtle hover:bg-kyro-danger/10 hover:text-kyro-danger"
                        title="Eliminar"
                      >
                        <Trash2 size={14} />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

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
