import { api } from './api'

export interface DashboardTotales {
  total_general: number
  fisico_esperado: number
  fisico_declarado: number
  diferencia_fisica: number
  total_yape: number
  total_bipay: number
  total_transferencia: number
  total_reportes: number
}

export interface DashboardReporte {
  id: number
  fecha: string
  tienda_id: string
  agente_id: number
  agente_nombre: string
  total_calculado: string
  efectivo_esperado: string
  efectivo_entregado: string
  diferencia: string
  estado: string
  estado_edicion: 'CERRADO' | 'SOLICITADO' | 'APROBADO'
  destino_efectivo: string
  ganancia?: string | number | null
}

export interface DashboardKpisResponse {
  totales: DashboardTotales
  ganancia_total: number | null
  reportes: DashboardReporte[]
}

export interface AnomaliaItem {
  id: number
  fecha: string
  tienda_id: string
  agente_id: number
  diferencia: string
  estado: string
  efectivo_esperado: string
  efectivo_entregado: string
}

export interface DashboardAnomaliasResponse {
  count: number
  anomalias: AnomaliaItem[]
}

export type DashboardFilters = {
  fecha_desde?: string
  fecha_hasta?: string
  tienda?: string
}

export const dashboardApi = {
  kpis: (params?: DashboardFilters) =>
    api.get<DashboardKpisResponse>('/v1/dashboard/kpis', { params }).then((r) => r.data),

  anomalias: () =>
    api.get<DashboardAnomaliasResponse>('/v1/dashboard/anomalias').then((r) => r.data),
}
