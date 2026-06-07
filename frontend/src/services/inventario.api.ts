import axios from 'axios'
import type { InventarioFilters, InventarioItem, InventarioPayload } from '../types/inventario'
import type { PaginatedResponse } from '../types/pagination'

const BASE = import.meta.env.VITE_API_BASE_URL

export const inventarioApi = {
  listar: (params: InventarioFilters) =>
    axios.get<PaginatedResponse<InventarioItem>>(`${BASE}/inventario`, { params }),

  crear: (data: InventarioPayload) =>
    axios.post<InventarioItem>(`${BASE}/inventario`, data),

  actualizar: (id: number, data: Partial<InventarioPayload>) =>
    axios.put<InventarioItem>(`${BASE}/inventario/${id}`, data),

  eliminar: (id: number) =>
    axios.delete(`${BASE}/inventario/${id}`),
}
