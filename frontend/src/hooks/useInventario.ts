import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { inventarioApi } from '../services/inventario.api'
import type { InventarioFilters, InventarioPayload } from '../types/inventario'

export function useInventario(filters: InventarioFilters) {
  return useQuery({
    queryKey: ['inventario', filters],
    queryFn: () => inventarioApi.listar(filters).then((r) => r.data),
  })
}

export function useCrearInventario() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: InventarioPayload) => inventarioApi.crear(data).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['inventario'] }),
  })
}

export function useActualizarInventario() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<InventarioPayload> }) =>
      inventarioApi.actualizar(id, data).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['inventario'] }),
  })
}

export function useEliminarInventario() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => inventarioApi.eliminar(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['inventario'] }),
  })
}
