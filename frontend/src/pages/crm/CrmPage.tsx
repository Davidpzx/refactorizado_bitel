import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useLeads, usePipeline, useCrearLead, useActualizarLead, useEliminarLead } from '../../hooks/useCrm'
import { PageHeader } from '../../components/PageHeader'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Badge } from '../../components/ui/badge'
import { Card } from '../../components/ui/card'
import { Dialog } from '../../components/ui/dialog'
import type { Lead, LeadFormData } from '../../types/crm'

// ── Constantes ────────────────────────────────────────────────────────────────

const ESTADOS: { value: Lead['estado']; label: string; color: string; bg: string }[] = [
  { value: 'NUEVO',      label: 'Nuevo',      color: 'text-sky-400',    bg: 'bg-sky-400/10 border-sky-400/30' },
  { value: 'CONTACTADO', label: 'Contactado', color: 'text-amber-400',  bg: 'bg-amber-400/10 border-amber-400/30' },
  { value: 'INTERESADO', label: 'Interesado', color: 'text-violet-400', bg: 'bg-violet-400/10 border-violet-400/30' },
  { value: 'CONVERTIDO', label: 'Convertido', color: 'text-green-400',  bg: 'bg-green-400/10 border-green-400/30' },
  { value: 'PERDIDO',    label: 'Perdido',    color: 'text-red-400',    bg: 'bg-red-400/10 border-red-400/30' },
]

const FUENTES: Lead['fuente'][] = ['PRESENCIAL', 'WHATSAPP', 'REFERIDO', 'LLAMADA']

const TIENDAS = [
  'PUNDA50','PUNDA11','PUNSC01','PUNDA23',
  'TACDA13','TACDA17','TACDA21','TACDA25','TACDA27','TACDA30',
]

// ── Schema ────────────────────────────────────────────────────────────────────

const leadSchema = z.object({
  agente_id: z.coerce.number().int().positive('Requerido'),
  tienda_id: z.string().min(1, 'Requerido'),
  estado:    z.enum(['NUEVO','CONTACTADO','INTERESADO','CONVERTIDO','PERDIDO']),
  fuente:    z.enum(['PRESENCIAL','WHATSAPP','REFERIDO','LLAMADA']),
  notas:     z.string().max(2000).optional().or(z.literal('')),
})

type LeadSchemaData = z.infer<typeof leadSchema>

// ── Lead Form ─────────────────────────────────────────────────────────────────

function LeadForm({
  leadInicial,
  onSuccess,
  onCancel,
}: {
  leadInicial?: Lead
  onSuccess: () => void
  onCancel: () => void
}) {
  const crear    = useCrearLead()
  const actualizar = useActualizarLead()
  const esEdicion  = !!leadInicial?.id

  const { register, handleSubmit, formState: { errors } } = useForm<LeadSchemaData>({
    resolver: zodResolver(leadSchema),
    defaultValues: leadInicial
      ? {
          agente_id: leadInicial.agente_id,
          tienda_id: leadInicial.tienda_id,
          estado:    leadInicial.estado,
          fuente:    leadInicial.fuente,
          notas:     leadInicial.notas ?? '',
        }
      : { estado: 'NUEVO', fuente: 'PRESENCIAL' },
  })

  const onSubmit = (data: LeadSchemaData) => {
    const payload: LeadFormData = {
      agente_id: data.agente_id,
      tienda_id: data.tienda_id,
      estado:    data.estado,
      fuente:    data.fuente,
      notas:     data.notas || undefined,
    }
    if (esEdicion) {
      actualizar.mutate({ id: leadInicial!.id, data: payload }, { onSuccess })
    } else {
      crear.mutate(payload, { onSuccess })
    }
  }

  const isPending = crear.isPending || actualizar.isPending

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4 p-4">
      <h3 className="font-semibold text-sm">{esEdicion ? 'Editar lead' : 'Nuevo lead'}</h3>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label className="text-xs">ID Agente</Label>
          <Input type="number" {...register('agente_id')} placeholder="ID del agente" className="mt-1" />
          {errors.agente_id && <p className="text-red-400 text-xs mt-1">{errors.agente_id.message}</p>}
        </div>
        <div>
          <Label className="text-xs">Tienda</Label>
          <select
            {...register('tienda_id')}
            className="mt-1 w-full h-9 rounded-md border border-input bg-transparent px-3 text-sm"
          >
            <option value="">Seleccionar</option>
            {TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
          </select>
          {errors.tienda_id && <p className="text-red-400 text-xs mt-1">{errors.tienda_id.message}</p>}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label className="text-xs">Estado</Label>
          <select
            {...register('estado')}
            className="mt-1 w-full h-9 rounded-md border border-input bg-transparent px-3 text-sm"
          >
            {ESTADOS.map(e => <option key={e.value} value={e.value}>{e.label}</option>)}
          </select>
        </div>
        <div>
          <Label className="text-xs">Fuente</Label>
          <select
            {...register('fuente')}
            className="mt-1 w-full h-9 rounded-md border border-input bg-transparent px-3 text-sm"
          >
            {FUENTES.map(f => <option key={f} value={f}>{f}</option>)}
          </select>
        </div>
      </div>

      <div>
        <Label className="text-xs">Notas</Label>
        <textarea
          {...register('notas')}
          rows={3}
          className="mt-1 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm resize-none"
          placeholder="Observaciones del lead..."
        />
      </div>

      {(crear.isError || actualizar.isError) && (
        <p className="text-red-400 text-xs">Error al guardar. Intente nuevamente.</p>
      )}

      <div className="flex gap-2 justify-end">
        <Button type="button" variant="ghost" size="sm" onClick={onCancel}>Cancelar</Button>
        <Button type="submit" size="sm" disabled={isPending}>
          {isPending ? 'Guardando...' : esEdicion ? 'Actualizar' : 'Crear lead'}
        </Button>
      </div>
    </form>
  )
}

// ── Tarjeta de lead en el Kanban ───────────────────────────────────────────────

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

  return (
    <div className="rounded-lg border border-white/10 bg-white/[0.03] p-3 text-xs space-y-2 hover:border-white/20 transition-colors">
      {lead.cliente ? (
        <div>
          <p className="font-semibold text-white text-sm">{lead.cliente.nombre}</p>
          <p className="text-muted-foreground/60">{lead.cliente.dni_ruc}</p>
          {lead.cliente.telefono && (
            <a
              href={`https://wa.me/51${lead.cliente.telefono}`}
              target="_blank"
              rel="noreferrer"
              className="text-green-400 hover:underline"
            >
              {lead.cliente.telefono}
            </a>
          )}
        </div>
      ) : (
        <p className="text-muted-foreground italic">Sin cliente asignado</p>
      )}

      <div className="flex gap-2 text-muted-foreground/60 flex-wrap">
        <span>Tienda: <span className="text-white">{lead.tienda_id}</span></span>
        <span>Fuente: <span className="text-white">{lead.fuente}</span></span>
      </div>

      {lead.notas && (
        <p className="text-muted-foreground/60 line-clamp-2 italic">{lead.notas}</p>
      )}

      <div className="flex gap-1 pt-1">
        <Button variant="ghost" size="sm" className="h-6 text-xs px-2" onClick={() => onEditar(lead)}>
          Editar
        </Button>
        <select
          className="text-xs rounded border border-white/10 bg-transparent px-1 flex-1"
          value=""
          onChange={e => {
            if (e.target.value) onCambiarEstado(lead.id, e.target.value as Lead['estado'])
          }}
        >
          <option value="">Mover a...</option>
          {estados.map(e => (
            <option key={e} value={e}>{ESTADOS.find(x => x.value === e)?.label}</option>
          ))}
        </select>
        <Button
          variant="ghost"
          size="sm"
          className="h-6 text-xs px-2 text-red-400 hover:text-red-300"
          onClick={() => { if (confirm('¿Eliminar lead?')) onEliminar(lead.id) }}
        >
          ×
        </Button>
      </div>
    </div>
  )
}

// ── Columna Kanban ────────────────────────────────────────────────────────────

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
    <div className={`rounded-xl border p-3 min-h-[200px] flex flex-col gap-2 ${config.bg}`} style={{ minWidth: '220px' }}>
      <div className="flex items-center justify-between mb-1">
        <span className={`font-semibold text-sm ${config.color}`}>{config.label}</span>
        <Badge variant="outline" className="text-xs">{leads.length}</Badge>
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
        <p className="text-muted-foreground/40 text-xs text-center mt-4">Sin leads</p>
      )}
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

export function CrmPage() {
  const [dialogOpen, setDialogOpen] = useState(false)
  const [leadEdicion, setLeadEdicion] = useState<Lead | undefined>()
  const [filtroTienda, setFiltroTienda] = useState('')

  const params = filtroTienda ? { tienda_id: filtroTienda, per_page: 200 } : { per_page: 200 }
  const { data: leadsData, isLoading } = useLeads(params)
  const { data: pipeline } = usePipeline(filtroTienda ? { tienda_id: filtroTienda } : undefined)

  const actualizar = useActualizarLead()
  const eliminar   = useEliminarLead()

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

  // Agrupar por estado para el Kanban
  const leadsPorEstado = ESTADOS.reduce((acc, e) => {
    acc[e.value] = leads.filter(l => l.estado === e.value)
    return acc
  }, {} as Record<Lead['estado'], Lead[]>)

  return (
    <div className="space-y-4">
      <PageHeader title="CRM — Pipeline de Leads" subtitle="Gestión de clientes potenciales">
        <select
          value={filtroTienda}
          onChange={e => setFiltroTienda(e.target.value)}
          className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
        >
          <option value="">Todas las tiendas</option>
          {TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
        </select>
        <Button size="sm" onClick={abrirNuevo}>+ Nuevo lead</Button>
      </PageHeader>

      {/* Métricas del pipeline */}
      {pipeline && (
        <div className="flex gap-3 flex-wrap">
          {pipeline.pipeline.map(p => {
            const cfg = ESTADOS.find(e => e.value === p.estado)!
            return (
              <Card key={p.estado} className="px-4 py-2 text-center">
                <p className="text-xs text-muted-foreground">{cfg.label}</p>
                <p className={`text-lg font-bold ${cfg.color}`}>{p.total}</p>
              </Card>
            )
          })}
          <Card className="px-4 py-2 text-center">
            <p className="text-xs text-muted-foreground">Tasa conversión</p>
            <p className="text-lg font-bold text-green-400">{pipeline.tasa_conversion}%</p>
          </Card>
        </div>
      )}

      {isLoading && (
        <div className="text-center py-16 text-muted-foreground">Cargando leads...</div>
      )}

      {/* Tablero Kanban */}
      {!isLoading && (
        <div className="flex gap-3 overflow-x-auto pb-4" style={{ minHeight: '400px' }}>
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

      {/* Dialog nuevo/editar lead */}
      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <LeadForm
          leadInicial={leadEdicion}
          onSuccess={() => setDialogOpen(false)}
          onCancel={() => setDialogOpen(false)}
        />
      </Dialog>
    </div>
  )
}
