import { Fragment, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../services/api'
import { PageHeader } from '../../components/PageHeader'
import { AsistenciasTabs } from './AsistenciasTabs'
import { Input } from '../../components/ui/input'
import { CalendarCheck } from '@phosphor-icons/react'

interface CeldaMatriz {
  fecha: string
  asistencia_id: number | null
  estado: 'DESCANSO' | 'FUTURO' | 'SIN_MARCA' | 'FALTA' | 'PERMISO' | 'FERIADO' | 'MEDIO_TIEMPO' | 'TARDANZA' | 'OK'
  minutos_tardanza: number
  hora_ingreso: string | null
  inicio_refrigerio: string | null
  fin_refrigerio: string | null
  hora_salida: string | null
  omitio_refrigerio: boolean
  excepcion_tipo: string | null
}

interface AgenteMatriz {
  id: number
  nombre: string
  dia_descanso: string | null
  dias: Record<string, CeldaMatriz>
}

interface TiendaMatriz {
  tienda: string
  agentes: AgenteMatriz[]
}

interface MatrizResponse {
  mes: string
  dias_mostrar: number
  tiendas: TiendaMatriz[]
}

const ESTADO_ESTILO: Record<CeldaMatriz['estado'], string> = {
  OK: 'text-kyro-success',
  TARDANZA: 'text-kyro-warning font-bold bg-kyro-warning/10',
  FALTA: 'text-kyro-danger font-bold bg-kyro-danger/10',
  PERMISO: 'text-kyro-info bg-kyro-info/10',
  FERIADO: 'text-yellow-500 bg-yellow-500/10',
  MEDIO_TIEMPO: 'text-orange-400 bg-orange-400/10',
  DESCANSO: 'text-kyro-subtle bg-kyro-elevated/60',
  SIN_MARCA: 'text-kyro-subtle',
  FUTURO: 'text-kyro-subtle',
}

const ESTADO_LABEL: Record<CeldaMatriz['estado'], string> = {
  OK: 'OK',
  TARDANZA: '',
  FALTA: 'FALTA',
  PERMISO: 'PERMISO',
  FERIADO: 'FERIADO',
  MEDIO_TIEMPO: '½T',
  DESCANSO: 'DESC.',
  SIN_MARCA: '—',
  FUTURO: '—',
}

export function ControlAsistenciasPage() {
  const qc = useQueryClient()
  const [mes, setMes] = useState(new Date().toISOString().slice(0, 7))
  const [celda, setCelda] = useState<{ agenteId: number; fecha: string } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['asistencias-matriz', mes],
    queryFn: () => api.get<MatrizResponse>('/v1/asistencias/matriz', { params: { mes } }).then(r => r.data),
  })

  const toggleExcepcion = useMutation({
    mutationFn: (vars: { agenteId: number; fecha: string }) =>
      api.post('/v1/asistencias/excepcion-jornada', { agente_id: vars.agenteId, fecha: vars.fecha }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['asistencias-matriz', mes] }),
  })

  const dias = useMemo(
    () => Array.from({ length: data?.dias_mostrar ?? 0 }, (_, i) => i + 1),
    [data?.dias_mostrar],
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title="Control de Asistencia"
        subtitle="Matriz mensual agente x dia, agrupada por tienda"
        Icon={CalendarCheck}
        actions={
          <Input type="month" value={mes} onChange={e => setMes(e.target.value)} className="kyro-input w-40" />
        }
      />

      <AsistenciasTabs />

      <div className="flex flex-wrap items-center gap-3 text-xs text-kyro-subtle">
        <span className="flex items-center gap-1"><span className="text-kyro-success">■</span> OK</span>
        <span className="flex items-center gap-1"><span className="text-kyro-warning">■</span> Tardanza/Falta</span>
        <span className="flex items-center gap-1"><span className="text-yellow-500">■</span> Feriado</span>
        <span className="flex items-center gap-1"><span className="text-kyro-info">■</span> Permiso</span>
        <span className="flex items-center gap-1"><span className="text-orange-400">■</span> Medio tiempo</span>
        <span className="flex items-center gap-1"><span className="text-kyro-subtle">■</span> Descanso</span>
        <span className="ml-auto">Clic en una celda para alternar excepcion de medio tiempo (½T)</span>
      </div>

      <div className="kyro-card overflow-auto" style={{ maxHeight: 'calc(100vh - 260px)' }}>
        {isLoading && <p className="p-6 text-center text-kyro-subtle">Cargando matriz...</p>}
        {!isLoading && (data?.tiendas.length ?? 0) === 0 && (
          <p className="p-6 text-center text-kyro-subtle">Sin agentes activos para mostrar.</p>
        )}
        {!isLoading && (data?.tiendas.length ?? 0) > 0 && (
          <table className="border-collapse text-xs font-mono whitespace-nowrap">
            <thead>
              <tr>
                <th className="sticky left-0 top-0 z-20 bg-kyro-elevated px-2 py-1 text-left text-kyro-subtle">AGENTE</th>
                {dias.map(d => (
                  <th key={d} className="sticky top-0 z-10 bg-kyro-elevated px-2 py-1 text-center text-kyro-subtle">{d}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data?.tiendas.map(t => (
                <Fragment key={t.tienda}>
                  <tr>
                    <td colSpan={dias.length + 1} className="bg-kyro-indigo/10 px-2 py-1 font-semibold text-kyro-indigo">
                      {t.tienda}
                    </td>
                  </tr>
                  {t.agentes.map(agente => (
                    <tr key={agente.id} className="border-t border-kyro-border">
                      <td className="sticky left-0 z-10 bg-kyro-panel px-2 py-1 font-semibold text-kyro-text">{agente.nombre}</td>
                      {dias.map(d => {
                        const c = agente.dias[String(d)]
                        if (!c) return <td key={d} />
                        const esEditable = c.asistencia_id !== null && c.estado !== 'DESCANSO' && c.estado !== 'FUTURO'
                        const label = c.estado === 'TARDANZA' ? `${c.minutos_tardanza}m` : ESTADO_LABEL[c.estado]

                        return (
                          <td
                            key={d}
                            title={esEditable ? 'Clic para alternar medio tiempo' : undefined}
                            className={`px-2 py-1 text-center ${ESTADO_ESTILO[c.estado]} ${esEditable ? 'cursor-pointer hover:outline hover:outline-1 hover:outline-kyro-warning' : ''}`}
                            onClick={() => {
                              if (!esEditable) return
                              setCelda({ agenteId: agente.id, fecha: c.fecha })
                              toggleExcepcion.mutate({ agenteId: agente.id, fecha: c.fecha })
                            }}
                          >
                            {toggleExcepcion.isPending && celda?.agenteId === agente.id && celda?.fecha === c.fecha ? '...' : label}
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                </Fragment>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
