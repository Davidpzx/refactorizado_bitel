export type EstadoTraslado =
  | 'PENDIENTE'
  | 'PENDIENTE_APROBACION'
  | 'CONFIRMADO'
  | 'RECHAZADO'
  | 'CANCELADO'

export interface Traslado {
  id: number
  producto_nombre_snap: string
  tienda_origen: string
  tienda_destino: string
  cantidad: number
  estado: EstadoTraslado
  notas: string | null
  observacion_confirmacion: string | null
  codigo_lote: string | null
  creado_por_id: number | null
  auth_agente_id: number | null
  created_at: string
  updated_at: string
}

export interface TrasladoFilters {
  estado?: string
  tienda_origen?: string
  tienda_destino?: string
  per_page?: number
  page?: number
}

export interface CrearTrasladoPayload {
  producto_id: number
  tienda_destino: string
  cantidad?: number
  notas?: string
  auth_dni?: string
}

export interface ConfirmarTrasladoPayload {
  observacion?: string
  auth_dni: string
}

export interface GestionarTrasladoPayload {
  action: 'aprobar' | 'rechazar' | 'cancelar'
  codigo_lote?: string
}

export interface TrasladoChip {
  id: number
  chip_id: number
  tienda_origen: string
  tienda_destino: string
  cantidad: number
  estado: EstadoTraslado
  notas: string | null
  observacion_confirmacion: string | null
  auth_agente_id: number | null
  created_at: string
  updated_at: string
}

export interface ChipStock {
  id: number
  tienda_origen: string
  tienda_id: string
  tipo_chip: string
  stock_actual: number
}

export interface TrasladoChipFilters {
  estado?: string
  tienda_origen?: string
  tienda_destino?: string
  per_page?: number
  page?: number
}

export interface CrearTrasladoChipPayload {
  chip_id: number
  tienda_origen: string
  tienda_destino: string
  cantidad: number
  notas?: string
  auth_dni?: string
}
