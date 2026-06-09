import { api } from './api'
import type { PaginatedResponse } from '../types/pagination'
import type { Reporte } from '../types/reporte'

export interface HistorialReporte extends Reporte {
  agente_nombre: string
}

export interface HistorialFilters {
  fecha_desde?: string
  fecha_hasta?: string
  tienda?: string
  agente_id?: number | string
  estado?: string
  page?: number
  per_page?: number
}

export const historialApi = {
  listar: (params?: HistorialFilters) =>
    api
      .get<PaginatedResponse<HistorialReporte>>('/v1/historial', { params })
      .then((r) => r.data),
}
