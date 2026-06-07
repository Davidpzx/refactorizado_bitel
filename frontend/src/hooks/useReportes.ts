import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { reportesApi } from '../services/reportes.api'
import type { CreateReportePayload, ReporteFilters } from '../types/reporte'

export function useReportes(filters: ReporteFilters) {
  return useQuery({
    queryKey: ['reportes', filters],
    queryFn: () => reportesApi.listar(filters),
  })
}

export function useReporte(id: number) {
  return useQuery({
    queryKey: ['reportes', id],
    queryFn: () => reportesApi.obtener(id),
    enabled: !!id,
  })
}

export function useCrearReporte() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateReportePayload) => reportesApi.crear(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['reportes'] }),
  })
}

export function useEliminarReporte() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => reportesApi.eliminar(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['reportes'] }),
  })
}

export function usePlanesComisiones(tipo_servicio?: string) {
  return useQuery({
    queryKey: ['planes_comisiones', tipo_servicio],
    queryFn: () => reportesApi.planesComisiones(tipo_servicio),
    staleTime: 300_000,
  })
}
