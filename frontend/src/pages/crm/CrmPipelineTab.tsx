import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import type { ComponentType } from 'react'
import {
  ChartLineUp as TrendingUp,
  Users,
  CheckCircle,
  XCircle,
  ChatText as MessageSquare,
  Star,
  Trash as Trash2,
} from '@phosphor-icons/react'
import { useActualizarLead, useCrearLead, useEliminarLead, useLeads, usePipeline } from '../../hooks/useCrm'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { Badge } from '../../components/ui/badge'
import { Dialog } from '../../components/ui/dialog'
import { KpiCard } from '../../components/ui/KpiCard'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'
import type { Lead, LeadFormData } from '../../types/crm'

type IconCmp = ComponentType<{ size?: number | string; className?: string }>

const ESTADOS: { value: Lead['estado']; label: string; color: string; bg: string; accent: string; Icon: IconCmp }[] = [
  { value: 'NUEVO', label: 'Nuevo', color: 'text-kyro-info', bg: 'border-kyro-info bg-kyro-panel', accent: '#38bdf8', Icon: Users },
  { value: 'CONTACTADO', label: 'Contactado', color: 'text-kyro-warning', bg: 'border-kyro-warning bg-kyro-panel', accent: '#fbbf24', Icon: MessageSquare },
  { value: 'INTERESADO', label: 'Interesado', color: 'text-kyro-indigo', bg: 'border-kyro-indigo bg-kyro-panel', accent: '#6366f1', Icon: Star },
  { value: 'CONVERTIDO', label: 'Convertido', color: 'text-kyro-success', bg: 'border-kyro-success bg-kyro-panel', accent: '#22c55e', Icon: CheckCircle },
  { value: 'PERDIDO', label: 'Perdido', color: 'text-kyro-danger', bg: 'border-kyro-danger bg-kyro-panel', accent: '#ef4444', Icon: XCircle },
]

const FUENTES: Lead['fuente'][] = ['PRESENCIAL', 'WHATSAPP', 'REFERIDO', 'LLAMADA']

const TIENDAS = [
  'PUNDA50', 'PUNDA11', 'PUNSC01', 'PUNDA23',
  'TACDA13', 'TACDA17', 'TACDA21', 'TACDA25', 'TACDA27', 'TACDA30',
]

const leadSchema = z.object({
  agente_id: z.number().min(1, 'Seleccione un agente'),
  tienda_id: z.string().min(1, 'Requerido'),
  estado: z.enum(['NUEVO', 'CONTACTADO', 'INTERESADO', 'CONVERTIDO', 'PERDIDO']),
  fuente: z.enum(['PRESENCIAL', 'WHATSAPP', 'REFERIDO', 'LLAMADA']),
  notas: z.string().max(2000).optional().or(z.literal('')),
})

type LeadSchemaData = z.infer<typeof leadSchema>

function LeadForm({
  leadInicial,
  onSuccess,
  onCancel,
}: {
  leadInicial?: Lead
  onSuccess: () => void
  onCancel: () => void
}) {
  const crear = useCrearLead()
  const actualizar = useActualizarLead()
  const esEdicion = !!leadInicial?.id

  const { register, handleSubmit, formState: { errors } } = useForm<LeadSchemaData>({
    resolver: zodResolver(leadSchema),
    defaultValues: leadInicial
      ? {
          agente_id: leadInicial.agente_id,
          tienda_id: leadInicial.tienda_id,
          estado: leadInicial.estado,
          fuente: leadInicial.fuente,
          notas: leadInicial.notas ?? '',
        }
      : { estado: 'NUEVO', fuente: 'PRESENCIAL' },
  })

  const onSubmit = (data: LeadSchemaData) => {
    const payload: LeadFormData = {
      agente_id: data.agente_id,
      tienda_id: data.tienda_id,
      estado: data.estado,
      fuente: data.fuente,
      notas: data.notas || undefined,
    }

    if (esEdicion) {
      actualizar.mutate({ id: leadInicial!.id, data: payload }, { onSuccess })
    } else {
      crear.mutate(payload, { onSuccess })
    }
  }

  const isPending = crear.isPending || actualizar.isPending

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-5 p-1">
      <h3 className="text-sm font-semibold tracking-tight text-kyro-text">{esEdicion ? 'Editar lead' : 'Nuevo lead'}</h3>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label className="text-xs">ID Agente</Label>
          <Input type="number" {...register('agente_id', { valueAsNumber: true })} placeholder="ID del agente" className="mt-1" />
          {errors.agente_id && <p className="mt-1 text-xs text-kyro-danger">{errors.agente_id.message}</p>}
        </div>
        <div>
          <Label className="text-xs">Tienda</Label>
          <Select {...register('tienda_id')} className="mt-1">
            <option value="">Seleccionar</option>
            {TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
          </Select>
          {errors.tienda_id && <p className="mt-1 text-xs text-kyro-danger">{errors.tienda_id.message}</p>}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label className="text-xs">Estado</Label>
          <Select {...register('estado')} className="mt-1">
            {ESTADOS.map(e => <option key={e.value} value={e.value}>{e.label}</option>)}
          </Select>
        </div>
        <div>
          <Label className="text-xs">Fuente</Label>
          <Select {...register('fuente')} className="mt-1">
            {FUENTES.map(f => <option key={f} value={f}>{f}</option>)}
          </Select>
        </div>
      </div>

      <div>
        <Label className="text-xs">Notas</Label>
        <textarea
          {...register('notas')}
          rows={3}
          className="kyro-input mt-1 w-full resize-none px-3 py-2 text-sm"
          placeholder="Observaciones del lead..."
        />
      </div>

      {(crear.isError || actualizar.isError) && (
        <p className="rounded-kyro border border-kyro-danger bg-kyro-danger/10 px-3 py-2 text-xs text-kyro-danger">Error al guardar. Intente nuevamente.</p>
      )}

      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" size="sm" onClick={onCancel}>Cancelar</Button>
        <Button type="submit" variant="default" size="sm" disabled={isPending}>
          {isPending ? 'Guardando...' : esEdicion ? 'Actualizar' : 'Crear lead'}
        </Button>
      </div>
    </form>
  )
}

function LeadCard({
  lead,
  onEditar,
  onCambiarEstado,
  onEliminar,
}: {
  lead: Lead
  onEditar: (l: Lead) => void
  onCambiarEstado: (id: number, estado: Lead['estado']) => void
  onEliminar: (id: number) => void
}) {
  const estados = ESTADOS.map(e => e.value).filter(e => e !== lead.estado)
  const confirmDialog = useConfirmDialog()

  return (
    <div className="kyro-card group space-y-3 p-4 text-xs transition-colors hover:border-kyro-indigo">
      {lead.cliente ? (
        <div>
          <p className="text-sm font-semibold tracking-tight text-kyro-text">{lead.cliente.nombre}</p>
          <p className="font-mono text-[0.68rem] text-kyro-subtle">{lead.cliente.dni_ruc}</p>
          {lead.cliente.telefono && (
            <a
              href={`https://wa.me/51${lead.cliente.telefono}`}
              target="_blank"
              rel="noreferrer"
              className="font-medium text-kyro-success transition-colors hover:underline"
            >
              {lead.cliente.telefono}
            </a>
          )}
        </div>
      ) : (
        <p className="italic text-kyro-muted">Sin cliente asignado</p>
      )}

      <div className="flex flex-wrap gap-2 text-kyro-subtle">
        <span>Tienda: <span className="font-medium text-kyro-body">{lead.tienda_id}</span></span>
        <span>Fuente: <span className="font-medium text-kyro-body">{lead.fuente}</span></span>
      </div>

      {lead.notas && (
        <p className="line-clamp-2 rounded-kyro bg-kyro-elevated px-2.5 py-2 italic leading-relaxed text-kyro-muted">{lead.notas}</p>
      )}

      <div className="flex gap-1.5 border-t border-kyro-border pt-2.5">
        <Button variant="glassWarning" size="sm" className="h-7 px-2 text-xs" onClick={() => onEditar(lead)}>
          Editar
        </Button>
        <Select
          className="h-7 flex-1 px-2 text-xs"
          value=""
          onChange={e => {
            if (e.target.value) onCambiarEstado(lead.id, e.target.value as Lead['estado'])
          }}
        >
          <option value="">Mover a...</option>
          {estados.map(e => (
            <option key={e} value={e}>{ESTADOS.find(x => x.value === e)?.label}</option>
          ))}
        </Select>
        <Button
          variant="glassDanger"
          size="sm"
          className="h-7 px-2 text-xs"
          onClick={async () => {
            if (await confirmDialog({ title: 'Eliminar lead?', intent: 'danger', icon: Trash2, confirmLabel: 'Eliminar' })) {
              onEliminar(lead.id)
            }
          }}
        >
          x
        </Button>
      </div>
    </div>
  )
}

function KanbanColumna({
  config,
  leads,
  onEditar,
  onCambiarEstado,
  onEliminar,
}: {
  config: typeof ESTADOS[0]
  leads: Lead[]
  onEditar: (l: Lead) => void
  onCambiarEstado: (id: number, estado: Lead['estado']) => void
  onEliminar: (id: number) => void
}) {
  return (
    <div className={`flex min-h-[200px] min-w-[260px] flex-col gap-2.5 rounded-kyro-lg border p-4 shadow-kyro-card ${config.bg}`}>
      <div className="mb-1 flex items-center justify-between border-b border-kyro-border pb-2.5">
        <span className={`text-[0.7rem] font-bold uppercase tracking-[0.1em] ${config.color}`}>{config.label}</span>
        <Badge variant="outline" className="min-w-6 justify-center border-kyro-border bg-kyro-elevated text-xs">{leads.length}</Badge>
      </div>
      {leads.map(lead => (
        <LeadCard
          key={lead.id}
          lead={lead}
          onEditar={onEditar}
          onCambiarEstado={onCambiarEstado}
          onEliminar={onEliminar}
        />
      ))}
      {leads.length === 0 && (
        <p className="mt-6 rounded-kyro border border-dashed border-kyro-border py-6 text-center text-xs text-kyro-subtle">Sin leads</p>
      )}
    </div>
  )
}

export function CrmPipelineTab() {
  const navigate = useNavigate()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [leadEdicion, setLeadEdicion] = useState<Lead | undefined>()
  const [filtroTienda, setFiltroTienda] = useState('')
  const [busqueda, setBusqueda] = useState('')

  const params: Record<string, string | number> = {
    ...(filtroTienda ? { tienda_id: filtroTienda } : {}),
    ...(busqueda ? { q: busqueda } : {}),
    per_page: 200,
  }
  const pipelineParams: Record<string, string | number> | undefined = filtroTienda
    ? { tienda_id: filtroTienda }
    : undefined
  const { data: leadsData, isLoading } = useLeads(params)
  const { data: pipeline } = usePipeline(pipelineParams)

  const actualizar = useActualizarLead()
  const eliminar = useEliminarLead()

  const leads: Lead[] = leadsData?.data ?? []

  const abrirNuevo = () => {
    setLeadEdicion(undefined)
    setDialogOpen(true)
  }

  const abrirEdicion = (lead: Lead) => {
    setLeadEdicion(lead)
    setDialogOpen(true)
  }

  const cambiarEstado = (id: number, estado: Lead['estado']) => {
    actualizar.mutate({ id, data: { estado } })
  }

  const eliminarLead = (id: number) => {
    eliminar.mutate(id)
  }

  const leadsPorEstado = ESTADOS.reduce((acc, e) => {
    acc[e.value] = leads.filter(l => l.estado === e.value)
    return acc
  }, {} as Record<Lead['estado'], Lead[]>)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-end gap-2">
        <Input
          value={busqueda}
          onChange={e => setBusqueda(e.target.value)}
          placeholder="Buscar cliente, DNI o agente..."
          className="h-9 w-56"
        />
        <Select
          value={filtroTienda}
          onChange={e => setFiltroTienda(e.target.value)}
          className="h-9"
        >
          <option value="">Todas las tiendas</option>
          {TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
        </Select>
        <Button variant="outline" size="sm" onClick={() => navigate('/clientes')}>Clientes</Button>
        <Button variant="gold" size="sm" onClick={abrirNuevo}>+ Nuevo lead</Button>
      </div>

      {pipeline && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {pipeline.pipeline.map(p => {
            const cfg = ESTADOS.find(e => e.value === p.estado)!
            return (
              <KpiCard key={p.estado} title={cfg.label} accent={cfg.accent} icon={<cfg.Icon size={18} />} value={p.total} />
            )
          })}
          <KpiCard
            title="Tasa conversion"
            tone="success"
            accent="var(--color-kyro-success)"
            icon={<TrendingUp size={18} />}
            value={`${pipeline?.tasa_conversion ?? 0}%`}
          />
        </div>
      )}

      {isLoading && (
        <div className="kyro-card py-16 text-center text-sm text-kyro-muted">Cargando leads...</div>
      )}

      {!isLoading && (
        <div className="kyro-glass flex min-h-[400px] gap-4 overflow-x-auto rounded-kyro-xl p-3 pb-5">
          {ESTADOS.map(config => (
            <KanbanColumna
              key={config.value}
              config={config}
              leads={leadsPorEstado[config.value] ?? []}
              onEditar={abrirEdicion}
              onCambiarEstado={cambiarEstado}
              onEliminar={eliminarLead}
            />
          ))}
        </div>
      )}

      <Dialog
        open={dialogOpen}
        onClose={() => setDialogOpen(false)}
        title={leadEdicion ? 'Editar lead' : 'Nuevo lead'}
      >
        <LeadForm
          leadInicial={leadEdicion}
          onSuccess={() => setDialogOpen(false)}
          onCancel={() => setDialogOpen(false)}
        />
      </Dialog>
    </div>
  )
}
