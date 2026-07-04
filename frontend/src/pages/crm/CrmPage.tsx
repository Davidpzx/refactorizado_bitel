import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import {
  useLeads, usePipeline, useCrearLead, useActualizarLead, useEliminarLead, useCrmDashboard, useCrmTemperatura,
} from '../../hooks/useCrm'
import { PageHeader } from '../../components/PageHeader'
import { PageTabs } from '../../components/ui/PageTabs'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { Select } from '../../components/ui/select'
import { Badge } from '../../components/ui/badge'
import { Dialog } from '../../components/ui/dialog'
import { StatCard } from '../../components/ui/StatCard'
import { TrendingUp, Users, CheckCircle, XCircle, MessageSquare, Megaphone, Star } from 'lucide-react'
import type { ComponentType } from 'react'
import {
  BarChart, Bar, LineChart, Line,
  XAxis, YAxis, Tooltip, Legend, CartesianGrid,
  ResponsiveContainer, Cell,
} from 'recharts'
import type { CrmDashboardFilters, CrmInteraccionTemp, CrmTemperaturaFiltros, Lead, LeadFormData, TemperaturaEtiqueta } from '../../types/crm'

// ── Constantes ────────────────────────────────────────────────────────────────

type IconCmp = ComponentType<{ size?: number | string; className?: string }>

const ESTADOS: { value: Lead['estado']; label: string; color: string; bg: string; accent: string; Icon: IconCmp }[] = [
  { value: 'NUEVO',      label: 'Nuevo',      color: 'text-kyro-info',    bg: 'border-kyro-info bg-kyro-panel',    accent: '#38bdf8', Icon: Users },
  { value: 'CONTACTADO', label: 'Contactado', color: 'text-kyro-warning', bg: 'border-kyro-warning bg-kyro-panel', accent: '#ffc200', Icon: MessageSquare },
  { value: 'INTERESADO', label: 'Interesado', color: 'text-kyro-gold',    bg: 'border-kyro-indigo bg-kyro-panel',  accent: '#6366f1', Icon: Star },
  { value: 'CONVERTIDO', label: 'Convertido', color: 'text-kyro-success', bg: 'border-kyro-success bg-kyro-panel', accent: '#22c55e', Icon: CheckCircle },
  { value: 'PERDIDO',    label: 'Perdido',    color: 'text-kyro-danger',  bg: 'border-kyro-danger bg-kyro-panel',  accent: '#ef4444', Icon: XCircle },
]

/** Ícono en caja redondeada tintada (estilo KPI legacy CRM). */
function KpiIcon({ accent, Icon }: { accent: string; Icon: IconCmp }) {
  return (
    <span
      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
      style={{ background: `color-mix(in srgb, ${accent} 18%, transparent)`, color: accent }}
      aria-hidden
    >
      <Icon size={18} />
    </span>
  )
}

const FUENTES: Lead['fuente'][] = ['PRESENCIAL', 'WHATSAPP', 'REFERIDO', 'LLAMADA']

const TIENDAS = [
  'PUNDA50','PUNDA11','PUNSC01','PUNDA23',
  'TACDA13','TACDA17','TACDA21','TACDA25','TACDA27','TACDA30',
]

// Orden visual igual al Kanban del legacy (crm_dashboard.php:909-956)
const TEMPERATURAS: TemperaturaEtiqueta[] = ['Neutro', 'Upselling', 'Caliente', 'Frío']

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
      <h3 className="text-sm font-semibold tracking-tight text-kyro-text">{esEdicion ? 'Editar lead' : 'Nuevo lead'}</h3>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label className="text-xs">ID Agente</Label>
          <Input type="number" {...register('agente_id', { valueAsNumber: true })} placeholder="ID del agente" className="mt-1" />
          {errors.agente_id && <p className="mt-1 text-xs text-kyro-danger">{errors.agente_id.message}</p>}
        </div>
        <div>
          <Label className="text-xs">Tienda</Label>
          <Select
            {...register('tienda_id')}
            className="mt-1"
          >
            <option value="">Seleccionar</option>
            {TIENDAS.map(t => <option key={t} value={t}>{t}</option>)}
          </Select>
          {errors.tienda_id && <p className="mt-1 text-xs text-kyro-danger">{errors.tienda_id.message}</p>}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label className="text-xs">Estado</Label>
          <Select
            {...register('estado')}
            className="mt-1"
          >
            {ESTADOS.map(e => <option key={e.value} value={e.value}>{e.label}</option>)}
          </Select>
        </div>
        <div>
          <Label className="text-xs">Fuente</Label>
          <Select
            {...register('fuente')}
            className="mt-1"
          >
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

      <div className="flex gap-2 justify-end">
        <Button type="button" variant="ghost" size="sm" onClick={onCancel}>Cancelar</Button>
        <Button type="submit" variant="gold" size="sm" disabled={isPending}>
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
    <div className="kyro-card group space-y-3 p-3.5 text-xs transition-colors hover:border-kyro-gold">
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
    <div className={`flex min-h-[200px] min-w-[220px] flex-col gap-2.5 rounded-kyro-lg border p-3.5 shadow-kyro-card ${config.bg}`}>
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

// ── CRM Analytics Dashboard ───────────────────────────────────────────────────

const FUENTE_COLORS: Record<string, string> = {
  PRESENCIAL: 'var(--color-kpi-total)',
  WHATSAPP:   'var(--color-kyro-success)',
  REFERIDO:   'var(--color-kyro-gold)',
  LLAMADA:    'var(--color-kyro-warning)',
}

const TIPO_ICON: Record<string, string> = {
  LLAMADA:   '📞',
  VISITA:    '🏪',
  WHATSAPP:  '💬',
  VENTA:     '✅',
  POSTVENTA: '🔄',
}

function CrmAnalytics({ tiendaId }: { tiendaId: string }) {
  const hoy  = new Date().toISOString().slice(0, 10)
  const mes1 = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10)

  const [filters, setFilters] = useState<CrmDashboardFilters>({
    desde: mes1,
    hasta: hoy,
    tienda_id: tiendaId || undefined,
  })
  const [applied, setApplied] = useState<CrmDashboardFilters>({ ...filters, tienda_id: tiendaId || undefined })

  const { data, isLoading } = useCrmDashboard(applied)

  const pipeline        = data?.pipeline ?? []
  const porFuente       = data?.por_fuente ?? []
  const tendencia       = data?.tendencia ?? []
  const ranking         = data?.ranking_agentes ?? []
  const actividad       = data?.actividad_reciente ?? []
  const totalActividad  = tendencia.reduce((s, d) => s + d.leads, 0)

  const kpis = [
    { label: 'Leads en período',   value: data?.total_leads   ?? 0, icon: <Users size={16} />,        accent: '#0d6efd', text: 'text-kyro-text' },
    { label: 'Tasa conversión',    value: `${data?.tasa_conversion ?? 0}%`, icon: <TrendingUp size={16} />, accent: '#22c55e', text: 'text-kyro-success' },
    { label: 'Convertidos',        value: data?.convertidos   ?? 0, icon: <CheckCircle size={16} />,  accent: '#22c55e', text: 'text-kyro-success' },
    { label: 'Perdidos',           value: data?.perdidos      ?? 0, icon: <XCircle size={16} />,      accent: '#ef4444', text: 'text-kyro-danger' },
    { label: 'Interacciones',      value: totalActividad,           icon: <MessageSquare size={16} />, accent: '#6366f1', text: 'text-kyro-body' },
  ]

  return (
    <div className="space-y-6">
      {/* Filtros de fecha */}
      <div className="kyro-card flex flex-wrap items-end gap-3 p-3">
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Desde</label>
          <input
            type="date"
            value={filters.desde}
            onChange={e => setFilters(f => ({ ...f, desde: e.target.value }))}
            className="kyro-input h-9 w-40"
          />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Hasta</label>
          <input
            type="date"
            value={filters.hasta}
            onChange={e => setFilters(f => ({ ...f, hasta: e.target.value }))}
            className="kyro-input h-9 w-40"
          />
        </div>
        <Button variant="gold" size="sm" onClick={() => setApplied({ ...filters, tienda_id: tiendaId || undefined })}>
          Aplicar
        </Button>
        <Button variant="outline" size="sm" onClick={() => {
          const r: CrmDashboardFilters = { desde: mes1, hasta: hoy, tienda_id: tiendaId || undefined }
          setFilters(r); setApplied(r)
        }}>
          Este mes
        </Button>
      </div>

      {isLoading && <div className="py-16 text-center text-sm text-kyro-muted">Cargando analytics...</div>}

      {!isLoading && (
        <>
          {/* KPI Strip */}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {kpis.map(k => (
              <StatCard key={k.label} title={k.label} accent={k.accent} icon={k.icon} value={k.value} valueColorClass={k.text} />
            ))}
          </div>

          {/* Gráficos fila 1: funnel + fuente */}
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* Pipeline funnel */}
            <div className="kyro-card p-4">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/70 to-transparent" />
              <h3 className="mb-4 text-sm font-semibold text-kyro-text">Embudo por Estado</h3>
              <ResponsiveContainer width="100%" height={220}>
                <BarChart data={pipeline} layout="vertical" margin={{ left: 16, right: 16 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-kyro-border)" horizontal={false} />
                  <XAxis type="number" tick={{ fontSize: 11 }} />
                  <YAxis type="category" dataKey="estado" tick={{ fontSize: 11 }} width={80}
                    tickFormatter={v => ({ NUEVO:'Nuevo', CONTACTADO:'Contactado', INTERESADO:'Interesado', CONVERTIDO:'Convertido', PERDIDO:'Perdido' } as Record<string,string>)[v as string] ?? String(v)} />
                  <Tooltip formatter={(v) => [Number(v), 'Leads']} />
                  <Bar dataKey="total" radius={[0, 4, 4, 0]}>
                    {pipeline.map((p, i) => {
                      const fill = ['var(--color-kyro-info)','var(--color-kyro-warning)','var(--color-kpi-total)','var(--color-kyro-success)','var(--color-kyro-danger)'][i] ?? 'var(--color-kyro-muted)'
                      return <Cell key={p.estado} fill={fill} />
                    })}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>

            {/* Por fuente */}
            <div className="kyro-card p-4">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-amber-400/70 to-transparent" />
              <h3 className="mb-4 text-sm font-semibold text-kyro-text">Leads por Canal de Captación</h3>
              <ResponsiveContainer width="100%" height={220}>
                <BarChart data={porFuente} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-kyro-border)" />
                  <XAxis dataKey="fuente" tick={{ fontSize: 11 }}
                    tickFormatter={v => ({ PRESENCIAL:'Presencial', WHATSAPP:'WhatsApp', REFERIDO:'Referido', LLAMADA:'Llamada' } as Record<string,string>)[v as string] ?? String(v)} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip formatter={(v) => [Number(v), 'Leads']} />
                  <Bar dataKey="total" radius={[4, 4, 0, 0]}>
                    {porFuente.map(f => (
                      <Cell key={f.fuente} fill={FUENTE_COLORS[f.fuente] ?? 'var(--color-kyro-muted)'} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>

          {/* Tendencia diaria */}
          {tendencia.length > 0 && (
            <div className="kyro-card p-4">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-green-500/50 to-transparent" />
              <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-kyro-text">
                <TrendingUp size={15} className="text-kyro-success" />Tendencia de Captación Diaria
              </h3>
              <ResponsiveContainer width="100%" height={200}>
                <LineChart data={tendencia} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-kyro-border)" />
                  <XAxis dataKey="dia" tick={{ fontSize: 10 }} tickFormatter={v => v.slice(5)} />
                  <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
                  <Tooltip labelFormatter={v => `Día: ${v}`} />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Line type="monotone" dataKey="leads" stroke="var(--color-kpi-total)" strokeWidth={2} dot={false} name="Nuevos leads" />
                  <Line type="monotone" dataKey="convertidos" stroke="var(--color-kyro-success)" strokeWidth={2} dot={false} name="Convertidos" />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}

          {/* Fila inferior: ranking + actividad reciente */}
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* Ranking agentes CRM */}
            <div className="kyro-card overflow-hidden">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-amber-400/70 via-indigo-500/40 to-transparent" />
              <div className="border-b border-kyro-border p-4">
                <h3 className="text-sm font-semibold text-kyro-text">Ranking Agentes — Captación CRM</h3>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="kyro-table-head">
                      {['#', 'Agente', 'Tienda', 'Leads', 'Conv.', 'Tasa'].map(h => (
                        <th key={h} className="px-3 py-2.5 text-left text-xs">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {ranking.map((a, i) => (
                      <tr key={a.agente_id} className={`border-b border-kyro-border transition-colors ${i < 3 ? 'bg-kyro-gold/5' : 'hover:bg-kyro-gold/5'}`}>
                        <td className="px-3 py-2.5 text-xs text-kyro-muted">
                          {i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i + 1}°`}
                        </td>
                        <td className="px-3 py-2.5 font-medium text-kyro-text">{a.nombres}</td>
                        <td className="px-3 py-2.5 font-mono text-xs text-kyro-subtle">{a.tienda_id}</td>
                        <td className="px-3 py-2.5 font-bold text-kyro-text">{a.total_leads}</td>
                        <td className="px-3 py-2.5 font-bold text-kyro-success">{a.convertidos}</td>
                        <td className="px-3 py-2.5">
                          <Badge variant={a.tasa >= 50 ? 'success' : a.tasa >= 25 ? 'warning' : 'outline'}>
                            {a.tasa}%
                          </Badge>
                        </td>
                      </tr>
                    ))}
                    {ranking.length === 0 && (
                      <tr><td colSpan={6} className="px-3 py-10 text-center text-kyro-muted text-sm">Sin datos en el período</td></tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Actividad reciente */}
            <div className="kyro-card overflow-hidden">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/50 to-transparent" />
              <div className="border-b border-kyro-border p-4">
                <h3 className="text-sm font-semibold text-kyro-text">Actividad Reciente</h3>
              </div>
              <div className="divide-y divide-kyro-border">
                {actividad.map(act => (
                  <div key={act.id} className="px-4 py-3 text-xs transition-colors hover:bg-kyro-gold/5">
                    <div className="flex items-start justify-between gap-2">
                      <span className="font-medium text-kyro-text">
                        {TIPO_ICON[act.tipo] ?? '•'} {act.agente_nombres}
                      </span>
                      <span className="shrink-0 text-[0.65rem] text-kyro-muted">
                        {act.fecha.slice(0, 10)}
                      </span>
                    </div>
                    {act.cliente_nombre && (
                      <p className="mt-0.5 text-kyro-subtle">Cliente: {act.cliente_nombre}</p>
                    )}
                    {act.detalle && (
                      <p className="mt-1 line-clamp-2 italic text-kyro-muted">{act.detalle}</p>
                    )}
                  </div>
                ))}
                {actividad.length === 0 && (
                  <p className="py-10 text-center text-sm text-kyro-muted">Sin interacciones registradas</p>
                )}
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  )
}

// ── Temperatura calculada (CRM legacy: crm_clientes/crm_interacciones) ────────

function TemperaturaCard({ item }: { item: CrmInteraccionTemp }) {
  const temp = item.temperatura
  return (
    <div
      className="space-y-2 rounded-kyro-lg border p-3 text-xs"
      style={{ background: 'rgba(255,255,255,0.03)', borderColor: temp.border }}
    >
      <div className="flex flex-wrap items-start justify-between gap-1">
        <span className="text-sm font-semibold tracking-tight text-kyro-text">{item.nombres} {item.apellidos}</span>
        <span className="rounded-full bg-kyro-elevated px-2 py-0.5 font-mono text-[0.68rem] text-kyro-subtle">{item.dni}</span>
      </div>
      <div className="space-y-0.5 text-kyro-subtle" style={{ lineHeight: 1.4 }}>
        <div>{item.tienda_codigo} · <span className="text-kyro-body">{item.agente_nombre}</span></div>
        <div>{new Date(item.fecha_hora).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' })}</div>
        {item.producto_interes && <div>Interés: {item.producto_interes}</div>}
        {item.motivo_rechazo && <div className="font-bold text-kyro-danger">⚠ {item.motivo_rechazo}</div>}
      </div>
      {item.telefono && (
        <a
          href={`https://wa.me/51${item.telefono}`}
          target="_blank"
          rel="noreferrer"
          className="block rounded-kyro border border-kyro-success/35 bg-kyro-success/10 px-2 py-1 text-center font-medium text-kyro-success transition-colors hover:underline"
        >
          Contactar por WhatsApp
        </a>
      )}
    </div>
  )
}

function TemperaturaColumna({ etiqueta, items }: { etiqueta: TemperaturaEtiqueta; items: CrmInteraccionTemp[] }) {
  const colores = items[0]?.temperatura ?? { bg: 'rgba(148,163,184,.12)', text: '#94a3b8', border: 'rgba(148,163,184,.25)' }
  return (
    <div className="flex min-h-[200px] min-w-[240px] flex-col gap-2.5 rounded-kyro-lg border p-3.5" style={{ background: 'rgba(0,0,0,0.15)', borderColor: colores.border }}>
      <div className="mb-1 flex items-center justify-between border-b border-kyro-border pb-2.5">
        <span className="text-[0.7rem] font-bold uppercase tracking-[0.1em]" style={{ color: colores.text }}>{etiqueta}</span>
        <Badge variant="outline" className="min-w-6 justify-center border-kyro-border bg-kyro-elevated text-xs">{items.length}</Badge>
      </div>
      {items.map(item => <TemperaturaCard key={item.id} item={item} />)}
      {items.length === 0 && (
        <p className="mt-6 rounded-kyro border border-dashed border-kyro-border py-6 text-center text-xs text-kyro-subtle">Sin registros</p>
      )}
    </div>
  )
}

function CrmTemperaturaTab({ tiendaId }: { tiendaId: string }) {
  const [filters, setFilters] = useState<CrmTemperaturaFiltros>({ tienda_codigo: tiendaId || undefined })

  const { data, isLoading } = useCrmTemperatura({ ...filters, per_page: 200 })
  const items = data?.data ?? []

  const porTemperatura = TEMPERATURAS.reduce((acc, t) => {
    acc[t] = items.filter(i => i.temperatura.etiqueta === t)
    return acc
  }, {} as Record<TemperaturaEtiqueta, CrmInteraccionTemp[]>)

  return (
    <div className="space-y-4">
      <div className="kyro-card flex flex-wrap items-end gap-3 p-3">
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Desde</label>
          <input
            type="date"
            value={filters.desde ?? ''}
            onChange={e => setFilters(f => ({ ...f, desde: e.target.value || undefined }))}
            className="kyro-input h-9 w-40"
          />
        </div>
        <div>
          <label className="mb-1 block text-xs text-kyro-muted">Hasta</label>
          <input
            type="date"
            value={filters.hasta ?? ''}
            onChange={e => setFilters(f => ({ ...f, hasta: e.target.value || undefined }))}
            className="kyro-input h-9 w-40"
          />
        </div>
      </div>

      {isLoading && <div className="kyro-card py-16 text-center text-sm text-kyro-muted">Cargando temperatura...</div>}

      {!isLoading && (
        <div className="kyro-glass flex min-h-[400px] gap-4 overflow-x-auto rounded-kyro-xl p-3 pb-5">
          {TEMPERATURAS.map(etiqueta => (
            <TemperaturaColumna key={etiqueta} etiqueta={etiqueta} items={porTemperatura[etiqueta] ?? []} />
          ))}
        </div>
      )}
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

const CRM_TABS = [
  { id: 'pipeline',    label: 'Pipeline Kanban' },
  { id: 'temperatura', label: 'Temperatura' },
  { id: 'analytics',   label: 'Analytics' },
] as const

export function CrmPage() {
  const [dialogOpen, setDialogOpen] = useState(false)
  const [leadEdicion, setLeadEdicion] = useState<Lead | undefined>()
  const [filtroTienda, setFiltroTienda] = useState('')
  const [busqueda, setBusqueda] = useState('')
  const [tab, setTab] = useState<'pipeline' | 'temperatura' | 'analytics'>('pipeline')

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
      <PageHeader title="CRM — Pipeline de Leads" subtitle="Gestión de clientes potenciales" Icon={Megaphone}>
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
        {tab === 'pipeline' && (
          <Button variant="gold" size="sm" onClick={abrirNuevo}>+ Nuevo lead</Button>
        )}
      </PageHeader>

      {/* Tabs */}
      <PageTabs
        tabs={CRM_TABS.map(t => ({ id: t.id, label: t.label }))}
        active={tab}
        onChange={(id) => setTab(id as typeof tab)}
      />

      {/* ── Tab Pipeline ─────────────────────────────────────────────────── */}
      {tab === 'pipeline' && (
        <>
          {/* Métricas del pipeline */}
          {pipeline && (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
              {pipeline.pipeline.map(p => {
                const cfg = ESTADOS.find(e => e.value === p.estado)!
                return (
                  <StatCard key={p.estado} title={cfg.label} align="top" accent={cfg.accent}
                    icon={<KpiIcon accent={cfg.accent} Icon={cfg.Icon} />}
                    value={p.total} valueColorClass={cfg.color} />
                )
              })}
              <StatCard title="Tasa conversión" align="top" accent="#22c55e"
                icon={<KpiIcon accent="#22c55e" Icon={TrendingUp} />}
                value={`${pipeline?.tasa_conversion ?? 0}%`} valueColorClass="text-kyro-success" />
            </div>
          )}

          {isLoading && (
            <div className="kyro-card py-16 text-center text-sm text-kyro-muted">Cargando leads...</div>
          )}

          {/* Tablero Kanban */}
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
        </>
      )}

      {/* ── Tab Temperatura ────────────────────────────────────────────────── */}
      {tab === 'temperatura' && <CrmTemperaturaTab tiendaId={filtroTienda} />}

      {/* ── Tab Analytics ─────────────────────────────────────────────────── */}
      {tab === 'analytics' && <CrmAnalytics tiendaId={filtroTienda} />}

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
