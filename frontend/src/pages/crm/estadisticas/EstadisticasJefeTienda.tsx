import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../../../hooks/useAuth'
import { api } from '../../../services/api'

export function EstadisticasJefeTienda() {
  const { usuario } = useAuth()
  const { data, isLoading } = useQuery({
    queryKey: ['crm-estadisticas-jt', usuario?.tienda_id],
    queryFn: () => api.get<{ leads_activos: number; conversion_mes: number; ventas_mes: number }>(
      `/v1/crm/estadisticas-resumen?tienda_id=${usuario?.tienda_id}`,
    ).then(r => r.data),
    enabled: !!usuario?.tienda_id,
  })

  if (isLoading) return <div className="text-sm text-kyro-muted">Cargando...</div>

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
      <div className="rounded-kyro border border-kyro-border bg-kyro-surface p-4">
        <p className="text-xs text-kyro-muted">Leads activos</p>
        <p className="text-2xl font-bold">{data?.leads_activos ?? 0}</p>
      </div>
      <div className="rounded-kyro border border-kyro-border bg-kyro-surface p-4">
        <p className="text-xs text-kyro-muted">Conversion del mes</p>
        <p className="text-2xl font-bold">{data?.conversion_mes ?? 0}%</p>
      </div>
      <div className="rounded-kyro border border-kyro-border bg-kyro-surface p-4">
        <p className="text-xs text-kyro-muted">Ventas del mes</p>
        <p className="text-2xl font-bold">S/ {(data?.ventas_mes ?? 0).toFixed(2)}</p>
      </div>
    </div>
  )
}
