import { useQuery } from '@tanstack/react-query'
import { api } from '../services/api'

export interface AgenteOption {
  id: number
  nombres: string
  /** Solo los últimos 4 dígitos del DNI (SEC-11) — nunca el DNI completo. */
  dni_ultimos4: string
  tienda_base?: string
}

/**
 * Hook ligero para poblar <select> de agentes en filtros y formularios.
 * Pide per_page=500 para traer todos sin paginación.
 */
export function useAgentesSelect() {
  const { data, isLoading } = useQuery<AgenteOption[]>({
    queryKey: ['agentes-select'],
    queryFn: () =>
      api.get('/v1/agentes/select').then(r => {
        const raw = r.data
        return Array.isArray(raw) ? raw : (raw?.data ?? [])
      }),
    staleTime: 5 * 60_000,
  })

  return { agentes: data ?? [], isLoading }
}
