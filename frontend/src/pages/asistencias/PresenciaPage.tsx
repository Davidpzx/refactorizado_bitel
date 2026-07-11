import { useQuery } from '@tanstack/react-query'
import { adminPaginasApi, type EstadoPresencia, type PresenciaAgenteItem } from '../../services/adminPaginas.api'
import { PageHeader } from '../../components/PageHeader'
import { AsistenciasTabs } from './AsistenciasTabs'
import { AppTerminalDescarga } from './AppTerminalDescarga'
import { Card } from '../../components/ui/card'
import { MapPinLine, BatteryFull, BatteryLow, WarningCircle, CheckCircle, XCircle } from '@phosphor-icons/react'

const ESTADO_INFO: Record<EstadoPresencia, { label: string; dot: string; texto: string }> = {
  ok:             { label: 'En tienda',       dot: 'bg-kyro-success',  texto: 'text-kyro-success' },
  fuera_de_rango: { label: 'Fuera de rango',  dot: 'bg-kyro-danger',   texto: 'text-kyro-danger' },
  mock_gps:       { label: 'GPS sospechoso',  dot: 'bg-kyro-danger',   texto: 'text-kyro-danger' },
  sin_ping:       { label: 'Sin señal',       dot: 'bg-kyro-warning',  texto: 'text-kyro-warning' },
}

function IconoEstado({ estado }: { estado: EstadoPresencia }) {
  if (estado === 'ok') return <CheckCircle size={16} weight="fill" className="text-kyro-success" />
  if (estado === 'sin_ping') return <WarningCircle size={16} weight="fill" className="text-kyro-warning" />
  return <XCircle size={16} weight="fill" className="text-kyro-danger" />
}

function textoMinutos(min: number | null): string {
  if (min === null) return '—'
  if (min < 1) return 'hace instantes'
  if (min < 60) return `hace ${min} min`
  return `hace ${Math.floor(min / 60)}h ${min % 60}min`
}

export function PresenciaPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['asistencias-presencia'],
    queryFn: () => adminPaginasApi.presencia(),
    // El ping llega cada 30 min; refrescar cada minuto es suficiente para "en vivo"
    // sin generar tráfico innecesario.
    refetchInterval: 60_000,
  })

  const items = data?.data ?? []
  const enTienda = items.filter((i) => i.estado === 'ok').length
  const conIncidencia = items.filter((i) => i.estado !== 'ok').length

  return (
    <div className="max-w-6xl mx-auto space-y-6">
      <PageHeader
        title="Presencia en vivo"
        description="Semáforo de ubicación de los agentes con turno abierto — se actualiza con cada ping de la app de asistencia (cada 30 min)."
        Icon={MapPinLine}
      />

      <AsistenciasTabs />

      <AppTerminalDescarga />

      {isLoading ? (
        <Card className="kyro-card p-6"><p className="text-sm text-kyro-muted">Cargando…</p></Card>
      ) : items.length === 0 ? (
        <Card className="kyro-card p-6"><p className="text-sm text-kyro-muted">No hay agentes con turno abierto en este momento.</p></Card>
      ) : (
        <>
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
            <Card className="premium-kpi rounded-kyro-xl p-4">
              <p className="text-[0.68rem] font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-zinc-400">En turno</p>
              <p className="mt-2 text-xl font-bold tracking-tight text-kyro-text">{items.length}</p>
            </Card>
            <Card className="premium-kpi rounded-kyro-xl p-4">
              <p className="text-[0.68rem] font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-zinc-400">En tienda</p>
              <p className="mt-2 text-xl font-bold tracking-tight text-kyro-success">{enTienda}</p>
            </Card>
            <Card className="premium-kpi rounded-kyro-xl p-4">
              <p className="text-[0.68rem] font-semibold uppercase tracking-[0.08em] text-gray-500 dark:text-zinc-400">Con incidencia</p>
              <p className="mt-2 text-xl font-bold tracking-tight text-kyro-danger">{conIncidencia}</p>
            </Card>
          </div>

          <Card className="kyro-card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="kyro-table-head">
                  <tr>
                    <th className="text-left">Agente</th>
                    <th className="text-left">Tienda</th>
                    <th className="text-left">Estado</th>
                    <th className="text-left">Último ping</th>
                    <th className="text-left">Distancia</th>
                    <th className="text-left">Batería</th>
                    <th className="text-left">Incidencias hoy</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item: PresenciaAgenteItem) => {
                    const info = ESTADO_INFO[item.estado]
                    return (
                      <tr key={item.agente_id} className="border-t border-kyro-border hover:bg-kyro-indigo/[0.04]">
                        <td className="px-4 py-3 font-medium text-kyro-text">{item.nombre}</td>
                        <td className="px-4 py-3 text-kyro-muted">{item.tienda ?? '—'}</td>
                        <td className="px-4 py-3">
                          <span className={`inline-flex items-center gap-1.5 text-xs font-semibold ${info.texto}`}>
                            <IconoEstado estado={item.estado} />
                            {info.label}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-kyro-muted">{textoMinutos(item.minutos_desde_ping)}</td>
                        <td className="px-4 py-3 text-kyro-muted">{item.distancia !== null ? `${item.distancia} m` : '—'}</td>
                        <td className="px-4 py-3 text-kyro-muted">
                          {item.battery_pct !== null ? (
                            <span className="inline-flex items-center gap-1">
                              {item.battery_pct < 20 ? <BatteryLow size={14} className="text-kyro-danger" /> : <BatteryFull size={14} />}
                              {item.battery_pct}%
                            </span>
                          ) : '—'}
                        </td>
                        <td className="px-4 py-3">
                          {item.incidencias_dia > 0 ? (
                            <span className="rounded-full bg-kyro-danger/10 px-2 py-0.5 text-xs font-bold text-kyro-danger">{item.incidencias_dia}</span>
                          ) : (
                            <span className="text-kyro-muted">0</span>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}
    </div>
  )
}
