import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { WarningOctagon, Warning, ShieldCheck, CaretDown, CaretUp } from '@phosphor-icons/react'
import { adminPaginasApi } from '../../services/adminPaginas.api'
import { useAuth } from '../../hooks/useAuth'
import { Button } from '../../components/ui/button'

/** Filas visibles antes de pulsar "Mostrar todos" (paridad legacy panel_asistencias.php). */
const FILAS_INICIALES = 5

const COLUMNAS = [
  'Fecha / Hora',
  'Agente (intentó marcar)',
  'DNI ingresado',
  'DNI dueño real del celular',
  'Tienda del intento',
]

function formatearFecha(valor: string): string {
  const fecha = new Date(valor.replace(' ', 'T'))
  if (Number.isNaN(fecha.getTime())) return valor
  return fecha.toLocaleString('es-PE', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
  })
}

/**
 * Monitor de Fraude de Dispositivos — calca `gerencia/panel_asistencias.php` del legacy.
 * Se alimenta de `log_fraude_dispositivo`, que el backend ya escribe cuando el hash
 * facial del celular no corresponde al agente que intenta marcar (DEVICE_MISMATCH).
 * Solo lectura y solo admin: el legacy tampoco ofrece acciones sobre el log.
 */
export function MonitorFraudePanel() {
  const { usuario } = useAuth()
  const esAdmin = usuario?.rol === 'admin'
  const [expandido, setExpandido] = useState(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['fraude-dispositivos'],
    queryFn: () => adminPaginasApi.fraudeDispositivos(),
    enabled: esAdmin,
    staleTime: 30_000,
  })

  if (!esAdmin) return null

  const alertas = data?.data ?? []
  const total = data?.total ?? 0
  const visibles = expandido ? alertas : alertas.slice(0, FILAS_INICIALES)
  const hayOcultas = alertas.length > FILAS_INICIALES

  return (
    <section className="mt-8">
      <div className="mb-3 flex items-center gap-2">
        <WarningOctagon size={22} weight="fill" className="text-kyro-danger" />
        <h2 className="text-sm font-semibold text-kyro-text">Monitor de Fraude de Dispositivos</h2>
        {total > 0 && (
          <span className="rounded-full bg-kyro-danger px-2 py-0.5 text-xs font-semibold text-white">
            {total} {total === 1 ? 'alerta' : 'alertas'}
          </span>
        )}
      </div>

      <div className="kyro-card overflow-hidden border-t-2 border-t-kyro-danger/50">
        {isLoading && <p className="px-4 py-10 text-center text-gray-400">Cargando alertas...</p>}

        {isError && (
          <p className="px-4 py-10 text-center text-sm text-kyro-danger">
            No se pudieron cargar las alertas de fraude.
          </p>
        )}

        {!isLoading && !isError && alertas.length === 0 && (
          <div className="px-4 py-10 text-center">
            <ShieldCheck size={40} weight="fill" className="mx-auto text-kyro-success" />
            <p className="mt-2 text-sm text-kyro-muted">
              Sin alertas de fraude registradas. El sistema está limpio.
            </p>
          </div>
        )}

        {!isLoading && !isError && alertas.length > 0 && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr>
                    {COLUMNAS.map(h => (
                      <th key={h} className="kyro-table-head px-4 py-3 text-left">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {visibles.map(a => {
                    const distinto = Boolean(a.dni_duenio_hash) && a.dni_duenio_hash !== a.dni_ingresado

                    return (
                      <tr key={a.id} className="border-b border-kyro-border hover:bg-kyro-elevated/50">
                        <td className="px-4 py-3 text-xs text-kyro-subtle">{formatearFecha(a.fecha_hora)}</td>
                        <td className="px-4 py-3 font-semibold text-kyro-danger">
                          <span className="flex items-center gap-1">
                            <Warning size={14} weight="fill" />
                            {a.nombre_agente || '—'}
                          </span>
                        </td>
                        <td className="px-4 py-3 font-mono text-kyro-warning">{a.dni_ingresado || '—'}</td>
                        <td className="px-4 py-3">
                          {a.dni_duenio_hash ? (
                            <span className="flex items-center gap-2">
                              <code className="font-mono text-kyro-info">{a.dni_duenio_hash}</code>
                              {distinto && (
                                <span className="rounded-full bg-kyro-danger/20 px-2 py-0.5 text-[0.65rem] font-medium text-kyro-danger">
                                  ≠ DIFERENTE
                                </span>
                              )}
                            </span>
                          ) : (
                            <span className="text-xs text-kyro-muted">No identificado</span>
                          )}
                        </td>
                        <td className="px-4 py-3">
                          <span className="rounded-full bg-kyro-indigo/15 px-2 py-0.5 text-xs font-medium text-kyro-indigo">
                            {a.tienda_intento || '—'}
                          </span>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            {hayOcultas && (
              <div className="border-t border-kyro-danger/20 bg-black/20 p-3 text-center">
                <Button variant="outline" onClick={() => setExpandido(v => !v)}>
                  {expandido ? (
                    <>Mostrar menos <CaretUp size={14} weight="bold" /></>
                  ) : (
                    <>Mostrar todos (Total: {alertas.length}) <CaretDown size={14} weight="bold" /></>
                  )}
                </Button>
              </div>
            )}
          </>
        )}
      </div>
    </section>
  )
}
