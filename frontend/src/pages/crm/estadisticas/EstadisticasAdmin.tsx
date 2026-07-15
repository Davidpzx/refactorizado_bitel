import { useMemo, useState } from 'react'
import type { ComponentType } from 'react'
import { Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ChartLineUp as TrendingUp, ChatText as MessageSquare, CheckCircle, Users, XCircle } from '@phosphor-icons/react'
import { useCrmDashboard } from '../../../hooks/useCrm'
import { Button } from '../../../components/ui/button'
import { Badge } from '../../../components/ui/badge'
import { KpiCard } from '../../../components/ui/KpiCard'
import type { CrmDashboardFilters } from '../../../types/crm'

type IconCmp = ComponentType<{ size?: number | string; className?: string }>

const FUENTE_COLORS: Record<string, string> = {
  PRESENCIAL: 'var(--color-kpi-total)',
  WHATSAPP: 'var(--color-kyro-success)',
  REFERIDO: '#a78bfa',
  LLAMADA: 'var(--color-kyro-warning)',
}

const FUENTE_LABELS: Record<string, string> = {
  PRESENCIAL: 'Presencial',
  WHATSAPP: 'WhatsApp',
  REFERIDO: 'Referido',
  LLAMADA: 'Llamada',
}

const TIPO_ICON: Record<string, string> = {
  LLAMADA: 'Llamada',
  VISITA: 'Visita',
  WHATSAPP: 'WhatsApp',
  VENTA: 'Venta',
  POSTVENTA: 'Postventa',
}

function limpiarFiltros(filtros: CrmDashboardFilters): CrmDashboardFilters {
  return Object.fromEntries(
    Object.entries(filtros).filter(([, value]) => value !== undefined && value !== ''),
  ) as CrmDashboardFilters
}

export function EstadisticasAdmin({ filtros }: { filtros?: Partial<CrmDashboardFilters> }) {
  const hoy = new Date().toISOString().slice(0, 10)
  const mes1 = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10)
  const usaFiltrosExternos = filtros !== undefined

  const [filters, setFilters] = useState<CrmDashboardFilters>({
    desde: mes1,
    hasta: hoy,
  })
  const [applied, setApplied] = useState<CrmDashboardFilters>(filters)

  const dashboardFilters = useMemo(
    () => limpiarFiltros(usaFiltrosExternos ? { ...filtros } : applied),
    [applied, filtros, usaFiltrosExternos],
  )

  const { data, isLoading } = useCrmDashboard(dashboardFilters)

  const pipeline = data?.pipeline ?? []
  const porFuente = data?.por_fuente ?? []
  const tendencia = data?.tendencia ?? []
  const ranking = data?.ranking_agentes ?? []
  const actividad = data?.actividad_reciente ?? []
  const totalActividad = tendencia.reduce((s, d) => s + d.leads, 0)
  const leadsTrend = tendencia.map(d => d.leads)
  const convTrend = tendencia.map(d => d.convertidos)

  const kpis: { title: string; value: number | string; tone: 'neutral' | 'success' | 'danger' | 'info' | 'indigo'; accent: string; Icon: IconCmp; trend?: number[] }[] = [
    { title: 'Leads en periodo', value: data?.total_leads ?? 0, tone: 'info', accent: 'var(--color-kyro-info)', Icon: Users, trend: leadsTrend },
    { title: 'Tasa conversion', value: `${data?.tasa_conversion ?? 0}%`, tone: 'success', accent: 'var(--color-kyro-success)', Icon: TrendingUp },
    { title: 'Convertidos', value: data?.convertidos ?? 0, tone: 'success', accent: 'var(--color-kyro-success)', Icon: CheckCircle, trend: convTrend },
    { title: 'Perdidos', value: data?.perdidos ?? 0, tone: 'danger', accent: 'var(--color-kyro-danger)', Icon: XCircle },
    { title: 'Interacciones', value: totalActividad, tone: 'indigo', accent: 'var(--color-kyro-indigo)', Icon: MessageSquare },
  ]

  return (
    <div className="space-y-6">
      {!usaFiltrosExternos && (
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
          <Button variant="default" size="sm" onClick={() => setApplied(filters)}>
            Aplicar
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              const r: CrmDashboardFilters = { desde: mes1, hasta: hoy }
              setFilters(r)
              setApplied(r)
            }}
          >
            Este mes
          </Button>
        </div>
      )}

      {isLoading && <div className="py-16 text-center text-sm text-kyro-muted">Cargando analytics...</div>}

      {!isLoading && (
        <>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            {kpis.map(k => (
              <KpiCard key={k.title} title={k.title} value={k.value} tone={k.tone} accent={k.accent} icon={<k.Icon size={18} />} trend={k.trend} />
            ))}
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div className="kyro-card p-5">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/70 to-transparent" />
              <h3 className="mb-4 text-sm font-semibold text-kyro-text">Embudo por Estado</h3>
              <ResponsiveContainer width="100%" height={220}>
                <BarChart data={pipeline} layout="vertical" margin={{ left: 16, right: 16 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-kyro-border)" horizontal={false} />
                  <XAxis type="number" tick={{ fontSize: 11 }} />
                  <YAxis
                    type="category"
                    dataKey="estado"
                    tick={{ fontSize: 11 }}
                    width={80}
                    tickFormatter={v => ({ NUEVO: 'Nuevo', CONTACTADO: 'Contactado', INTERESADO: 'Interesado', CONVERTIDO: 'Convertido', PERDIDO: 'Perdido' } as Record<string, string>)[v as string] ?? String(v)}
                  />
                  <Tooltip formatter={(v) => [Number(v), 'Leads']} />
                  <Bar dataKey="total" radius={[0, 4, 4, 0]}>
                    {pipeline.map((p, i) => {
                      const fill = ['var(--color-kyro-info)', 'var(--color-kyro-warning)', 'var(--color-kpi-total)', 'var(--color-kyro-success)', 'var(--color-kyro-danger)'][i] ?? 'var(--color-kyro-muted)'
                      return <Cell key={p.estado} fill={fill} />
                    })}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </div>

            <div className="kyro-card p-5">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/70 to-transparent" />
              <h3 className="mb-4 text-sm font-semibold text-kyro-text">Leads por Canal de Captacion</h3>
              <ResponsiveContainer width="100%" height={220}>
                <PieChart>
                  <Pie data={porFuente} dataKey="total" nameKey="fuente" innerRadius={55} outerRadius={85} paddingAngle={2}>
                    {porFuente.map(f => (
                      <Cell key={f.fuente} fill={FUENTE_COLORS[f.fuente] ?? 'var(--color-kyro-muted)'} />
                    ))}
                  </Pie>
                  <Tooltip formatter={(v, _n, item) => [Number(v), FUENTE_LABELS[item.payload.fuente as string] ?? item.payload.fuente]} />
                  <Legend verticalAlign="bottom" wrapperStyle={{ fontSize: 11 }} formatter={(v: string) => FUENTE_LABELS[v] ?? v} />
                </PieChart>
              </ResponsiveContainer>
            </div>
          </div>

          {tendencia.length > 0 && (
            <div className="kyro-card p-5">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-green-500/50 to-transparent" />
              <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-kyro-text">
                <TrendingUp size={15} className="text-kyro-success" />Tendencia de Captacion Diaria
              </h3>
              <ResponsiveContainer width="100%" height={200}>
                <LineChart data={tendencia} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-kyro-border)" />
                  <XAxis dataKey="dia" tick={{ fontSize: 10 }} tickFormatter={v => v.slice(5)} />
                  <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
                  <Tooltip labelFormatter={v => `Dia: ${v}`} />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Line type="monotone" dataKey="leads" stroke="var(--color-kpi-total)" strokeWidth={2} dot={false} name="Nuevos leads" />
                  <Line type="monotone" dataKey="convertidos" stroke="var(--color-kyro-success)" strokeWidth={2} dot={false} name="Convertidos" />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div className="kyro-card overflow-hidden">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/70 via-indigo-500/40 to-transparent" />
              <div className="border-b border-kyro-border p-4">
                <h3 className="text-sm font-semibold text-kyro-text">Ranking Agentes - Captacion CRM</h3>
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
                      <tr key={a.agente_id} className={`border-b border-kyro-border transition-colors ${i < 3 ? 'bg-kyro-indigo/5' : 'hover:bg-kyro-indigo/5'}`}>
                        <td className="px-3 py-2.5 text-xs text-kyro-muted">{i + 1}</td>
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
                      <tr><td colSpan={6} className="px-3 py-10 text-center text-sm text-kyro-muted">Sin datos en el periodo</td></tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="kyro-card overflow-hidden">
              <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-500/50 to-transparent" />
              <div className="border-b border-kyro-border p-4">
                <h3 className="text-sm font-semibold text-kyro-text">Actividad Reciente</h3>
              </div>
              <div className="divide-y divide-kyro-border">
                {actividad.map(act => (
                  <div key={act.id} className="px-4 py-3 text-xs transition-colors hover:bg-kyro-indigo/5">
                    <div className="flex items-start justify-between gap-2">
                      <span className="font-medium text-kyro-text">
                        {TIPO_ICON[act.tipo] ?? act.tipo} - {act.agente_nombres}
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
