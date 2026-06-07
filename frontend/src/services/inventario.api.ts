import { api } from './api'
import type { InventarioFilters, InventarioItem, InventarioPayload } from '../types/inventario'
import type { PaginatedResponse } from '../types/pagination'

export const inventarioApi = {
  listar: (params: InventarioFilters) =>
    api.get<PaginatedResponse<InventarioItem>>('/v1/inventario', { params }).then((r) => r.data),

  crear: (data: InventarioPayload) =>
    api.post<InventarioItem>('/v1/inventario', data).then((r) => r.data),

  actualizar: (id: number, data: Partial<InventarioPayload>) =>
    api.put<InventarioItem>(`/v1/inventario/${id}`, data).then((r) => r.data),

  eliminar: (id: number) =>
    api.delete(`/v1/inventario/${id}`),
}
