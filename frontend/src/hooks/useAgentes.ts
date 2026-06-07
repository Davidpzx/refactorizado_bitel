import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { agentesApi } from '../services/agentes.api'
import type { AgenteFormData, AgenteParams } from '../types/agente'

export function useAgentes(params?: AgenteParams) {
  return useQuery({
    queryKey: ['agentes', params],
    queryFn: () => agentesApi.list(params),
  })
}

export function useAgente(id: number) {
  return useQuery({
    queryKey: ['agentes', id],
    queryFn: () => agentesApi.get(id),
    enabled: !!id,
  })
}

export function useCrearAgente() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: AgenteFormData) => agentesApi.create(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['agentes'] }),
  })
}

export function useActualizarAgente() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<AgenteFormData> }) =>
      agentesApi.update(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['agentes'] }),
  })
}

export function useEliminarAgente() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => agentesApi.destroy(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['agentes'] }),
  })
}
