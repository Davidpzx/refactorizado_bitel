import { useQuery } from '@tanstack/react-query'
import { api } from '../services/api'

export interface TiendaOption {
  id?: number
  codigo: string
  nombre: string
}

/**
 * Hook ligero para poblar <select> de tiendas en filtros.
 * Usa staleTime largo porque las tiendas cambian poco.
 */
export function useTiendasSelect() {
  const { data, isLoading } = useQuery<TiendaOption[]>({
    queryKey: ['tiendas-select'],
    queryFn: () =>
      api.get('/v1/tiendas/select').then(r => {
        const raw = r.data
        return Array.isArray(raw) ? raw : (raw?.data ?? [])
      }),
    staleTime: 10 * 60_000,
  })

  return { tiendas: data ?? [], isLoading }
}
