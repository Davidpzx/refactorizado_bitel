import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { postulacionesApi } from '../services/postulaciones.api'
import type { PostulacionFilters } from '../types/postulacion'

export function usePostulaciones(filters: PostulacionFilters) {
  return useQuery({
    queryKey: ['postulaciones', filters],
    queryFn: () => postulacionesApi.listar(filters),
  })
}

export function usePostulacion(id: number | null) {
  return useQuery({
    queryKey: ['postulaciones', id],
    queryFn: () => postulacionesApi.obtener(id!),
    enabled: id !== null,
  })
}

export function useActualizarPostulacion() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: { estado: string; notas_admin?: string } }) =>
      postulacionesApi.actualizar(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['postulaciones'] }),
  })
}

export function useEliminarPostulacion() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => postulacionesApi.eliminar(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['postulaciones'] }),
  })
}

export function useTiendasPublicas() {
  return useQuery({
    queryKey: ['postulaciones-tiendas'],
    queryFn: () => postulacionesApi.tiendas(),
    staleTime: 5 * 60_000,
  })
}
