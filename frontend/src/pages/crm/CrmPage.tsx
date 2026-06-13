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
  { value: 'NUEVO',      label: 'Nuevo',      color: 'text-sky-600 dark:text-sky-400',       bg: 'border-sky-200/80 bg-sky-50/55 dark:border-sky-400/20 dark:bg-sky-400/[0.055]' },
  { value: 'CONTACTADO', label: 'Contactado', color: 'text-amber-600 dark:text-amber-400',   bg: 'border-amber-200/80 bg-amber-50/55 dark:border-amber-400/20 dark:bg-amber-400/[0.055]' },
  { value: 'INTERESADO', label: 'Interesado', color: 'text-violet-600 dark:text-violet-400', bg: 'border-violet-200/80 bg-violet-50/55 dark:border-violet-400/20 dark:bg-violet-400/[0.055]' },
  { value: 'CONVERTIDO', label: 'Convertido', color: 'text-emerald-600 dark:text-emerald-400', bg: 'border-emerald-200/80 bg-emerald-50/55 dark:border-emerald-400/20 dark:bg-emerald-400/[0.055]' },
  { value: 'PERDIDO',    label: 'Perdido',    color: 'text-red-600 dark:text-red-400',       bg: 'border-red-200/80 bg-red-50/55 dark:border-red-400/20 dark:bg-red-400/[0.055]' },
]

const FUENTES: Lead['fuente'][] = ['PRESENCIAL', 'WHATSAPP', 'REFERIDO', 'LLAMADA']

const TIENDAS = [
  'PUNDA50','PUNDA11','PUNSC01','PUNDA23',
  'TACDA13','TACDA17','TACDA21','TACDA25','TACDA27','TACDA30',
]

// ── Schema ────────────────────────────────────────────────────────────────────

const leadSchema = z.object({
  agente_id: z.number().min(1, 'Seleccione un agente'),
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
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-5 p-1">
      <h3 className="text-sm font-semibold tracking-tight text-gray-900 dark:text-zinc-100">{esEdicion ? 'Editar lead' : 'Nuevo lead'}</h3>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label className="text-xs">ID Agente</Label>
          <Input type="number" {...register('agente_id', { valueAsNumber: true })} placeholder="ID del agente" className="mt-1" />
          {errors.agente_id && <p className="text-red-400 text-xs mt-1">{errors.agente_id.message}</p>}
        </div>
        <div>
          <Label className="text-xs">Tienda</Label>
          <select
            {...register('tienda_id')}
            className="mt-1 h-9 w-full rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm text-gray-800 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
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
            className="mt-1 h-9 w-full rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm text-gray-800 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
          >
            {ESTADOS.map(e => <option key={e.value} value={e.value}>{e.label}</option>)}
          </select>
        </div>
        <div>
          <Label className="text-xs">Fuente</Label>
          <select
            {...register('fuente')}
            className="mt-1 h-9 w-full rounded-lg border border-gray-300/90 bg-white/90 px-3 text-sm text-gray-800 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
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
          className="mt-1 w-full resize-none rounded-lg border border-gray-300/90 bg-white/90 px-3 py-2 text-sm text-gray-800 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all dark:border-white/10 dark:bg-black/20 dark:text-zinc-100"
          placeholder="Observaciones del lead..."
        />
      </div>

      {(crear.isError || actualizar.isError) && (
        <p className="rounded-lg border border-red-200/80 bg-red-50/70 px-3 py-2 text-xs text-red-600 dark:border-red-400/20 dark:bg-red-500/[0.08] dark:text-red-400">Error al guardar. Intente nuevamente.</p>
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
    <div className="group space-y-3 rounded-xl border border-white/70 bg-white/75 p-3.5 text-xs shadow-[0_10px_25px_-20px_rgba(15,23,42,0.5)] backdrop-blur-xl transition-all duration-200 hover:-translate-y-0.5 hover:border-indigo-300/70 hover:shadow-[0_16px_30px_-20px_rgba(79,70,229,0.35)] dark:border-white/[0.08] dark:bg-zinc-900/70 dark:shadow-[0_14px_30px_-22px_rgba(0,0,0,0.95)] dark:hover:border-indigo-400/25">
      {lead.cliente ? (
        <div>
          <p className="text-sm font-semibold tracking-tight text-gray-900 dark:text-zinc-100">{lead.cliente.nombre}</p>
          <p className="font-mono text-[0.68rem] text-gray-400 dark:text-zinc-500">{lead.cliente.dni_ruc}</p>
          {lead.cliente.telefono && (
            <a
              href={`https://wa.me/51${lead.cliente.telefono}`}
              target="_blank"
              rel="noreferrer"
              className="font-medium text-emerald-600 transition-colors hover:text-emerald-500 hover:underline dark:text-emerald-400"
            >
              {lead.cliente.telefono}
            </a>
          )}
        </div>
      ) : (
        <p className="italic text-gray-400 dark:text-zinc-500">Sin cliente asignado</p>
      )}

      <div className="flex flex-wrap gap-2 text-gray-400 dark:text-zinc-500">
        <span>Tienda: <span className="font-medium text-gray-700 dark:text-zinc-300">{lead.tienda_id}</span></span>
        <span>Fuente: <span className="font-medium text-gray-700 dark:text-zinc-300">{lead.fuente}</span></span>
      </div>

      {lead.notas && (
        <p className="line-clamp-2 rounded-lg bg-slate-50/80 px-2.5 py-2 italic leading-relaxed text-gray-500 dark:bg-white/[0.035] dark:text-zinc-400">{lead.notas}</p>
      )}

      <div className="flex gap-1.5 border-t border-gray-100/90 pt-2.5 dark:border-white/[0.06]">
        <Button variant="ghost" size="sm" className="h-7 px-2 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-400/[0.08]" onClick={() => onEditar(lead)}>
          Editar
        </Button>
        <select
          className="h-7 flex-1 rounded-lg border border-gray-200/90 bg-white/75 px-2 text-xs text-gray-600 shadow-sm dark:border-white/10 dark:bg-black/20 dark:text-zinc-300"
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
          className="h-7 px-2 text-xs text-red-500 hover:bg-red-50 hover:text-red-600 dark:text-red-400 dark:hover:bg-red-500/[0.08] dark:hover:text-red-300"
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
    <div className={`flex min-h-[200px] flex-col gap-2.5 rounded-2xl border p-3.5 shadow-[0_14px_35px_-28px_rgba(15,23,42,0.5)] backdrop-blur-xl dark:shadow-[0_18px_40px_-28px_rgba(0,0,0,0.95)] ${config.bg}`} style={{ minWidth: '220px' }}>
      <div className="mb-1 flex items-center justify-between border-b border-black/[0.05] pb-2.5 dark:border-white/[0.06]">
        <span className={`text-[0.7rem] font-bold uppercase tracking-[0.1em] ${config.color}`}>{config.label}</span>
        <Badge variant="outline" className="min-w-6 justify-center border-white/70 bg-white/60 text-xs shadow-sm dark:border-white/10 dark:bg-white/[0.04]">{leads.length}</Badge>
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
        <p className="mt-6 rounded-xl border border-dashed border-gray-300/70 py-6 text-center text-xs text-gray-400 dark:border-white/10 dark:text-zinc-600">Sin leads</p>
      )}
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

export function CrmPage() {
  const [dialogOpen, setDialogOpen] = useState(false)
  const [leadEdicion, setLeadEdicion] = useState<Lead | undefined>()
  const [filtroTienda, setFiltroTienda] = useState('')

  const params: Record<string, string | number> = filtroTienda
    ? { tienda_id: filtroTienda, per_page: 200 }
    : { per_page: 200 }
  const pipelineParams: Record<string, string | number> | undefined = filtroTienda
    ? { tienda_id: filtroTienda }
    : undefined
  const { data: leadsData, isLoading } = useLeads(params)
  const { data: pipeline } = usePipeline(pipelineParams)

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
    <div className="space-y-6">
      <PageHeader title="CRM — Pipeline de Leads" subtitle="Gestión de clientes potenciales">
        <select
          value={filtroTienda}
          onChange={e => setFiltroTienda(e.target.value)}
          className="h-9 rounded-lg border border-gray-300/90 bg-white/80 px-3 text-sm text-gray-700 shadow-sm backdrop-blur-xl transition-all dark:border-white/10 dark:bg-zinc-900/65 dark:text-zinc-200"
        >
          <option value="">Todas las tiendas</option>
          {TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
        </select>
        <Button size="sm" onClick={abrirNuevo}>+ Nuevo lead</Button>
      </PageHeader>

      {/* Métricas del pipeline */}
      {pipeline && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {pipeline.pipeline.map(p => {
            const cfg = ESTADOS.find(e => e.value === p.estado)!
            return (
              <Card key={p.estado} className="relative overflow-hidden border-gray-200/80 bg-white/80 px-4 py-3 text-center shadow-[0_12px_30px_-22px_rgba(15,23,42,0.45)] backdrop-blur-xl transition-all duration-200 hover:-translate-y-0.5 dark:border-white/[0.08] dark:bg-zinc-900/65">
                <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-zinc-400">{cfg.label}</p>
                <p className={`mt-1 text-xl font-bold tracking-tight ${cfg.color}`}>{p.total}</p>
              </Card>
            )
          })}
          <Card className="relative overflow-hidden border-emerald-200/80 bg-emerald-50/55 px-4 py-3 text-center shadow-[0_12px_30px_-22px_rgba(16,185,129,0.4)] backdrop-blur-xl transition-all duration-200 hover:-translate-y-0.5 dark:border-emerald-400/20 dark:bg-emerald-400/[0.055]">
            <p className="text-[0.65rem] font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-zinc-400">Tasa conversión</p>
            <p className="mt-1 text-xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">{pipeline.tasa_conversion}%</p>
          </Card>
        </div>
      )}

      {isLoading && (
        <div className="rounded-2xl border border-gray-200/80 bg-white/70 py-16 text-center text-sm text-gray-400 shadow-sm backdrop-blur-xl dark:border-white/[0.08] dark:bg-zinc-900/60 dark:text-zinc-500">Cargando leads...</div>
      )}

      {/* Tablero Kanban */}
      {!isLoading && (
        <div className="flex gap-4 overflow-x-auto rounded-2xl border border-gray-200/70 bg-white/35 p-3 pb-5 shadow-[0_18px_45px_-34px_rgba(15,23,42,0.5)] backdrop-blur-xl dark:border-white/[0.06] dark:bg-black/10" style={{ minHeight: '400px' }}>
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
