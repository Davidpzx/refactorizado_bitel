import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'

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
    <div className="mx-auto max-w-5xl space-y-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-xl font-bold text-kyro-text">Liquidacion de Asistencias</h1>
          <p className="text-sm text-kyro-muted">Tardanzas, deuda y descuentos por agente.</p>
        </div>
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-[260px_150px]">
          <select
            value={agenteId}
            onChange={(event) => setAgenteId(event.target.value)}
            className="kyro-input h-10 rounded-kyro px-3 text-sm"
          >
            <option value="">Agente</option>
            {agentes.map((agente) => (
              <option key={agente.id} value={agente.id}>
                {agente.nombres} ({agente.tienda_base})
              </option>
            ))}
          </select>
          <input
            type="month"
            value={mes}
            onChange={(event) => setMes(event.target.value)}
            className="kyro-input h-10 rounded-kyro px-3 text-sm"
          />
        </div>
      </div>

      {isLoading && <p className="text-sm text-kyro-muted">Cargando...</p>}
      {isError && <p className="rounded-kyro border border-kyro-danger/30 bg-kyro-danger/10 px-3 py-2 text-sm text-kyro-danger">No se pudo cargar la liquidacion.</p>}

      {data && (
        <>
          <section className="kyro-card p-4">
            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p className="font-semibold text-kyro-text">{data.agente.nombre}</p>
                <p className="text-xs text-kyro-muted">DNI {data.agente.dni}</p>
              </div>
              <p className="text-sm font-medium text-kyro-muted">{data.mes}</p>
            </div>
          </section>

          <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {[
              { label: 'Tardanza', value: `${data.resumen.total_tardanzas_min} min` },
              { label: 'Deuda', value: `${data.resumen.deuda_acumulada_min} min` },
              { label: 'Comodines', value: data.resumen.comodines_usados },
              { label: 'Descuento', value: `S/ ${Number(data.resumen.total_descuento_soles).toFixed(2)}` },
            ].map((item) => (
              <div key={item.label} className="kyro-card p-4">
                <p className="text-xl font-bold text-kyro-text">{item.value}</p>
                <p className="text-xs text-kyro-muted">{item.label}</p>
              </div>
            ))}
          </section>

          <section className="kyro-card overflow-x-auto p-4">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="border-b border-kyro-border text-left text-xs uppercase text-kyro-muted">
                  <th className="pb-2">Fecha</th>
                  <th className="pb-2">Estado</th>
                  <th className="pb-2">Entrada</th>
                  <th className="pb-2">Salida</th>
                  <th className="pb-2 text-right">Tardanza</th>
                  <th className="pb-2 text-right">Deuda</th>
                  <th className="pb-2">Comodin</th>
                  <th className="pb-2">Turno</th>
                  <th className="pb-2 text-right">Descuento</th>
                </tr>
              </thead>
              <tbody>
                {data.dias.length === 0 && (
                  <tr>
                    <td colSpan={9} className="py-6 text-center text-kyro-muted">Sin asistencias en el periodo.</td>
                  </tr>
                )}
                {data.dias.map((dia) => (
                  <tr key={dia.fecha} className="border-b border-kyro-border/70 text-kyro-body last:border-0">
                    <td className="py-2">{dia.fecha}</td>
                    <td className="py-2">{dia.estado}</td>
                    <td className="py-2">{dia.hora_entrada?.slice(0, 5) ?? '-'}</td>
                    <td className="py-2">{dia.hora_salida?.slice(0, 5) ?? '-'}</td>
                    <td className="py-2 text-right">{dia.minutos_tardanza}</td>
                    <td className="py-2 text-right">{dia.minutos_deuda}</td>
                    <td className="py-2">{dia.uso_comodin ? 'Si' : '-'}</td>
                    <td className="py-2">{dia.omitio_refrigerio ? 'Corrido' : 'Regular'}</td>
                    <td className="py-2 text-right">S/ {Number(dia.descuento_soles).toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        </>
      )}
    </div>
  )
}
