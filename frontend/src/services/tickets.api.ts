import { api } from './api'
import type { Ticket, TicketFilters, TicketPayload, TicketUpdatePayload } from '../types/ticket'
import type { PaginatedResponse } from '../types/pagination'

export const ticketsApi = {
  listar: (params: TicketFilters) =>
    api.get<PaginatedResponse<Ticket>>('/v1/tickets', { params }).then((r) => r.data),

  crear: (data: TicketPayload) =>
    api.post<Ticket>('/v1/tickets', data).then((r) => r.data),

  actualizar: (id: number, data: TicketUpdatePayload) =>
    api.patch<Ticket>(`/v1/tickets/${id}`, data).then((r) => r.data),
}
