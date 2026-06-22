import { api } from './api'
import type { ComisionPlan, CreateReportePayload, Reporte, ReporteConVentas, ReporteFilters, VendedorReporte } from '../types/reporte'
import type { PaginatedResponse } from '../types/pagination'

export const reportesApi = {
  listar: (params: ReporteFilters) =>
    api.get<PaginatedResponse<Reporte>>('/v1/reportes', { params }).then((r) => r.data),

  obtener: (id: number) =>
    api.get<ReporteConVentas>(`/v1/reportes/${id}`).then((r) => r.data),

  crear: (data: CreateReportePayload) =>
    api.post<Reporte>('/v1/reportes', data).then((r) => r.data),

  reprocesar: (id: number, data: CreateReportePayload) =>
    api.put<ReporteConVentas>(`/v1/reportes/${id}/reprocesar`, data).then((r) => r.data),

  vendedores: (tienda_id?: string) =>
    api.get<VendedorReporte[]>('/v1/reportes/vendedores', { params: { tienda_id } }).then((r) => r.data),

  actualizar: (id: number, data: Partial<CreateReportePayload>) =>
    api.put<Reporte>(`/v1/reportes/${id}`, data).then((r) => r.data),

  eliminar: (id: number) =>
    api.delete(`/v1/reportes/${id}`),

  planesComisiones: (tipo_servicio?: string) =>
    api.get<ComisionPlan[]>('/v1/comisiones-planes', { params: { tipo_servicio } }).then((r) => r.data),

  cambiarDestino: (id: number, destino_efectivo: string, observacion?: string) =>
    api.patch(`/v1/reportes/${id}/destino-efectivo`, { destino_efectivo, observacion }).then((r) => r.data),

  editarAprobado: (id: number, data: { efectivo_entregado?: number; destino_efectivo?: string; observaciones?: string; motivo_edicion?: string }) =>
    api.put<Reporte>(`/v1/reportes/${id}`, data).then((r) => r.data),

  historial: (id: number) =>
    api.get<HistorialReporteEntry[]>(`/v1/reportes/${id}/historial`).then((r) => r.data),

  aprobarEdicion: (id: number) =>
    api.post(`/v1/reportes/${id}/aprobar-edicion`).then((r) => r.data),

  denegarEdicion: (id: number, motivo?: string) =>
    api.post(`/v1/reportes/${id}/denegar-edicion`, { motivo }).then((r) => r.data),
}

export interface HistorialReporteEntry {
  id: number
  reporte_id: number
  usuario_id: number | null
  accion: 'crear' | 'solicito_edicion' | 'edicion_aprobada' | 'edicion_rechazada' | 'edicion_reporte' | 'edicion_critica' | 'edicion_restaurada' | 'destino_modificado'
  detalle: string | null
  snapshot_antes: Record<string, unknown> | null
  snapshot_despues: Record<string, unknown> | null
  created_at: string
  updated_at: string
  usuario?: { id: number; nombre: string } | null
}
