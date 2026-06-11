import { api } from './api'

export interface BorradorResponse {
  success: boolean
  borrador: (Record<string, unknown> & {
    _cloud_ts?: number
    _cloud_agente?: number
    _mismo_usuario?: boolean
  }) | null
  borrador_id?: number
}

export const borradorApi = {
  cargar: () => api.get<BorradorResponse>('/v1/reportes/borrador').then((r) => r.data),
  guardar: (datos: Record<string, unknown>) =>
    api.post('/v1/reportes/borrador', datos).then((r) => r.data),
  eliminar: () => api.delete('/v1/reportes/borrador').then((r) => r.data),
}
