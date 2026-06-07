import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { clientesApi } from '../services/clientes.api'
import type { ClienteFormData, ClienteParams } from '../types/cliente'

export function useClientes(params?: ClienteParams) {
  return useQuery({
    queryKey: ['clientes', params],
    queryFn: () => clientesApi.list(params),
  })
}

export function useCliente(id: number) {
  return useQuery({
    queryKey: ['clientes', id],
    queryFn: () => clientesApi.get(id),
    enabled: !!id,
  })
}

export function useCrearCliente() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: ClienteFormData) => clientesApi.create(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['clientes'] }),
  })
}

export function useActualizarCliente() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<ClienteFormData> }) =>
      clientesApi.update(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['clientes'] }),
  })
}

export function useEliminarCliente() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => clientesApi.destroy(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['clientes'] }),
  })
}
