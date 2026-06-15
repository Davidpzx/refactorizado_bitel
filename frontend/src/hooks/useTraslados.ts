import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { trasladosApi, trasladosChipsApi } from '../services/traslados.api'
import type {
  TrasladoFilters,
  CrearTrasladoPayload,
  ConfirmarTrasladoPayload,
  GestionarTrasladoPayload,
  TrasladoChipFilters,
  CrearTrasladoChipPayload,
} from '../types/traslados'

export function useTraslados(filters: TrasladoFilters) {
  return useQuery({
    queryKey: ['traslados', filters],
    queryFn: () => trasladosApi.listar(filters),
  })
}

export function useCrearTraslado() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: CrearTrasladoPayload) => trasladosApi.crear(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['traslados'] }),
  })
}

export function useConfirmarTraslado() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: ConfirmarTrasladoPayload }) =>
      trasladosApi.confirmar(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['traslados'] }),
  })
}

export function useConfirmarLoteTraslado() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ codigoLote, data }: { codigoLote: string; data: ConfirmarTrasladoPayload }) =>
      trasladosApi.confirmarLote(codigoLote, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['traslados'] }),
  })
}

export function useGestionarTraslado() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: GestionarTrasladoPayload }) =>
      trasladosApi.gestionar(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['traslados'] }),
  })
}

export function useTrasladosChips(filters: TrasladoChipFilters) {
  return useQuery({
    queryKey: ['traslados-chips', filters],
    queryFn: () => trasladosChipsApi.listar(filters),
  })
}

export function useCrearTrasladoChip() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: CrearTrasladoChipPayload) => trasladosChipsApi.crear(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['traslados-chips'] })
      qc.invalidateQueries({ queryKey: ['chips-stock'] })
    },
  })
}

export function useConfirmarTrasladoChip() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: ConfirmarTrasladoPayload }) =>
      trasladosChipsApi.confirmar(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['traslados-chips'] }),
  })
}

export function useGestionarTrasladoChip() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: GestionarTrasladoPayload }) =>
      trasladosChipsApi.gestionar(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['traslados-chips'] }),
  })
}

export function useStockChips() {
  return useQuery({
    queryKey: ['chips-stock'],
    queryFn: () => trasladosChipsApi.stockChips(),
  })
}
