import { api } from './api'

export interface AnomaliaCajaItem {
  agente_id: number
  cajero: string | null
  tienda: string | null
  dias_desc: number
  mayor_diferencia: number
}

export interface TrasladoPendienteItem {
  id: number
  codigo_lote: string | null
  tienda_origen: string | null
  tienda_destino: string | null
  cantidad: number
  fecha_creacion: string | null
  tipo_lote: 'equipos' | 'chips'
  detalle: string
}

export interface NotificacionItem {
  id: number
  tipo: string
  mensaje: string
  fecha_creacion: string | null
}

interface Indicator {
  count: number
}

export interface ControlCenterResponse {
  generated_at: string
  anomalias_caja: Indicator & { data: AnomaliaCajaItem[] }
  precios_pendientes: Indicator
  traslados_pendientes: Indicator & { equipos_count: number; chips_count: number; data: TrasladoPendienteItem[] }
  postulantes_pendientes: Indicator
  financieras_pendientes: Indicator
  alertas_bcp: Indicator & { data?: unknown[] }
  alertas_bipay: Indicator & { data?: unknown[] }
  notificaciones_sistema: Indicator & { data: NotificacionItem[] }
}

export type TrasladoAccion = 'aprobar' | 'rechazar' | 'cancelar'

export const controlCenterApi = {
  get: () => api.get<ControlCenterResponse>('/v1/control-center').then((r) => r.data),

  gestionarTraslado: (tipo: 'equipos' | 'chips', id: number, action: TrasladoAccion, codigoLote?: string | null) => {
    const path = tipo === 'chips' ? `/v1/traslados-chips/${id}/gestionar` : `/v1/traslados/${id}/gestionar`
    return api.post(path, { action, codigo_lote: codigoLote ?? '' }).then((r) => r.data)
  },

  marcarNotificacion: (id: number, accion: 'leido' | 'borrar' = 'leido') =>
    api.post('/v1/marcar-notificacion', { id, accion }).then((r) => r.data),
}
