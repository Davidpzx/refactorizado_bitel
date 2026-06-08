import { api } from './api'
import type { ComisionPlan, CreateReportePayload, Reporte, ReporteConVentas, ReporteFilters } from '../types/reporte'
import type { PaginatedResponse } from '../types/pagination'

export const reportesApi = {
  listar: (params: ReporteFilters) =>
    api.get<PaginatedResponse<Reporte>>('/v1/reportes', { params }).then((r) => r.data),

  obtener: (id: number) =>
    api.get<ReporteConVentas>(`/v1/reportes/${id}`).then((r) => r.data),

  crear: (data: CreateReportePayload) =>
    api.post<Reporte>('/v1/reportes', data).then((r) => r.data),

  actualizar: (id: number, data: Partial<CreateReportePayload>) =>
    api.put<Reporte>(`/v1/reportes/${id}`, data).then((r) => r.data),

  eliminar: (id: number) =>
    api.delete(`/v1/reportes/${id}`),

  planesComisiones: (tipo_servicio?: string) =>
    api.get<ComisionPlan[]>('/v1/comisiones-planes', { params: { tipo_servicio } }).then((r) => r.data),
}
