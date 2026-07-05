import { api } from './api'
import type { Agente, AgenteFormData, AgenteParams, EstadoSeguridad, HistorialAgenteEvento } from '../types/agente'
import type { PaginatedResponse } from '../types/pagination'

export type TipoTokenAccion = 'diario' | 'permanente' | 'revocar'

export const agentesApi = {
  list: (params?: AgenteParams) =>
    api.get<PaginatedResponse<Agente>>('/v1/agentes', { params }).then((r) => r.data),

  get: (id: number) =>
    api.get<Agente>(`/v1/agentes/${id}`).then((r) => r.data),

  create: (data: AgenteFormData) =>
    api.post<Agente>('/v1/agentes', data).then((r) => r.data),

  update: (id: number, data: Partial<AgenteFormData>) =>
    api.put<Agente>(`/v1/agentes/${id}`, data).then((r) => r.data),

  destroy: (id: number) =>
    api.delete(`/v1/agentes/${id}`),

  historial: (id: number) =>
    api.get<{ data: HistorialAgenteEvento[] }>(`/v1/agentes/${id}/historial`).then((r) => r.data.data),

  seguridad: (id: number) =>
    api.get<EstadoSeguridad>(`/v1/agentes/${id}/seguridad`).then((r) => r.data),

  tokenSeguridad: (id: number, tipo: TipoTokenAccion) =>
    api.post<{ success: boolean; token?: string; expiracion?: string; tipo?: string; accion?: string }>(
      `/v1/agentes/${id}/token-seguridad`,
      { tipo },
    ).then((r) => r.data),

  resetDispositivo: (id: number) =>
    api.post<{ message: string }>(`/v1/agentes/${id}/reset-dispositivo`).then((r) => r.data),
}
