import { api } from './api'
import type { Cliente, ClienteFormData, ClienteParams } from '../types/cliente'
import type { PaginatedResponse } from '../types/pagination'

export const clientesApi = {
  list: (params?: ClienteParams) =>
    api.get<PaginatedResponse<Cliente>>('/v1/clientes', { params }).then((r) => r.data),

  get: (id: number) =>
    api.get<Cliente>(`/v1/clientes/${id}`).then((r) => r.data),

  create: (data: ClienteFormData) =>
    api.post<Cliente>('/v1/clientes', data).then((r) => r.data),

  update: (id: number, data: Partial<ClienteFormData>) =>
    api.put<Cliente>(`/v1/clientes/${id}`, data).then((r) => r.data),

  destroy: (id: number) =>
    api.delete(`/v1/clientes/${id}`),
}
