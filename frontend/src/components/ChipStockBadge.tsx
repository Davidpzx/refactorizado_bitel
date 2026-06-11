import { useQuery } from '@tanstack/react-query'
import { api } from '../services/api'

interface ChipRow {
  stock_actual?: number
  tipo?: string
  operador?: string
  plan_nombre?: string
  descripcion?: string
}

export function ChipStockBadge() {
  const { data } = useQuery({
    queryKey: ['inventario-chips-badge'],
    queryFn: () => api.get<{ data: ChipRow[] }>('/v1/inventario-chips').then((r) => r.data.data),
    staleTime: 60_000,
    retry: false,
  })

  if (!data) return null
  const total = data.reduce((acc, c) => acc + (Number(c.stock_actual) || 0), 0)
  const detalle = data
    .filter((c) => (Number(c.stock_actual) || 0) > 0)
    .map((c) => `${c.plan_nombre ?? c.descripcion ?? c.tipo ?? c.operador ?? 'Chip'}: ${c.stock_actual}`)
    .join(' · ')

  return (
    <span
      title={detalle || 'Sin chips físicos en stock'}
      className={`text-xs font-semibold px-2.5 py-1 rounded-full flex items-center gap-1
        ${total > 0
          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
          : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'}`}
    >
      📶 {total} chips
    </span>
  )
}
