import { api } from './api'
import type { PaginatedResponse } from '../types/pagination'
import type { Reporte } from '../types/reporte'

export interface HistorialReporte extends Reporte {
  agente_nombre: string
  /** Ganancia de la empresa por reporte (equipos + comisión de líneas). Solo viene para rol admin. */
  ganancia?: number | string | null
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

export interface HistorialTotales {
  total_general: number
  fisico_esperado: number
  fisico_declarado: number
  diferencia_fisica: number
  total_yape: number
  total_bipay: number
  total_transferencia: number
  total_reportes: number
}

export interface HistorialKpis {
  totales: HistorialTotales
  ganancia_total: number | null
}

export const historialApi = {
  listar: (params?: HistorialFilters) =>
    api
      .get<PaginatedResponse<HistorialReporte>>('/v1/historial', { params })
      .then((r) => r.data),
  kpis: (params?: HistorialFilters) =>
    api
      .get<HistorialKpis>('/v1/historial/kpis', { params })
      .then((r) => r.data),
}
