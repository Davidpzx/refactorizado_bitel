export interface Agente {
  id: number
  dni: string
  nombres: string
  tienda_base: string
  hora_ingreso: string | null
  hora_salida: string | null
  sueldo_base: string
  dia_pago: number | null
  estado: 'ACTIVO' | 'INACTIVO' | 'BAJA'
  dia_descanso: string | null
  fecha_ingreso: string
  correo: string | null
  telefono: string | null
  direccion: string | null
  es_gerencia: boolean
  permiso_largo: boolean
  fecha_retorno: string | null
  fecha_baja: string | null
  creado_en: string
}

export interface AgenteFormData {
  dni: string
  nombres: string
  tienda_base: string
  pin_seguridad?: string
  sueldo_base: number
  estado: 'ACTIVO' | 'INACTIVO' | 'BAJA'
  hora_ingreso?: string
  hora_salida?: string
  dia_pago?: number
  dia_descanso?: string
  fecha_ingreso: string
  correo?: string
  telefono?: string
  direccion?: string
  es_gerencia: boolean
  permiso_largo?: boolean
  fecha_retorno?: string
}

export interface AgenteParams {
  q?: string
  tienda?: string
  estado?: string
  page?: number
  per_page?: number
}
