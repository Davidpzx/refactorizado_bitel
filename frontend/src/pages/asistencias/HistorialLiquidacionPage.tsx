import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'
import { PageHeader } from '../../components/PageHeader'
import { AsistenciasTabs } from './AsistenciasTabs'
import { KpiCard } from '../../components/ui/KpiCard'
import { Select } from '../../components/ui/select'
import { Label } from '../../components/ui/label'
import { Clock, ChartLineDown as TrendingDown, ShieldSlash as ShieldOff, CurrencyDollar as DollarSign } from '@phosphor-icons/react'

interface AgenteOption {
  id: number
  dni: string
  nombres: string
  tienda_base: string
}

interface DiaLiquidacion {
  fecha: string
  estado: string
  hora_entrada: string | null
  hora_salida: string | null
  minutos_tardanza: number
  minutos_deuda: number
  uso_comodin: boolean
  omitio_refrigerio: boolean
  descuento_soles: number
}

interface LiquidacionResponse {
  agente: { id: number; nombre: string; dni: string }
  mes: string
  dias: DiaLiquidacion[]
  resumen: {
    total_tardanzas_min: number
    deuda_acumulada_min: number
    comodines_usados: number
    total_descuento_soles: number
  }
}

export function HistorialLiquidacionPage() {
  const [agenteId, setAgenteId] = useState('')
  const [mes, setMes] = useState(() => new Date().toISOString().slice(0, 7))

  const { data: agentes = [] } = useQuery({
    queryKey: ['agentes-liquidacion'],
    queryFn: () => api.get<{ data: AgenteOption[] }>('/v1/agentes', { params: { per_page: 300, estado: 'ACTIVO' } }).then((r) => r.data.data),
    staleTime: 60_000,
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['liquidacion-asistencias', agenteId, mes],
    queryFn: () => api.get<LiquidacionResponse>(`/v1/agentes/${agenteId}/liquidacion-asistencias`, { params: { mes } }).then((r) => r.data),
    enabled: agenteId !== '',
  })

  return (
    <div className="space-y-6">
      <PageHeader
        title="Liquidacion de Asistencias"
        subtitle="Tardanzas, deuda, comodines y descuentos por agente y mes."
        Icon={Clock}
      >
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <Label className="mb-1 block text-xs">Agente</Label>
            <Select
              value={agenteId}
              onChange={(e) => setAgenteId(e.target.value)}
              className="h-9 w-64"
            >
              <option value="">— Selecciona agente —</option>
              {agentes.map((agente) => (
                <option key={agente.id} value={agente.id}>
                  {agente.nombres} ({agente.tienda_base})
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label className="mb-1 block text-xs">Mes</Label>
            <input
              type="month"
              value={mes}
              onChange={(e) => setMes(e.target.value)}
              className="kyro-input h-9 rounded-kyro px-3 text-sm"
            />
          </div>
        </div>
      </PageHeader>

      <AsistenciasTabs />

      {isLoading && (
        <div className="flex items-center gap-2 py-6 text-sm text-kyro-muted">
          <span className="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
          Cargando liquidacion...
        </div>
      )}
      {isError && (
        <p className="rounded-kyro border border-kyro-danger/30 bg-kyro-danger/10 px-3 py-2 text-sm text-kyro-danger">
          No se pudo cargar la liquidacion.
        </p>
      )}

      {!agenteId && !isLoading && (
        <div className="kyro-card p-8 text-center text-sm text-kyro-muted">
          Selecciona un agente y mes para ver la liquidacion.
        </div>
      )}

      {data && (
        <>
          <section className="kyro-card relative overflow-hidden rounded-[18px] p-5">
            {/* DIS-FX-27: gradiente decorativo oro-índigo eliminado → hairline índigo neutro. */}
            <div aria-hidden className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-kyro-indigo/50 to-transparent" />
            <div className="flex items-center justify-between">
              <div>
                <p className="font-semibold text-kyro-text">{data.agente.nombre}</p>
                <p className="text-xs text-kyro-muted">DNI {data.agente.dni}</p>
              </div>
              <span className="rounded-kyro bg-kyro-elevated px-3 py-1.5 text-sm font-medium text-kyro-body">{data.mes}</span>
            </div>
          </section>

          {/* Resumen de liquidación (DIS-FX-27). Oro reservado al único monto
              protagonista (descuento total); tardanza warning, deuda danger,
              comodines info. La respuesta no expone series → sin sparkline/delta. */}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard title="Tardanza total" value={`${data.resumen.total_tardanzas_min} min`} accent="var(--color-kyro-warning)" icon={<Clock size={18} />} className="[&_.tabular-nums]:text-kyro-warning" />
            <KpiCard title="Deuda acumulada" value={`${data.resumen.deuda_acumulada_min} min`} tone="danger" accent="var(--color-kyro-danger)" icon={<TrendingDown size={18} />} />
            <KpiCard title="Comodines usados" value={data.resumen.comodines_usados} tone="info" accent="var(--color-kyro-info)" icon={<ShieldOff size={18} />} />
            <KpiCard title="Descuento total" value={Number(data.resumen.total_descuento_soles)} monetary tone="gold" icon={<DollarSign size={18} />} subtitle="Total a descontar del mes" />
          </div>

          <section className="kyro-card overflow-hidden rounded-[18px]">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-sm">
                <thead className="kyro-table-head">
                  <tr>
                    {['Fecha', 'Estado', 'Entrada', 'Salida', 'Tardanza', 'Deuda', 'Comodin', 'Turno', 'Descuento'].map((h) => (
                      <th key={h} className={`px-4 py-3 text-xs font-semibold ${['Tardanza', 'Deuda', 'Descuento'].includes(h) ? 'text-right' : 'text-left'}`}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-kyro-border">
                  {data.dias.length === 0 && (
                    <tr>
                      <td colSpan={9} className="py-8 text-center text-kyro-muted">Sin asistencias en el periodo.</td>
                    </tr>
                  )}
                  {data.dias.map((dia) => {
                    const conTardanza = dia.minutos_tardanza > 0
                    const conDeuda    = dia.minutos_deuda > 0
                    return (
                      <tr key={dia.fecha} className={`transition-colors ${conDeuda ? 'bg-kyro-danger/5' : 'hover:bg-kyro-elevated/60'}`}>
                        <td className="px-4 py-3 font-mono text-xs text-kyro-body">
                          {new Date(dia.fecha + 'T00:00:00').toLocaleDateString('es-PE')}
                        </td>
                        <td className="px-4 py-3">
                          <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${
                            dia.estado === 'FALTA' ? 'bg-kyro-danger/15 text-kyro-danger' :
                            dia.estado === 'PERMISO' ? 'bg-kyro-info/15 text-kyro-info' :
                            'bg-kyro-elevated text-kyro-muted'
                          }`}>{dia.estado}</span>
                        </td>
                        <td className="px-4 py-3 text-kyro-body">{dia.hora_entrada?.slice(0, 5) ?? '—'}</td>
                        <td className="px-4 py-3 text-kyro-body">{dia.hora_salida?.slice(0, 5) ?? '—'}</td>
                        <td className={`px-4 py-3 text-right font-mono font-bold ${conTardanza ? 'text-kyro-warning' : 'text-kyro-muted'}`}>
                          {dia.minutos_tardanza} min
                        </td>
                        <td className={`px-4 py-3 text-right font-mono font-bold ${conDeuda ? 'text-kyro-danger' : 'text-kyro-muted'}`}>
                          {dia.minutos_deuda} min
                        </td>
                        <td className="px-4 py-3 text-center">
                          {dia.uso_comodin
                            ? <span className="inline-block rounded-full bg-kyro-info/15 px-2 py-0.5 text-xs font-medium text-kyro-info">Si</span>
                            : <span className="text-kyro-subtle">—</span>}
                        </td>
                        <td className="px-4 py-3">
                          {dia.omitio_refrigerio
                            ? <span className="text-xs text-kyro-warning">Corrido</span>
                            : <span className="text-xs text-kyro-muted">Regular</span>}
                        </td>
                        <td className={`kyro-money px-4 py-3 text-right font-bold ${Number(dia.descuento_soles) > 0 ? 'text-kyro-danger' : 'text-kyro-muted'}`}>
                          S/ {Number(dia.descuento_soles).toFixed(2)}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </section>
        </>
      )}
    </div>
  )
}
