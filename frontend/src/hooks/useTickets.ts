import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ticketsApi } from '../services/tickets.api'
import type { TicketFilters, TicketPayload, TicketUpdatePayload } from '../types/ticket'

export function useTickets(filters: TicketFilters) {
  return useQuery({
    queryKey: ['tickets', filters],
    queryFn: () => ticketsApi.listar(filters),
  })
}

export function useCrearTicket() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: TicketPayload) => ticketsApi.crear(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tickets'] }),
  })
}

export function useActualizarTicket() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: TicketUpdatePayload }) =>
      ticketsApi.actualizar(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tickets'] }),
  })
}
