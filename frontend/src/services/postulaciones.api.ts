import { api } from './api'
import type { Postulacion, PostulacionFilters, PostulacionPublicaPayload } from '../types/postulacion'
import type { PaginatedResponse } from '../types/pagination'

export const postulacionesApi = {
  listar: (params: PostulacionFilters) =>
    api.get<PaginatedResponse<Postulacion>>('/v1/postulaciones', { params }).then((r) => r.data),

  obtener: (id: number) =>
    api.get<Postulacion>(`/v1/postulaciones/${id}`).then((r) => r.data),

  actualizar: (id: number, data: { estado: string; notas_admin?: string }) =>
    api.patch<Postulacion>(`/v1/postulaciones/${id}`, data).then((r) => r.data),

  eliminar: (id: number) =>
    api.delete(`/v1/postulaciones/${id}`),

  enviarPublica: (data: PostulacionPublicaPayload) =>
    api.post<Postulacion>('/v1/postulaciones', data).then((r) => r.data),

  tiendas: () =>
    api.get<{ id: number; nombre: string; codigo: string }[]>('/v1/postulaciones/tiendas').then((r) => r.data),
}
