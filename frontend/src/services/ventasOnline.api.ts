import { api } from './api'

export type EstadoVenta = 'pendiente' | 'exitoso' | 'fallido'
export type TipoVenta = 'delivery_chip' | 'plan_online'

export interface VentaOnline {
  id: number
  agente_ref: string
  tienda_codigo: string
  dni: string
  nombres: string
  telefono: string | null
  operador_origen: string
  tipo: TipoVenta
  plan_ofrecido: string | null
  notas: string | null
  estado: EstadoVenta
  motivo_falla: string | null
  crm_cliente_id: number | null
  origen: string
  created_at: string | null
  updated_at: string | null
}

export interface VentasOnlineKpis {
  total: number
  exitosos: number
  fallidos: number
  pendientes: number
  pct_exito: number
  incumplimientos: number
  top_motivos: { motivo: string; n: number }[]
}

export interface VentasOnlineResponse {
  success: boolean
  ventas: VentaOnline[]
  paginacion: { total: number; pagina: number; por_pagina: number; paginas: number }
  kpis: VentasOnlineKpis
}

export interface VentasOnlineFiltros {
  fecha_desde?: string
  fecha_hasta?: string
  tienda?: string
  agente?: string
  estado?: string
  operador?: string
  tipo?: string
  busqueda?: string
  page?: number
  per_page?: number
}

export async function listarVentasOnline(filtros: VentasOnlineFiltros): Promise<VentasOnlineResponse> {
  const params = Object.fromEntries(
    Object.entries(filtros).filter(([, v]) => v !== undefined && v !== '')
  )
  const { data } = await api.get<VentasOnlineResponse>('/v1/ventas-online', { params })
  return data
}
