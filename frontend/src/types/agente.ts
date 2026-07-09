export interface Agente {
  id: number
  dni: string
  nombres: string
  apellidos: string | null
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
  clasificacion_baja: 'LISTA_BLANCA' | 'LISTA_NEGRA' | null
  motivo_baja: string | null
  fecha_baja: string | null
  observacion: string | null
  creado_en: string
}

export interface HistorialAgenteEvento {
  id: number
  id_agente: number
  estado: string
  clasificacion_baja: string | null
  observacion: string | null
  fecha_registro: string
  tipo_cambio: string
  campo_anterior: string | null
  campo_nuevo: string | null
  registrado_por: number | null
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
  clasificacion_baja?: string
  motivo_baja?: string
  fecha_baja?: string
  observacion?: string
}

export interface AgenteParams {
  q?: string
  tienda?: string
  estado?: string
  page?: number
  per_page?: number
}

export interface EstadoSeguridad {
  dispositivo_vinculado: boolean
  fecha_registro_dispositivo: string | null
  tienda_registro_inicial: string | null
  tiene_token: boolean
  token: string | null
  tipo_token: 'diario' | 'permanente' | null
  expiracion_token: string | null
}
